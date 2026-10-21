<?php

namespace App\Filament\Pages\Settings;

use App\Actions\Core\SetModuleEnabled;
use App\Enums\Module;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * Pengaturan → Modul (F066): super-admin menyalakan/mematikan modul fitur lewat
 * `SetModuleEnabled`. Satu Section per modul (urut enum) dengan aksi `toggle-<key>`
 * di header, lalu daftar modul inti yang selalu aktif.
 */
class Modules extends Page
{
    protected string $view = 'filament.pages.settings-form';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'settings/modules';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.pengaturan');
    }

    public static function getNavigationLabel(): string
    {
        return __('pondok.module_settings.title');
    }

    public function getTitle(): string
    {
        return __('pondok.module_settings.title');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('super-admin');
    }

    /**
     * Item navigasi disimpan di instance panel; cek ulang akses saat dirender.
     *
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        return static::canAccess() ? parent::getNavigationItems() : [];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * Aksi saklar didaftarkan ke komponen agar bisa dipanggil dengan namanya
     * (`toggle-<key>`), lalu dipasang di header Section modulnya.
     */
    public function cacheTraitActions(): void
    {
        parent::cacheTraitActions();

        foreach (Module::cases() as $module) {
            $this->cacheAction($this->toggleAction($module));
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            ...array_map(fn (Module $module): Section => $this->moduleSection($module), Module::cases()),
            Section::make(__('pondok.module_settings.core_heading'))
                ->description(__('pondok.module_settings.core_description'))
                ->schema([
                    UnorderedList::make(array_map(
                        fn (string $item): Text => Text::make($item),
                        __('pondok.module_settings.core'),
                    )),
                ]),
        ]);
    }

    /**
     * Isi dihitung saat dirender agar status langsung berubah setelah aksi.
     */
    private function moduleSection(Module $module): Section
    {
        return Section::make($module->label())
            ->key("module-{$module->value}")
            ->description($module->description())
            ->afterHeader(fn (): array => [
                Text::make(__('pondok.module_settings.status.'.($module->isEnabled() ? 'enabled' : 'disabled')))
                    ->badge()
                    ->color($module->isEnabled() ? 'success' : 'gray'),
                $this->cachedActions["toggle-{$module->value}"],
            ])
            ->schema(fn (): array => array_values(array_filter([
                $this->requirementWarning($module),
                Text::make($module->requires() === []
                    ? __('pondok.module_settings.requires_none')
                    : __('pondok.module_settings.requires', ['modules' => Module::labels($module->requires())])),
                $module->dependents() === [] ? null : Text::make(__('pondok.module_settings.required_by', ['modules' => Module::labels($module->dependents())])),
            ])));
    }

    /**
     * Data lama bisa melanggar graf (modul aktif, dependensinya mati); tampilkan apa adanya.
     */
    private function requirementWarning(Module $module): ?Callout
    {
        $missing = $module->isEnabled()
            ? array_filter($module->requires(), fn (Module $required): bool => ! $required->isEnabled())
            : [];

        return $missing === []
            ? null
            : Callout::make(__('pondok.module_settings.requirement_off', ['modules' => Module::labels($missing)]))->warning();
    }

    private function toggleAction(Module $module): Action
    {
        $state = fn (): string => $module->isEnabled() ? 'disable' : 'enable';

        return Action::make("toggle-{$module->value}")
            ->label(fn (): string => __("pondok.module_settings.{$state()}"))
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(fn (): string => __("pondok.module_settings.confirm_{$state()}_heading", ['module' => $module->label()]))
            ->modalDescription(fn (): string => __("pondok.module_settings.confirm_{$state()}", ['module' => $module->label()]))
            ->modalSubmitActionLabel(fn (): string => __("pondok.module_settings.{$state()}"))
            ->action(function () use ($module): void {
                abort_unless(static::canAccess(), 403);

                /** @var User $user */
                $user = auth()->user();
                $enable = ! $module->isEnabled();

                try {
                    app(SetModuleEnabled::class)->handle($user, $module, $enable);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(collect($exception->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('pondok.module_settings.'.($enable ? 'enabled' : 'disabled'), ['module' => $module->label()]))
                    ->success()
                    ->send();
            });
    }
}
