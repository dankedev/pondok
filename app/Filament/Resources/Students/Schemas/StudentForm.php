<?php

namespace App\Filament\Resources\Students\Schemas;

use App\Filament\Resources\Students\StudentResource;
use App\Models\Student;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class StudentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('pondok.student.section_identity'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('nis')
                            ->label(__('pondok.student.nis'))
                            ->required()
                            ->maxLength(30)
                            ->unique(ignoreRecord: true),
                        TextInput::make('nisn')
                            ->label(__('pondok.student.nisn'))
                            ->maxLength(20)
                            ->unique(ignoreRecord: true),
                        TextInput::make('name')
                            ->label(__('pondok.common.name'))
                            ->required()
                            ->maxLength(255),
                        Select::make('gender')
                            ->label(__('pondok.common.gender'))
                            ->options(__('pondok.common.genders'))
                            ->required(),
                        TextInput::make('birth_place')
                            ->label(__('pondok.student.birth_place'))
                            ->required()
                            ->maxLength(100),
                        DatePicker::make('birth_date')
                            ->label(__('pondok.student.birth_date'))
                            ->required()
                            ->beforeOrEqual('today'),
                        Textarea::make('address')
                            ->label(__('pondok.common.address'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('pondok.student.section_academic'))
                    ->columns(2)
                    ->schema([
                        // Jenjang dan angkatan dikunci saat edit; perubahan lewat aksi "Pindah jenjang".
                        Select::make('unit_id')
                            ->label(__('pondok.common.unit'))
                            ->relationship('unit', 'name', fn (Builder $query) => $query->accessible()->orderBy('sort_order'))
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('cohort_id', null))
                            ->disabledOn('edit')
                            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? __('pondok.student.unit_locked_helper') : null),
                        Select::make('cohort_id')
                            ->label(__('pondok.student.cohort'))
                            ->options(fn (Get $get): array => StudentResource::cohortOptions($get('unit_id')))
                            ->required()
                            ->disabledOn('edit'),
                        Select::make('status')
                            ->label(__('pondok.student.status'))
                            ->options(__('pondok.student.statuses'))
                            ->default(Student::STATUS_ACTIVE)
                            ->required(),
                        DatePicker::make('entered_on')
                            ->label(__('pondok.student.entered_on'))
                            ->default(today())
                            ->required(),
                    ]),
            ]);
    }
}
