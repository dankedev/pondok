<?php

namespace App\Filament\Resources\Halaqahs\Schemas;

use App\Models\AcademicYear;
use App\Models\Staff;
use App\Models\Unit;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class HalaqahForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('pondok.tahfidz.halaqah.section_identity'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label(__('pondok.tahfidz.halaqah.name'))
                            ->helperText(__('pondok.tahfidz.halaqah.name_helper'))
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('academic_year_id', $get('academic_year_id')))
                            ->validationMessages(['unique' => __('pondok.tahfidz.halaqah.unique')]),
                        Select::make('academic_year_id')
                            ->label(__('pondok.common.academic_year'))
                            ->options(fn (): array => AcademicYear::query()->orderByDesc('starts_on')->pluck('name', 'id')->all())
                            ->default(fn (): ?int => AcademicYear::current()?->id)
                            ->required(),
                        Select::make('unit_id')
                            ->label(__('pondok.common.unit'))
                            ->helperText(__('pondok.tahfidz.halaqah.unit_helper'))
                            ->placeholder(__('pondok.tahfidz.cross_unit'))
                            ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all()),
                        Select::make('teacher_staff_id')
                            ->label(__('pondok.tahfidz.halaqah.teacher'))
                            ->helperText(__('pondok.tahfidz.halaqah.teacher_helper'))
                            ->options(fn (): array => Staff::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        Select::make('gender')
                            ->label(__('pondok.tahfidz.halaqah.gender'))
                            ->options(__('pondok.tahfidz.genders'))
                            ->required(),
                        TextInput::make('schedule')
                            ->label(__('pondok.tahfidz.halaqah.schedule'))
                            ->helperText(__('pondok.tahfidz.halaqah.schedule_helper'))
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label(__('pondok.common.is_active'))
                            ->default(true),
                    ]),
            ]);
    }
}
