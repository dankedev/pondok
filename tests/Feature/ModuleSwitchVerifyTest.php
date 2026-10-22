<?php

use App\Actions\Core\SetModuleEnabled;
use App\Enums\Module;
use App\Filament\Pages\Settings\Modules;
use App\Http\Middleware\EnsureModuleIsEnabled;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Student;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| F066 VERIFY: celah di luar test RED (otorisasi Livewire, data lama melanggar graf,
| route/halaman yang masih terbuka saat modulnya mati, data utuh setelah matikan-aktifkan).
*/

beforeEach(function () {
    kontenBoot();
});

it('rejects a non super-admin mounting the modules page through Livewire', function () {
    $this->actingAs(kontenUser('admin-sekolah'));

    Livewire::test(Modules::class)->assertForbidden();
});

it('rejects a toggle when the super-admin role is lost after the page was mounted', function () {
    $admin = kontenUser('super-admin');
    $this->actingAs($admin);

    $component = Livewire::test(Modules::class);
    $admin->removeRole('super-admin');
    try {
        $component->callAction('toggle-konten');
    } catch (Throwable) {
        // 403 dari komponen atau Action diharapkan.
    }

    expect(Module::Konten->isEnabled())->toBeTrue()
        ->and(Activity::query()->where('log_name', 'pengaturan')->count())->toBe(0);
});

it('rejects the action for every non super-admin role and leaves no trace', function (string $role) {
    expect(fn () => app(SetModuleEnabled::class)->handle(kontenUser($role), Module::Konten, false))
        ->toThrow(AuthorizationException::class);

    expect(Module::Konten->isEnabled())->toBeTrue()
        ->and(Activity::query()->count())->toBe(0);
})->with(['bendahara', 'admin-konten', 'admin-psb']);

it('lets the super-admin switch psb off when the stored data violates the graph (psb on, keuangan off)', function () {
    Setting::set('modules.keuangan.enabled', false);
    $this->actingAs(kontenUser('super-admin'));

    Livewire::test(Modules::class)->callAction('toggle-psb');

    expect(Module::Psb->isEnabled())->toBeFalse()
        ->and(Module::Keuangan->isEnabled())->toBeFalse();

    $this->get('/admin/settings/modules')->assertOk();
});

it('renders the dashboard and the finance-dependent pages when psb is on and keuangan is off', function () {
    Setting::set('modules.keuangan.enabled', false);
    $admin = kontenUser('super-admin');

    expect(bayarHtmlText(dashboardGet($admin)->getContent()))->toContain('Modul belum tersedia');
    $this->actingAs($admin)->get('/admin/applicants')->assertOk();
});

it('logs enabling a module whose setting row is missing as old=false', function () {
    Setting::query()->where('key', 'modules.akademik.enabled')->delete();
    Setting::forget('modules.akademik.enabled');

    app(SetModuleEnabled::class)->handle(kontenUser('super-admin'), Module::Akademik, true);

    $activity = Activity::query()->where('log_name', 'pengaturan')->sole();

    expect(Module::Akademik->isEnabled())->toBeTrue()
        ->and($activity->properties['old']['enabled'])->toBeFalse()
        ->and($activity->properties['attributes']['enabled'])->toBeTrue();
});

it('keeps students and invoices intact after switching every module off and on again', function () {
    $invoice = Invoice::factory()->create();
    $students = Student::query()->count();
    $invoices = Invoice::query()->count();
    $admin = kontenUser('super-admin');
    $action = app(SetModuleEnabled::class);
    $order = [Module::Kesehatan, Module::Psb, Module::Tahfidz, Module::Asrama, Module::Keuangan, Module::Prestasi, Module::PortalWali, Module::Akademik, Module::Dokumen, Module::Konten];

    foreach ($order as $module) {
        $action->handle($admin, $module, false);
    }

    expect(array_filter(Module::cases(), fn (Module $m): bool => $m->isEnabled()))->toBe([]);

    foreach (array_reverse($order) as $module) {
        $action->handle($admin, $module, true);
    }

    expect(Student::query()->count())->toBe($students)
        ->and(Invoice::query()->count())->toBe($invoices)
        ->and(Invoice::query()->find($invoice->id))->not->toBeNull();
});

it('closes every route guarded by EnsureModuleIsEnabled for the super-admin when its module is off', function () {
    $this->actingAs(kontenUser('super-admin'));
    $open = [];

    foreach (Route::getRoutes() as $route) {
        $guard = collect($route->gatherMiddleware())
            ->first(fn (mixed $m): bool => is_string($m) && str_starts_with($m, EnsureModuleIsEnabled::class.':'));

        if ($guard === null || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $module = Module::from(explode(',', substr($guard, strlen(EnsureModuleIsEnabled::class) + 1))[0]);

        foreach (Module::cases() as $case) {
            Setting::set("modules.{$case->value}.enabled", $case !== $module);
        }

        $uri = preg_replace('/\{[^}]+\}/', '1', $route->uri());
        $status = $this->get('/'.ltrim($uri, '/'))->getStatusCode();

        if (! in_array($status, [403, 404], true)) {
            $open[] = "{$route->uri()} => {$status} saat {$module->value} mati";
        }
    }

    expect($open)->toBe([], implode("\n", $open));
});

it('answers 403 or 404 on the index page of every module resource when its module is off', function () {
    $this->actingAs(kontenUser('super-admin'));
    $open = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        if (! method_exists($resource, 'getModule') || ! array_key_exists('index', $resource::getPages())) {
            continue;
        }

        $module = Module::from($resource::getModule());

        foreach (Module::cases() as $case) {
            Setting::set("modules.{$case->value}.enabled", $case !== $module);
        }

        $status = $this->get($resource::getUrl('index'))->getStatusCode();

        if (! in_array($status, [403, 404], true)) {
            $open[] = "{$resource} => {$status} saat {$module->value} mati";
        }
    }

    expect($open)->toBe([], implode("\n", $open));
});

it('keeps links to a disabled module out of the portal while the other module stays linked', function () {
    portalBoot();
    $link = fn (string $type, string $label): array => ['type' => $type, 'label' => $label];
    profilRawSet('portal.navigation_primary', [$link('home', 'Beranda'), $link('posts', 'Berita'), $link('psb.info', 'Info PSB')]);

    Setting::set('modules.konten.enabled', false);

    $html = $this->get('/')->assertOk()->getContent();
    $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($html)->not->toContain('/berita')
        ->and($html)->toContain('/info-psb')
        ->and($sitemap)->not->toContain('/berita')->not->toContain('/galeri')
        ->and($sitemap)->toContain('/info-psb');

    Setting::set('modules.konten.enabled', true);
    Setting::set('modules.psb.enabled', false);

    $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($sitemap)->not->toContain('/psb')->not->toContain('/info-psb')->toContain('/berita');
});
