<?php

namespace App\Filament\Resources\Guardians;

use App\Actions\PortalWali\CreateGuardianPortalAccount;
use App\Enums\Module;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\Documents\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Guardians\Pages\CreateGuardian;
use App\Filament\Resources\Guardians\Pages\EditGuardian;
use App\Filament\Resources\Guardians\Pages\ListGuardians;
use App\Filament\Resources\Guardians\RelationManagers\StudentsRelationManager;
use App\Filament\Resources\Guardians\Schemas\GuardianForm;
use App\Filament\Resources\Guardians\Tables\GuardiansTable;
use App\Models\Guardian;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GuardianResource extends Resource
{
    use BelongsToModule, ShowsActionErrorsOnForm;

    protected static ?string $model = Guardian::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 4;

    public static function getModule(): string
    {
        return 'akademik';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.guardian.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.guardian.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.akademik');
    }

    public static function form(Schema $schema): Schema
    {
        return GuardianForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GuardiansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            StudentsRelationManager::class,
            DocumentsRelationManager::class,
        ];
    }

    /**
     * "Buat akun portal" (F022): modul Portal wali aktif, wali belum punya akun, dan user boleh mengubah wali.
     */
    public static function createPortalAccountAction(): Action
    {
        return Action::make('createPortalAccount')
            ->label(__('pondok.portal_wali.create_account'))
            ->icon(Heroicon::OutlinedKey)
            ->visible(fn (Guardian $record): bool => Module::PortalWali->isEnabled()
                && $record->user_id === null
                && static::canEdit($record))
            ->modalHeading(fn (Guardian $record): string => __('pondok.portal_wali.create_account_confirm_heading', ['name' => $record->name]))
            ->modalDescription(__('pondok.portal_wali.create_account_confirm'))
            ->modalSubmitActionLabel(__('pondok.portal_wali.create_account_submit'))
            ->schema([
                TextInput::make('email')
                    ->label(__('pondok.portal_wali.create_account_email'))
                    ->helperText(__('pondok.portal_wali.create_account_email_helper'))
                    ->email()
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (Guardian $record, array $data, CreateGuardianPortalAccount $create, Action $action): void {
                $user = static::runShowingFormErrors($action, fn () => $create->handle($record, (string) $data['email'], auth()->user()));

                Notification::make()
                    ->title(__('pondok.portal_wali.account_created', ['email' => $user->email]))
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGuardians::route('/'),
            'create' => CreateGuardian::route('/create'),
            'edit' => EditGuardian::route('/{record}/edit'),
        ];
    }
}
