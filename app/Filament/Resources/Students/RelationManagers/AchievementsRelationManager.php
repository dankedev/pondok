<?php

namespace App\Filament\Resources\Students\RelationManagers;

use App\Actions\Prestasi\SummarizeStudentAchievements;
use App\Filament\Resources\Achievements\Pages\CreateAchievement;
use App\Models\AcademicYear;
use App\Models\Achievement;
use App\Models\Setting;
use App\Models\Student;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Tab prestasi di halaman santri (F015), hanya baca. Menampilkan semua
 * prestasi santri dengan badge verifikasi; ringkasan di deskripsi memakai
 * angka resmi (terverifikasi saja). Prestasi dikelola di `AchievementResource`.
 */
class AchievementsRelationManager extends RelationManager
{
    protected static string $relationship = 'achievements';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('pondok.achievement_student_tab.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Setting::moduleEnabled('prestasi') && Gate::allows('viewAny', Achievement::class);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('achievements.achieved_on')->orderByDesc('achievements.id'))
            ->description(fn (): string => $this->summaryDescription())
            ->columns([
                TextColumn::make('achieved_on')
                    ->label(__('pondok.achievement.achieved_on'))
                    ->date('j M Y'),
                TextColumn::make('title')
                    ->label(__('pondok.achievement.title'))
                    ->description(fn (Achievement $record): string => $record->event_name)
                    ->wrap(),
                TextColumn::make('level')
                    ->label(__('pondok.achievement.level'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.achievement.levels.{$state}")),
                TextColumn::make('category')
                    ->label(__('pondok.achievement.category'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.achievement.categories.{$state}")),
                TextColumn::make('participant_rank')
                    ->label(__('pondok.achievement.participant_rank'))
                    ->state(fn (Achievement $record): ?string => $record->pivot?->getAttribute('rank')),
                TextColumn::make('participant_role')
                    ->label(__('pondok.achievement.participant_role'))
                    ->state(fn (Achievement $record): ?string => $record->pivot?->getAttribute('role'))
                    ->placeholder('-'),
                TextColumn::make('verification_status')
                    ->label(__('pondok.achievement.verification_status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("pondok.achievement.verification_statuses.{$state}"))
                    ->color(fn (string $state): string => Achievement::VERIFICATION_COLORS[$state] ?? 'gray'),
            ])
            ->filters([
                SelectFilter::make('academic_year_id')
                    ->label(__('pondok.achievement.academic_year'))
                    ->options(fn (): array => AcademicYear::query()->orderByDesc('starts_on')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query : $query->where('achievements.academic_year_id', $data['value'])),
                TernaryFilter::make('verified')
                    ->label(__('pondok.achievement.verification_filter'))
                    ->trueLabel(__('pondok.achievement.verification_statuses.terverifikasi'))
                    ->falseLabel(__('pondok.achievement.verification_statuses.belum'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('achievements.verified_by'),
                        false: fn (Builder $query): Builder => $query->whereNull('achievements.verified_by'),
                    ),
            ])
            ->emptyStateIcon(Heroicon::OutlinedTrophy)
            ->emptyStateHeading(__('pondok.achievement_student_tab.empty'))
            ->headerActions([
                Action::make('createAchievement')
                    ->label(__('pondok.achievement.create_action'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->authorize(fn (): bool => Setting::moduleEnabled('prestasi') && Gate::allows('create', Achievement::class))
                    ->url(fn (): string => CreateAchievement::getUrl(['student' => $this->getOwnerRecord()->getKey()])),
            ]);
    }

    /**
     * Angka resmi (terverifikasi) dan jumlah yang belum diverifikasi.
     */
    private function summaryDescription(): string
    {
        /** @var Student $student */
        $student = $this->getOwnerRecord();
        $summary = app(SummarizeStudentAchievements::class)->handle($student);

        $text = $summary['total'] > 0
            ? __('pondok.achievement_student_tab.summary', [
                'total' => $summary['total'],
                'level' => __("pondok.achievement.levels.{$summary['highest_level']}"),
            ])
            : __('pondok.achievement_student_tab.empty');

        if ($summary['unverified'] > 0) {
            $text .= ' '.__('pondok.achievement_student_tab.unverified_note', ['count' => $summary['unverified']]);
        }

        return $text;
    }
}
