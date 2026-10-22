<?php

use App\Enums\Module;
use App\Filament\Pages\Settings\Contacts;
use App\Filament\Pages\Settings\DataRetention;
use App\Filament\Pages\Settings\Identity;
use App\Filament\Pages\Settings\Modules;
use App\Filament\Pages\Site\Footer;
use App\Filament\Pages\Site\Home;
use App\Filament\Pages\Site\Navigation;
use App\Filament\Pages\Site\Theme;
use App\Filament\Resources\AcademicYears\AcademicYearResource;
use App\Filament\Resources\Staff\StaffResource;
use App\Filament\Resources\Units\UnitResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\SystemOverviewWidget;
use App\Http\Middleware\EnsureModuleIsEnabled;
use App\Models\Setting;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Filament\Widgets\AccountWidget;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
| F066 AC-9 sampai AC-11: test kepatuhan. Fitur baru yang lupa didaftarkan ke modul (atau ke daftar inti di bawah)
| membuat test ini gagal.
*/

beforeEach(function () {
    kontenBoot();
});

/**
 * Atur semua modul aktif, kecuali `$off` yang dimatikan.
 */
function modulesOnExcept(?Module $off = null): void
{
    foreach (Module::cases() as $module) {
        Setting::set("modules.{$module->value}.enabled", $module !== $off);
    }
}

/**
 * Nilai `getModule()`/`getDashboardModule()` komponen, atau NULL bila tidak mendeklarasikannya.
 */
function componentModule(string $class): ?Module
{
    foreach (['getModule', 'getDashboardModule'] as $method) {
        if (method_exists($class, $method)) {
            return Module::tryFrom((string) $class::$method());
        }
    }

    return null;
}

it('AC-9: every panel resource, page and widget is core or declares a valid module', function () {
    $core = [
        UserResource::class, UnitResource::class,
        AcademicYearResource::class, StaffResource::class,
        RoleResource::class,
        Dashboard::class, Identity::class, Contacts::class,
        Modules::class, DataRetention::class, Home::class, Navigation::class,
        Footer::class, Theme::class,
        AccountWidget::class, SystemOverviewWidget::class,
    ];

    $panel = Filament::getPanel('admin');
    $undeclared = collect([...$panel->getResources(), ...$panel->getPages(), ...$panel->getWidgets()])
        ->reject(fn (string $class): bool => in_array($class, $core, true) || componentModule($class) !== null)
        ->values()->all();

    expect($undeclared)->toBe([], 'Tanpa modul dan bukan inti: '.implode(', ', $undeclared));
});

it('AC-9: disabling one module closes only its resources, pages and widgets', function () {
    $this->actingAs(kontenUser('super-admin'));
    $panel = Filament::getPanel('admin');
    $failures = [];

    foreach (Module::cases() as $module) {
        modulesOnExcept($module);

        foreach ($panel->getResources() as $resource) {
            if (componentModule($resource) === $module && $resource::canViewAny()) {
                $failures[] = "{$resource} masih terbuka saat {$module->value} mati";
            }
        }

        foreach ($panel->getPages() as $page) {
            if (componentModule($page) === $module && $page::canAccess()) {
                $failures[] = "{$page} masih terbuka saat {$module->value} mati";
            }
        }

        foreach ($panel->getWidgets() as $widget) {
            if (componentModule($widget) === $module
                && ! str_contains(bayarHtmlText(Livewire::test($widget)->html()), __('pondok.dashboard.module_unavailable'))) {
                $failures[] = "{$widget} tidak menampilkan state modul belum tersedia saat {$module->value} mati";
            }
        }
    }

    expect($failures)->toBe([], implode("\n", $failures));
});

