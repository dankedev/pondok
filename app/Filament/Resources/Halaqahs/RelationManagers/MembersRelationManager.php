<?php

namespace App\Filament\Resources\Halaqahs\RelationManagers;

use App\Actions\Tahfidz\AddHalaqahMember;
use App\Actions\Tahfidz\EndHalaqahMembership;
use App\Actions\Tahfidz\ResolveTahfidzTarget;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Models\AcademicYear;
use App\Models\Halaqah;
use App\Models\HalaqahMember;
use App\Models\Student;
use App\Models\StudentJuzProgress;
use App\Models\TahfidzTarget;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Anggota halaqah beserta capaian juz tahun ajaran halaqah dibanding target. Tambah dan
 * keluarkan hanya bagi pemegang `update:halaqah` lewat Action. Target dimuat sekali per render
 * dan dipilih di PHP sehingga jumlah query tidak bergantung pada jumlah anggota.
 */
class MembersRelationManager extends RelationManager
{
    use ShowsActionErrorsOnForm;

    protected static string $relationship = 'members';

    /** @var Collection<int, TahfidzTarget>|null */
    private ?Collection $targets = null;

    private ?bool $manages = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('pondok.tahfidz.member.plural');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        $year = AcademicYear::query()->findOrFail($this->halaqah()->academic_year_id);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['student.currentEnrollment.classroom'])
                ->addSelect(['memorized_count' => StudentJuzProgress::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('student_juz_progress.student_id', 'halaqah_members.student_id')
                    ->whereIn('status', StudentJuzProgress::MEMORIZED)
                    ->whereDate('completed_on', '>=', $year->starts_on->toDateString())
                    ->whereDate('completed_on', '<=', $year->ends_on->toDateString()),
                ]))
            ->defaultSort(fn (Builder $query) => $query->orderByRaw('CASE WHEN left_on IS NULL THEN 0 ELSE 1 END')->orderByDesc('joined_on'))
            ->columns([
                TextColumn::make('student.name')
                    ->label(__('pondok.tahfidz.member.student'))
                    ->searchable(),
                TextColumn::make('joined_on')
                    ->label(__('pondok.tahfidz.member.joined_on'))
                    ->date('j M Y'),
                TextColumn::make('left_on')
                    ->label(__('pondok.tahfidz.member.left_on'))
                    ->date('j M Y')
                    ->placeholder('-'),
                TextColumn::make('memorized_count')
                    ->label(__('pondok.tahfidz.member.memorized_this_year'))
                    ->formatStateUsing(fn (mixed $state): string => __('pondok.tahfidz.juz_count', ['count' => (int) $state])),
                TextColumn::make('target')
                    ->label(__('pondok.tahfidz.member.target'))
                    ->state(fn (HalaqahMember $record): ?string => $this->targetFor($record)?->target_juz)
                    ->formatStateUsing(fn (string $state): string => __('pondok.tahfidz.juz_count', ['count' => self::formatJuz($state)]))
                    ->placeholder('-'),
                TextColumn::make('target_status')
                    ->label('')
                    ->state(fn (HalaqahMember $record): ?string => $this->isBelowTarget($record) ? __('pondok.tahfidz.member.below_target') : null)
                    ->badge()
                    ->color('warning'),
            ])
            ->emptyStateHeading(__('pondok.tahfidz.member.empty'))
            ->emptyStateIcon(Heroicon::OutlinedUserGroup)
            ->headerActions([
                $this->addAction(),
            ])
            ->recordActions([
                $this->endAction(),
            ]);
    }

    private function addAction(): Action
    {
        return Action::make('add')
            ->label(__('pondok.tahfidz.member.add'))
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn (): bool => $this->manages())
            ->schema([
                Select::make('student_id')
                    ->label(__('pondok.tahfidz.member.student'))
                    ->helperText(__('pondok.tahfidz.member.add_helper'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => $this->candidates()
                        ->where(fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhere('nis', 'like', "%{$search}%"))
                        ->orderBy('name')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Student $student): array => [$student->id => "{$student->nis} - {$student->name}"])
                        ->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => ($student = $this->candidates()->find($value)) ? "{$student->nis} - {$student->name}" : null)
                    ->required(),
                DatePicker::make('joined_on')
                    ->label(__('pondok.tahfidz.member.joined_on'))
                    ->default(fn (): string => today()->toDateString())
                    ->maxDate(today())
                    ->required(),
            ])
            ->action(function (array $data, AddHalaqahMember $add, Action $action): void {
                $student = $this->candidates()->findOrFail($data['student_id']);

                static::runShowingFormErrors($action, fn () => $add->handle(auth()->user(), $this->halaqah(), $student, Carbon::parse($data['joined_on'])));

                Notification::make()
                    ->title(__('pondok.tahfidz.member.added', ['name' => $student->name, 'halaqah' => $this->halaqah()->name]))
                    ->success()
                    ->send();
            });
    }

    private function endAction(): Action
    {
        return Action::make('end')
            ->label(__('pondok.tahfidz.member.end'))
            ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('pondok.tahfidz.member.end_description'))
            ->visible(fn (HalaqahMember $record): bool => $record->left_on === null && $this->manages())
            ->schema([
                DatePicker::make('left_on')
                    ->label(__('pondok.tahfidz.member.left_on'))
                    ->default(fn (): string => today()->toDateString())
                    ->maxDate(today())
                    ->required(),
            ])
            ->action(function (HalaqahMember $record, array $data, EndHalaqahMembership $end, Action $action): void {
                static::runShowingFormErrors($action, fn () => $end->handle(auth()->user(), $record, Carbon::parse($data['left_on'])));

                Notification::make()
                    ->title(__('pondok.tahfidz.member.ended', ['name' => $record->student->name]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Santri aktif yang cocok dengan gender dan jenjang halaqah, tersaring cakupan jenjang.
     */
    private function candidates(): Builder
    {
        $halaqah = $this->halaqah();

        return Student::query()
            ->active()
            ->where('gender', $halaqah->gender)
            ->when($halaqah->unit_id, fn (Builder $query, int $unitId) => $query->where('unit_id', $unitId));
    }

    private function manages(): bool
    {
        return $this->manages ??= auth()->user()->can('update', $this->halaqah());
    }

    private function targetFor(HalaqahMember $member): ?TahfidzTarget
    {
        $this->targets ??= TahfidzTarget::withoutUnitScope()->where('academic_year_id', $this->halaqah()->academic_year_id)->get();

        return ResolveTahfidzTarget::pick($this->targets, $member->student?->unit_id, $member->student?->currentEnrollment?->classroom?->grade);
    }

    private function isBelowTarget(HalaqahMember $member): bool
    {
        $target = $this->targetFor($member);

        return $target !== null && (float) $member->memorized_count < (float) $target->target_juz;
    }

    private static function formatJuz(string $value): string
    {
        return str_replace('.', ',', rtrim(rtrim($value, '0'), '.'));
    }

    private function halaqah(): Halaqah
    {
        /** @var Halaqah */
        return $this->getOwnerRecord();
    }
}
