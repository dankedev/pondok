<?php

namespace App\Filament\Resources\Guardians\RelationManagers;

use App\Actions\Akademik\AttachGuardian;
use App\Filament\Resources\Guardians\GuardianResource;
use App\Filament\Resources\Students\StudentResource;
use App\Models\Guardian;
use App\Models\Student;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Santri yang diwalikan, misalnya kakak-adik dengan wali yang sama.
 */
class StudentsRelationManager extends RelationManager
{
    protected static string $relationship = 'students';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('pondok.student.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('nis')
                    ->label(__('pondok.student.nis')),
                TextColumn::make('name')
                    ->label(__('pondok.common.name'))
                    ->url(fn (Student $record): ?string => StudentResource::canEdit($record) ? StudentResource::getUrl('edit', ['record' => $record]) : null),
                TextColumn::make('unit.name')
                    ->label(__('pondok.common.unit')),
                TextColumn::make('relation')
                    ->label(__('pondok.guardian.relation'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.guardian.relations.{$state}")),
                IconColumn::make('is_billing_contact')
                    ->label(__('pondok.guardian.is_billing_contact'))
                    ->boolean(),
            ])
            ->headerActions([
                Action::make('attachStudent')
                    ->label(__('pondok.guardian.attach_student'))
                    ->icon(Heroicon::OutlinedLink)
                    ->authorize(fn (): bool => GuardianResource::canEdit($this->getOwnerRecord()) && StudentResource::canViewAny())
                    ->schema([
                        Select::make('student_id')
                            ->label(__('pondok.student.label'))
                            ->getSearchResultsUsing(fn (string $search): array => Student::query()
                                ->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('nis', 'like', "%{$search}%"))
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Student $student): array => [$student->id => "{$student->nis} - {$student->name}"])
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => Student::query()->find($value)?->name)
                            ->searchable()
                            ->required(),
                        Select::make('relation')
                            ->label(__('pondok.guardian.relation'))
                            ->options(__('pondok.guardian.relations'))
                            ->required(),
                        Toggle::make('is_billing_contact')
                            ->label(__('pondok.guardian.is_billing_contact'))
                            ->helperText(__('pondok.guardian.is_billing_contact_helper'))
                            ->default(false),
                    ])
                    ->action(function (array $data, AttachGuardian $attach): void {
                        /** @var Guardian $guardian */
                        $guardian = $this->getOwnerRecord();

                        $attach->handle(
                            Student::query()->findOrFail($data['student_id']),
                            $guardian,
                            $data['relation'],
                            (bool) $data['is_billing_contact'],
                        );
                    }),
            ]);
    }
}
