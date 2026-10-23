<?php

namespace App\Filament\Resources\LeaveTypes;

use App\Actions\Perizinan\SaveLeaveType;
use App\Enums\LeaveApprover;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\LeaveTypes\Pages\ManageLeaveTypes;
use App\Models\LeaveType;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Jenis izin (F024): durasi maksimal, penyetuju, dan kewajiban dijemput wali. Simpan lewat `SaveLeaveType`.
 * Tidak dihapus karena dirujuk izin; cukup dinonaktifkan.
 */
class LeaveTypeResource extends Resource
{
    use BelongsToModule;
    use ShowsActionErrorsOnForm;

    protected static ?string $model = LeaveType::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?int $navigationSort = 3;

    public static function getModule(): string
    {
        return 'perizinan';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.leave_type.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.leave_type.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.perizinan');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')
                    ->label(__('pondok.leave_type.fields.code'))
                    ->helperText(__('pondok.leave_type.code_helper'))
                    ->maxLength(20)
                    ->required(),
                TextInput::make('name')
                    ->label(__('pondok.leave_type.fields.name'))
                    ->maxLength(255)
                    ->required(),
                TextInput::make('max_hours')
                    ->label(__('pondok.leave_type.fields.max_hours'))
                    ->helperText(__('pondok.leave_type.max_hours_helper'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(65535),
                Select::make('approver')
                    ->label(__('pondok.leave_type.fields.approver'))
                    ->options(collect(LeaveApprover::cases())->mapWithKeys(fn (LeaveApprover $approver): array => [$approver->value => $approver->label()])->all())
                    ->required(),
                Toggle::make('requires_guardian')
                    ->label(__('pondok.leave_type.fields.requires_guardian'))
                    ->default(false),
                Toggle::make('is_active')
                    ->label(__('pondok.leave_type.fields.is_active'))
                    ->helperText(__('pondok.leave_type.is_active_helper'))
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->label(__('pondok.leave_type.fields.code'))
                    ->searchable(),
                TextColumn::make('name')
                    ->label(__('pondok.leave_type.fields.name'))
                    ->searchable(),
                TextColumn::make('max_hours')
                    ->label(__('pondok.leave_type.fields.max_hours'))
                    ->placeholder(__('pondok.leave_type.no_limit'))
                    ->alignEnd(),
                TextColumn::make('approver')
                    ->label(__('pondok.leave_type.fields.approver'))
                    ->formatStateUsing(fn (LeaveApprover $state): string => $state->label()),
                IconColumn::make('requires_guardian')
                    ->label(__('pondok.leave_type.fields.requires_guardian'))
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label(__('pondok.leave_type.fields.is_active'))
                    ->boolean(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedListBullet)
            ->emptyStateHeading(__('pondok.leave_type.empty'))
            ->recordActions([
                static::editAction(),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->label(__('pondok.leave_type.create_action'))
            ->using(fn (array $data, CreateAction $action, SaveLeaveType $save): LeaveType => static::saveShowingFormErrors(
                $action,
                fn (): LeaveType => $save->handle($data, static::user()),
            ));
    }

    public static function editAction(): EditAction
    {
        return EditAction::make()
            ->using(fn (LeaveType $record, array $data, EditAction $action, SaveLeaveType $save): LeaveType => static::saveShowingFormErrors(
                $action,
                fn (): LeaveType => $save->handle($data, static::user(), $record),
            ));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLeaveTypes::route('/'),
        ];
    }

    public static function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
