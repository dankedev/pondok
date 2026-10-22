<?php

namespace App\Filament\Resources\TahfidzTargets;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\TahfidzTargets\Pages\ManageTahfidzTargets;
use App\Models\AcademicYear;
use App\Models\QuranSurah;
use App\Models\TahfidzTarget;
use App\Models\Unit;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * Target hafalan per tahun ajaran, jenjang, dan tingkat (F023). Unik null-aware lewat `grade_key`.
 */
class TahfidzTargetResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = TahfidzTarget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?int $navigationSort = 4;

    public static function getModule(): string
    {
        return 'tahfidz';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.tahfidz.target.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.tahfidz.target.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.tahfidz');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('academic_year_id')
                    ->label(__('pondok.common.academic_year'))
                    ->options(fn (): array => AcademicYear::query()->orderByDesc('starts_on')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => AcademicYear::current()?->id)
                    ->required(),
                Select::make('unit_id')
                    ->label(__('pondok.common.unit'))
                    ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->required()
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                        ->where('academic_year_id', $get('academic_year_id'))
                        ->where('grade_key', (int) ($get('grade') ?? 0)))
                    ->validationMessages(['unique' => __('pondok.tahfidz.target.unique')]),
                TextInput::make('grade')
                    ->label(__('pondok.tahfidz.target.grade'))
                    ->helperText(__('pondok.tahfidz.target.grade_helper'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(12),
                TextInput::make('target_juz')
                    ->label(__('pondok.tahfidz.target.target_juz'))
                    ->numeric()
                    ->step(0.5)
                    ->minValue(0.5)
                    ->maxValue(QuranSurah::LAST_JUZ)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['academicYear', 'unit']))
            ->defaultSort(fn ($query) => $query->orderBy('unit_id')->orderBy('grade_key'))
            ->columns([
                TextColumn::make('academicYear.name')
                    ->label(__('pondok.common.academic_year')),
                TextColumn::make('unit.name')
                    ->label(__('pondok.common.unit')),
                TextColumn::make('grade')
                    ->label(__('pondok.tahfidz.target.grade'))
                    ->placeholder(__('pondok.tahfidz.target.all_grades')),
                TextColumn::make('target_juz')
                    ->label(__('pondok.tahfidz.target.target_juz'))
                    ->formatStateUsing(fn (string $state): string => str_replace('.', ',', $state)),
            ])
            ->filters([
                SelectFilter::make('academic_year_id')
                    ->label(__('pondok.common.academic_year'))
                    ->options(fn (): array => AcademicYear::query()->orderByDesc('starts_on')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => AcademicYear::current()?->id),
            ])
            ->emptyStateHeading(__('pondok.tahfidz.target.empty'))
            ->emptyStateIcon(Heroicon::OutlinedFlag)
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTahfidzTargets::route('/'),
        ];
    }
}
