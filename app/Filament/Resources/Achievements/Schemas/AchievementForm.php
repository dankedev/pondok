<?php

namespace App\Filament\Resources\Achievements\Schemas;

use App\Models\Achievement;
use App\Models\Student;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Form prestasi. Peserta disimpan `SaveAchievement` (Repeater tidak terikat relasi).
 */
class AchievementForm
{
    public const STUDENT_SEARCH_LIMIT = 50;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('pondok.achievement.section_achievement'))
                    ->description(fn (?Achievement $record): ?string => $record?->is_verified ? __('pondok.achievement.edit_resets_verification') : null)
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label(__('pondok.achievement.title'))
                            ->placeholder(__('pondok.achievement.title_placeholder'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('event_name')
                            ->label(__('pondok.achievement.event_name'))
                            ->placeholder(__('pondok.achievement.event_name_placeholder'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('organizer')
                            ->label(__('pondok.achievement.organizer'))
                            ->maxLength(255),
                        Select::make('category')
                            ->label(__('pondok.achievement.category'))
                            ->options(__('pondok.achievement.categories'))
                            ->required(),
                        Select::make('level')
                            ->label(__('pondok.achievement.level'))
                            ->options(__('pondok.achievement.levels'))
                            ->required(),
                        Radio::make('kind')
                            ->label(__('pondok.achievement.kind'))
                            ->options(__('pondok.achievement.kinds'))
                            ->default(Achievement::KIND_INDIVIDUAL)
                            ->inline()
                            ->live()
                            ->required(),
                        DatePicker::make('achieved_on')
                            ->label(__('pondok.achievement.achieved_on'))
                            ->helperText(__('pondok.achievement.achieved_on_helper'))
                            ->maxDate(fn () => today())
                            ->required(),
                        Toggle::make('is_public')
                            ->label(__('pondok.achievement.is_public'))
                            ->helperText(__('pondok.achievement.is_public_helper'))
                            ->default(false)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('pondok.achievement.section_participants'))
                    ->description(__('pondok.achievement.section_participants_description'))
                    ->schema([
                        Repeater::make('participants')
                            ->hiddenLabel()
                            ->label(__('pondok.achievement.participants'))
                            ->defaultItems(1)
                            ->minItems(1)
                            ->maxItems(Achievement::MAX_PARTICIPANTS)
                            ->addActionLabel(__('pondok.achievement.add_participant'))
                            ->reorderable(false)
                            ->columns(3)
                            ->schema([
                                Select::make('student_id')
                                    ->label(__('pondok.achievement.participant_student'))
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search): array => static::searchStudents($search))
                                    ->getOptionLabelUsing(fn (mixed $value): ?string => static::studentLabel($value))
                                    ->required(),
                                TextInput::make('rank')
                                    ->label(__('pondok.achievement.participant_rank'))
                                    ->datalist(__('pondok.achievement.rank_suggestions'))
                                    ->maxLength(Achievement::RANK_MAX_LENGTH)
                                    ->required(),
                                TextInput::make('role')
                                    ->label(__('pondok.achievement.participant_role'))
                                    ->placeholder(__('pondok.achievement.participant_role_placeholder'))
                                    ->maxLength(Achievement::ROLE_MAX_LENGTH)
                                    ->visible(fn (Get $get): bool => $get('../../kind') === Achievement::KIND_TEAM),
                            ]),
                    ]),
            ]);
    }

    /**
     * Santri aktif (tidak terarsip) dalam cakupan jenjang user, dicari dari nama atau NIS.
     *
     * @return array<int, string>
     */
    public static function searchStudents(string $search): array
    {
        $like = '%'.trim($search).'%';

        return Student::query()
            ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('nis', 'like', $like))
            ->orderBy('name')
            ->limit(self::STUDENT_SEARCH_LIMIT)
            ->get(['id', 'nis', 'name'])
            ->mapWithKeys(fn (Student $student): array => [$student->id => static::formatStudent($student)])
            ->all();
    }

    /**
     * Label santri terpilih; santri terarsip (peserta lama) diberi keterangan.
     */
    public static function studentLabel(mixed $value): ?string
    {
        $student = is_numeric($value) ? Student::query()->withTrashed()->find((int) $value) : null;

        return $student === null ? null : static::formatStudent($student);
    }

    private static function formatStudent(Student $student): string
    {
        $label = "{$student->nis} — {$student->name}";

        return $student->trashed() ? $label.' '.__('pondok.achievement.archived_suffix') : $label;
    }
}
