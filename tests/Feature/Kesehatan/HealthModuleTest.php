<?php

use App\Actions\Core\PruneActivityLog;
use App\Enums\Module;
use App\Filament\Resources\HealthVisits\HealthVisitResource;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Filament\Resources\Students\RelationManagers\HealthVisitsRelationManager;
use App\Models\HealthVisit;
use App\Models\Setting;
use Database\Seeders\SettingSeeder;
use Database\Seeders\SystemSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->f = kesehatanFixture();
});

it('AC-1: registers the kesehatan module that requires akademik, with translated texts', function () {
    expect(Module::from('kesehatan'))->toBe(Module::Kesehatan)
        ->and(Module::Kesehatan->requires())->toBe([Module::Akademik])
        ->and(Module::Kesehatan->label())->toBe(__('pondok.modules.kesehatan.label'))
        ->and(Module::Kesehatan->description())->toBe(__('pondok.modules.kesehatan.description'));

    expectTranslatedKeys(['pondok.modules.kesehatan.label', 'pondok.modules.kesehatan.description', 'pondok.navigation.kesehatan']);
});

it('AC-1: declares the module on the resource and registers it in the panel', function () {
    expect(HealthVisitResource::getModule())->toBe('kesehatan')
        ->and(Filament::getPanel('admin')->getResources())->toContain(HealthVisitResource::class);
});

it('AC-1: seeds the switch on for a fresh install and never overwrites an existing off switch', function () {
    Setting::query()->delete();
    $this->seed(SettingSeeder::class);

    expect(Setting::query()->find('modules.kesehatan.enabled')?->value)->toBeTrue();

    Setting::set('modules.kesehatan.enabled', false);
    $this->seed(SettingSeeder::class);
    $this->seed(SystemSeeder::class);

    expect(Module::Kesehatan->isEnabled())->toBeFalse();

    Setting::query()->whereKey('modules.kesehatan.enabled')->delete();
    Setting::forget('modules.kesehatan.enabled');

    expect(Module::Kesehatan->isEnabled())->toBeFalse();
});

it('AC-2: closes the resource, menu and student tab when the module is off, keeps the data, and reopens it', function () {
    kesehatanRaw($this->f->s1);
    $admin = $this->f->admin;

    $this->actingAs($admin)->get('/admin')->assertOk()->assertSee(__('pondok.navigation.kesehatan'));
    $this->actingAs($admin)->get('/admin/health-visits')->assertOk();
    expect(HealthVisitsRelationManager::canViewForRecord($this->f->s1, EditStudent::class))->toBeTrue();

    Setting::set('modules.kesehatan.enabled', false);

    expect(HealthVisitResource::canViewAny())->toBeFalse()
        ->and(HealthVisitsRelationManager::canViewForRecord($this->f->s1, EditStudent::class))->toBeFalse()
        ->and($this->actingAs($admin)->get('/admin/health-visits')->status())->toBeIn([403, 404])
        ->and($this->actingAs($admin)->get('/admin/health-visits/create')->status())->toBeIn([403, 404]);

    $this->actingAs($admin)->get('/admin')->assertOk()->assertDontSee(__('pondok.navigation.kesehatan'));
    expect(HealthVisit::query()->count())->toBe(1);

    Setting::set('modules.kesehatan.enabled', true);

    expect(HealthVisitResource::canViewAny())->toBeTrue();
    $this->actingAs($admin)->get('/admin/health-visits')->assertOk();
});

it('AC-14: never prunes the kesehatan activity log', function () {
    expect(PruneActivityLog::PRUNABLE_LOG_NAMES)->not->toContain('kesehatan');
});
