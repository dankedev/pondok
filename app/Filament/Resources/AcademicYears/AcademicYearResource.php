<?php

namespace App\Filament\Resources\AcademicYears;

use App\Actions\Core\ActivateAcademicYear;
use App\Filament\Resources\AcademicYears\Pages\ManageAcademicYears;
use App\Models\AcademicYear;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AcademicYearResource extends Resource
{
    protected static ?string $model = AcademicYear::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('pondok.academic_year.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.academic_year.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.master');
    }

    public static function form(Schema $schema): Schema
    {
        // Status aktif tidak diubah dari form, melainkan lewat aksi "Jadikan aktif"
        // agar hanya ada satu tahun ajaran aktif.
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('pondok.academic_year.name'))
                    ->helperText(__('pondok.academic_year.name_helper'))
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true),
                DatePicker::make('starts_on')
                    ->label(__('pondok.academic_year.starts_on'))
                    ->required(),
                DatePicker::make('ends_on')
                    ->label(__('pondok.academic_year.ends_on'))
                    ->required()
                    ->after('starts_on')
                    ->validationMessages(['after' => __('pondok.academic_year.ends_after_start')]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_on', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('pondok.academic_year.name'))
                    ->searchable(),
                TextColumn::make('starts_on')
                    ->label(__('pondok.academic_year.starts_on'))
                    ->date()
                    ->sortable(),
                TextColumn::make('ends_on')
                    ->label(__('pondok.academic_year.ends_on'))
                    ->date()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('pondok.academic_year.is_active'))
                    ->boolean(),
            ])
            ->recordActions([
                Action::make('activate')
                    ->label(__('pondok.academic_year.activate'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->requiresConfirmation()
                    ->visible(fn (AcademicYear $record): bool => ! $record->is_active)
                    ->authorize(fn (AcademicYear $record): bool => auth()->user()->can('update', $record))
                    ->action(function (AcademicYear $record, ActivateAcademicYear $activate): void {
                        $activate->handle($record);

                        Notification::make()
                            ->title(__('pondok.academic_year.activated', ['name' => $record->name]))
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (AcademicYear $record): bool => $record->is_active),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAcademicYears::route('/'),
        ];
    }
}
