<?php

use App\Actions\Core\SetModuleEnabled;
use App\Enums\Module;
use App\Filament\Resources\Halaqahs\HalaqahResource;
use App\Filament\Resources\MemorizationRecords\MemorizationRecordResource;
use App\Filament\Resources\TahfidzExams\TahfidzExamResource;
use App\Filament\Resources\TahfidzTargets\TahfidzTargetResource;
use App\Models\Halaqah;
use App\Models\MemorizationRecord;
use App\Models\Setting;
use Database\Seeders\SettingSeeder;
use Database\Seeders\SystemSeeder;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->f = tahfidzBoot();
});

it('AC-1: registers the tahfidz module that requires akademik, with translated texts', function () {
    expect(Module::from('tahfidz'))->toBe(Module::Tahfidz)
        ->and(Module::Tahfidz->requires())->toBe([Module::Akademik])
        ->and(Module::Tahfidz->label())->toBe(__('pondok.modules.tahfidz.label'))
        ->and(Module::Tahfidz->description())->toBe(__('pondok.modules.tahfidz.description'));

    expectTranslatedKeys(['pondok.modules.tahfidz.label', 'pondok.modules.tahfidz.description', 'pondok.navigation.tahfidz']);
});

it('AC-1: declares the tahfidz module on all four resources and registers them in the panel', function () {
    $panel = Filament::getPanel('admin');

    foreach ([HalaqahResource::class, MemorizationRecordResource::class, TahfidzExamResource::class, TahfidzTargetResource::class] as $resource) {
        expect($resource::getModule())->toBe('tahfidz')
            ->and($panel->getResources())->toContain($resource);
    }
});

it('AC-1: closes the resources and hides the menu when the module is off, but keeps the data', function () {
    [$halaqah, $student] = tahfidzHalaqahWithMember($this->f);
    tahfidzRawRecord($halaqah, $student);
    $admin = tahfidzAdmin();

    $this->actingAs($admin)->get('/admin')->assertOk()->assertSee(__('pondok.navigation.tahfidz'));

    Setting::set('modules.tahfidz.enabled', false);

    foreach (['halaqahs', 'memorization-records', 'tahfidz-exams', 'tahfidz-targets'] as $slug) {
        expect($this->actingAs($admin)->get("/admin/{$slug}")->status())->toBeIn([403, 404], $slug);
    }

    $this->actingAs($admin)->get('/admin')->assertOk()->assertDontSee(__('pondok.navigation.tahfidz'));

    expect(Halaqah::query()->count())->toBe(1)
        ->and(MemorizationRecord::query()->count())->toBe(1);
});

it('AC-1: seeds a switch for every module on a fresh install, including tahfidz', function () {
    Setting::query()->delete();

    $this->seed(SettingSeeder::class);

    expect(Setting::query()->where('key', 'like', 'modules.%.enabled')->count())->toBe(count(Module::cases()))
        ->and(Setting::query()->find('modules.tahfidz.enabled')?->value)->toBeTrue();
});

it('AC-1: never overwrites an existing off switch on seed or upgrade and treats a missing row as off', function () {
    Setting::set('modules.tahfidz.enabled', false);

    $this->seed(SettingSeeder::class);
    $this->seed(SystemSeeder::class);

    expect(Module::Tahfidz->isEnabled())->toBeFalse();

    Setting::query()->whereKey('modules.tahfidz.enabled')->delete();
    Setting::forget('modules.tahfidz.enabled');

    expect(Module::Tahfidz->isEnabled())->toBeFalse();
});

it('AC-1: refuses to switch akademik off while tahfidz is on', function () {
    foreach (['asrama', 'psb', 'keuangan', 'prestasi', 'portal-wali'] as $other) {
        Setting::set("modules.{$other}.enabled", false);
    }

    expect(fn () => app(SetModuleEnabled::class)->handle(tahfidzAdmin(), Module::Akademik, false))
        ->toThrow(ValidationException::class);

    expect(Module::Akademik->isEnabled())->toBeTrue();
});