it('AC-10: every app route is core or sits behind EnsureModuleIsEnabled for a valid module', function () {
    $core = ['portal.home', 'portal.sitemap', 'portal.robots'];
    $pageBases = [Page::class, \Filament\Resources\Pages\Page::class];
    $unguarded = [];

    foreach (Route::getRoutes() as $route) {
        $class = ltrim(explode('@', $route->getActionName())[0], '\\');

        if (! str_starts_with($class, 'App\\') || ! class_exists($class)) {
            continue;
        }

        if (collect($pageBases)->contains(fn (string $base): bool => is_subclass_of($class, $base))) {
            continue;
        }

        if (in_array($route->getName(), $core, true)) {
            continue;
        }

        $guarded = collect($route->gatherMiddleware())
            ->filter(fn (mixed $m): bool => is_string($m) && str_starts_with($m, EnsureModuleIsEnabled::class.':'))
            ->contains(fn (string $m): bool => Module::tryFrom(explode(',', substr($m, strlen(EnsureModuleIsEnabled::class) + 1))[0]) !== null);

        if (! $guarded) {
            $unguarded[] = ($route->getName() ?? $route->uri()).' ('.$class.')';
        }
    }

    expect($unguarded)->toBe([], 'Route tanpa modul: '.implode(', ', $unguarded));
});

it('AC-10: switching a module off closes its custom panel route and portal pages', function () {
    modulesOnExcept(Module::Keuangan);

    $this->actingAs(kontenUser('bendahara'))->get(route('filament.admin.keuangan.receipt', 1))->assertForbidden();

    modulesOnExcept(Module::Konten);

    $this->get('/berita')->assertNotFound();
});

it('AC-11: every non-core scheduled event is skipped when modules are off and runs when they are on', function () {
    Artisan::call('schedule:list');

    $isCore = fn (Event $event): bool => $event->description === 'pondok:scheduler-heartbeat'
        || str_contains((string) $event->command, 'queue:work')
        || str_contains((string) $event->command, 'pondok:backup')
        || str_contains((string) $event->command, 'pondok:redact-letter-snapshots')
        || str_contains((string) $event->command, 'pondok:prune-activity-log');
    $events = collect(app(Schedule::class)->events())->reject($isCore);
    $label = fn (Event $event): string => (string) ($event->command ?: $event->description);

    expect($events)->not->toBeEmpty();

    foreach (Module::cases() as $module) {
        Setting::set("modules.{$module->value}.enabled", false);
    }

    expect($events->reject(fn (Event $e): bool => $e->filtersPass(app()) === false)->map($label)->values()->all())
        ->toBe([], 'Event jadwal tetap jalan saat semua modul mati');

    modulesOnExcept();

    expect($events->reject(fn (Event $e): bool => $e->filtersPass(app()) === true)->map($label)->values()->all())
        ->toBe([], 'Event jadwal terblokir saat semua modul aktif');
});

it('AC-9: portal wali has no Filament panel and every portal-wali route is module guarded', function () {
    $guard = EnsureModuleIsEnabled::class;
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'portal-wali.'));
    $extra = ['portal-wali.invoices.show' => 'keuangan', 'portal-wali.documents.download' => 'dokumen'];

    expect(Filament::getPanels())->not->toHaveKey('wali')
        ->and(collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'filament.wali.')))->toBeEmpty()
        ->and(file_get_contents(base_path('bootstrap/providers.php')))->not->toContain('WaliPanelProvider')
        ->and($routes->map(fn ($route): string => $route->getName())->all())->toContain(
            'portal-wali.login', 'portal-wali.password.request', 'portal-wali.password.reset', 'portal-wali.verification.notice',
            'portal-wali.verification.verify', 'portal-wali.verification.send', 'portal-wali.logout', 'portal-wali.home',
            'portal-wali.students.show', 'portal-wali.invoices.show', 'portal-wali.documents.download',
        );

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain($guard.':portal-wali,404');

        if (isset($extra[$route->getName()])) {
            expect($middleware)->toContain($guard.':'.$extra[$route->getName()].',404');
        }
    }
});
