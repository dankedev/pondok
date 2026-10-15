<?php

namespace App\Filament\Resources\Students\RelationManagers;

use App\Actions\Akademik\EnrollStudent;
use App\Filament\Resources\Students\StudentResource;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Student;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Riwayat kelas santri. Hanya bisa ditambah lewat Action `EnrollStudent`
 * sehingga validasi jenjang, gender, dan kapasitas selalu berlaku.
 */
class EnrollmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'enrollments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('pondok.enrollment.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['academicYear', 'classroom']))
            ->defaultSort(fn (Builder $query) => $query->orderByDesc(
                AcademicYear::query()->select('starts_on')->whereColumn('academic_years.id', 'enrollments.academic_year_id'),
            ))
            ->columns([
                TextColumn::make('academicYear.name')
                    ->label(__('pondok.common.academic_year')),
                TextColumn::make('classroom.grade')
                    ->label(__('pondok.classroom.grade')),
                TextColumn::make('classroom.name')
                    ->label(__('pondok.enrollment.classroom')),
            ])
            ->headerActions([
                Action::make('enroll')
                    ->label(__('pondok.student.enroll'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->authorize(fn (): bool => StudentResource::canEdit($this->getOwnerRecord()))
                    ->schema([
                        Select::make('academic_year_id')
                            ->label(__('pondok.common.academic_year'))
                            ->options(fn (): array => AcademicYear::query()->orderByDesc('starts_on')->pluck('name', 'id')->all())
                            ->default(fn (): ?int => AcademicYear::current()?->id)
                            ->required()
                            ->live(),
                        Select::make('classroom_id')
                            ->label(__('pondok.enrollment.classroom'))
                            ->options(fn (Get $get): array => Classroom::query()
                                ->where('academic_year_id', $get('academic_year_id'))
                                ->where('unit_id', $this->getOwnerRecord()->unit_id)
                                ->orderBy('grade')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->required(),
                    ])
                    ->action(function (array $data, EnrollStudent $enroll, Action $action): void {
                        /** @var Student $student */
                        $student = $this->getOwnerRecord();
                        $classroom = Classroom::query()->findOrFail($data['classroom_id']);

                        try {
                            $enroll->handle($student, $classroom);
                        } catch (ValidationException $exception) {
                            Notification::make()->title($exception->validator->errors()->first())->danger()->send();
                            $action->halt();

                            return;
                        }

                        Notification::make()
                            ->title(__('pondok.student.enrolled', ['name' => $student->name, 'classroom' => $classroom->name]))
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
