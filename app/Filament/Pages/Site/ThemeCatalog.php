<?php

namespace App\Filament\Pages\Site;

use App\Actions\Konten\FetchThemeCatalog;
use App\Actions\Konten\InstallThemeFromCatalog;
use App\Actions\Konten\ListPortalThemes;
use App\Actions\Konten\MigrateThemeSchema;
use App\Actions\Konten\RemovePortalTheme;
use App\Actions\Konten\RollbackPortalTheme;
use App\Actions\Konten\ValidatePortalTheme;
use App\Enums\Module;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use InvalidArgumentException;

/**
 * Katalog tema resmi (F103): daftar tema katalog digabung tema terpasang yang tak ada di katalog, dengan aksi Pasang/Perbarui
 * (diantrekan lewat `InstallThemeFromCatalog`), Kembalikan versi sebelumnya, dan Hapus. Hanya pemegang `manage:themes`
 * (super-admin) dan hanya bila modul Konten aktif. Katalog dimuat sekali saat halaman dibuka dan lewat aksi "Muat ulang katalog";
 * tabel membaca status terpasang dan status antrean dari disk/settings di setiap render.
 */
class ThemeCatalog extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'site/theme-catalog';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected string $view = 'filament.pages.site.theme-catalog';

    /** @var list<array<string, mixed>> */
    public array $catalogThemes = [];

    public ?string $catalogError = null;

    public static function getModule(): string
    {
        return Module::Konten->value;
    }

    public static function canAccess(): bool
    {
        return Setting::moduleEnabled(static::getModule()) && (bool) auth()->user()?->can('manage:themes');
    }

    /**
     * Item selalu didaftarkan dan disaring lewat `visible()` di setiap render, sehingga saklar modul yang berubah
     * setelah navigasi dibangun ikut berlaku (bawaan Filament hanya mengecek `canAccess()` sekali saat mendaftar).
     */
    public static function registerNavigationItems(): void
    {
        if (static::shouldRegisterNavigation()) {
            Filament::getCurrentOrDefaultPanel()->navigationItems(static::getNavigationItems());
        }
    }

    /**
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        return array_map(
            fn (NavigationItem $item): NavigationItem => $item->visible(fn (): bool => static::canAccess()),
            parent::getNavigationItems(),
        );
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.situs');
    }

    public static function getNavigationLabel(): string
    {
        return __('pondok.theme_catalog.title');
    }

    public function getTitle(): string
    {
        return __('pondok.theme_catalog.title');
    }

    public function getSubheading(): ?string
    {
        return __('pondok.theme_catalog.description');
    }

    public function mount(): void
    {
        $this->loadCatalog();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshCatalog')
                ->label(__('pondok.theme_catalog.actions.refresh'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (): void {
                    $this->loadCatalog();
                    $this->flushCachedTableRecords();

                    Notification::make()->success()->title(__('pondok.theme_catalog.notifications.refreshed'))->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->columns([
                Split::make([
                    TextColumn::make('name')
                        ->weight('semibold')
                        ->description(fn (array $record): ?string => $record['description'] !== '' ? $record['description'] : null),
                    TextColumn::make('version')
                        ->extraAttributes(['class' => 'tabular-nums']),
                    TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => __("pondok.theme_catalog.status.{$state}"))
                        ->color(fn (string $state): string => match ($state) {
                            'installed' => 'success',
                            'update_available', 'queued', 'running' => 'info',
                            'incompatible' => 'warning',
                            'failed' => 'danger',
                            default => 'gray',
                        })
                        ->description(fn (array $record): ?string => $record['message']),
                ])->from('md'),
            ])
            ->recordActions([
                Action::make('install')
                    ->label(fn (array $record): string => __($record['status_base'] === 'update_available' ? 'pondok.theme_catalog.actions.update' : 'pondok.theme_catalog.actions.install'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (array $record): bool => in_array($record['status_base'], ['not_installed', 'update_available'], true))
                    ->disabled(fn (array $record): bool => in_array($record['status'], ['queued', 'running'], true))
                    ->requiresConfirmation()
                    ->modalDescription(fn (array $record): string => __('pondok.theme_catalog.actions.install_confirm', ['name' => $record['name']]))
                    ->action(fn (array $record) => $this->run($record, fn () => app(InstallThemeFromCatalog::class)->handle($record['slug'], auth()->user()), 'queued')),
                Action::make('rollback')
                    ->label(__('pondok.theme_catalog.actions.rollback'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn (array $record): bool => $record['has_previous'])
                    ->requiresConfirmation()
                    ->modalDescription(fn (array $record): string => __('pondok.theme_catalog.actions.rollback_confirm', ['name' => $record['name']]))
                    ->action(fn (array $record) => $this->run($record, fn () => app(RollbackPortalTheme::class)->handle($record['slug'], auth()->user()), 'rolled_back')),
                Action::make('remove')
                    ->label(__('pondok.theme_catalog.actions.remove'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn (array $record): bool => $record['is_installed'])
                    ->requiresConfirmation()
                    ->modalDescription(fn (array $record): string => __('pondok.theme_catalog.actions.remove_confirm', ['name' => $record['name']]))
                    ->action(fn (array $record) => $this->run($record, fn () => app(RemovePortalTheme::class)->handle($record['slug'], auth()->user()), 'removed')),
            ])
            ->emptyStateIcon(Heroicon::OutlinedInbox)
            ->emptyStateHeading(__('pondok.theme_catalog.empty'))
            ->emptyStateDescription(__('pondok.theme_catalog.empty_description'));
    }

    private function loadCatalog(): void
    {
        $result = app(FetchThemeCatalog::class)->handle();

        $this->catalogThemes = $result['themes'];
        $this->catalogError = $result['error'];
    }

    /**
     * Jalankan satu Action dan tampilkan hasilnya; penolakan Action (pesan Indonesia) menjadi notifikasi danger.
     *
     * @param  array<string, mixed>  $record
     */
    private function run(array $record, \Closure $callback, string $notification): void
    {
        try {
            $callback();
        } catch (InvalidArgumentException $exception) {
            Notification::make()->danger()->title(__('pondok.theme_catalog.notifications.failed'))->body($exception->getMessage())->send();

            return;
        } finally {
            $this->flushCachedTableRecords();
        }

        Notification::make()->success()->title(__("pondok.theme_catalog.notifications.{$notification}", ['name' => $record['name']]))->send();
    }

    /**
     * Baris tabel: katalog digabung tema terpasang yang tak ada di katalog, kunci = slug. `status_base` = keadaan terhadap
     * katalog dan berkas terpasang; `status` = keadaan yang tampil (status antrean menimpa bila `queued|running|failed`).
     *
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        $installed = collect(app(ListPortalThemes::class)->handle())->where('source', 'installed')->keyBy('slug');
        $rows = [];

        foreach ($this->catalogThemes as $entry) {
            $local = $installed->get($entry['slug']);
            $installedVersion = $local['version'] ?? null;
            $compatible = $this->isCompatible($entry);

            $base = match (true) {
                $installedVersion !== null && ! version_compare($entry['version'], $installedVersion, '>') => 'installed',
                ! $compatible => 'incompatible',
                $installedVersion !== null => 'update_available',
                default => 'not_installed',
            };

            $rows[$entry['slug']] = $this->row($entry['slug'], $entry['name'], $entry['description'], $installedVersion, $entry['version'], $base, $local !== null);
        }

        foreach ($installed as $slug => $local) {
            $rows[$slug] ??= $this->row($slug, $local['name'], $local['description'], $local['version'], null, $local['valid'] ? 'installed' : 'incompatible', true);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $slug, string $name, string $description, ?string $installedVersion, ?string $catalogVersion, string $base, bool $isInstalled): array
    {
        $job = InstallThemeFromCatalog::status($slug);
        $jobState = in_array($job['state'] ?? null, ['queued', 'running', 'failed'], true) ? $job['state'] : null;

        return [
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'version' => $base === 'update_available'
                ? __('pondok.theme_catalog.version_update', ['installed' => $installedVersion, 'available' => $catalogVersion])
                : __('pondok.theme_catalog.version', ['version' => $installedVersion ?? $catalogVersion ?? '-']),
            'status_base' => $base,
            'status' => $jobState ?? $base,
            'message' => $jobState === 'failed' ? $job['message'] : null,
            'is_installed' => $isInstalled,
            'has_previous' => $isInstalled && is_file(rtrim((string) config('pondok.installed_themes_path'), '/')."/{$slug}/previous.json"),
        ];
    }

    /**
     * Perkiraan dari entri katalog; penentu sebenarnya `ValidatePortalTheme` pada berkas yang diunduh.
     *
     * @param  array<string, mixed>  $entry
     */
    private function isCompatible(array $entry): bool
    {
        return $entry['schema_version'] >= max(1, MigrateThemeSchema::CURRENT - 1)
            && $entry['schema_version'] <= MigrateThemeSchema::CURRENT
            && version_compare($entry['min_core'], ValidatePortalTheme::CORE_VERSION, '<=');
    }
}
