<?php

use App\Enums\Module;
use App\Enums\ViolationCategory;
use App\Filament\Pages\DisciplineRecap;
use App\Filament\Resources\DisciplineLevels\DisciplineLevelResource;
use App\Filament\Resources\StudentViolations\Pages\ListStudentViolations;
use App\Filament\Resources\StudentViolations\StudentViolationResource;
use App\Filament\Resources\ViolationTypes\ViolationTypeResource;
use App\Models\Setting;
use Database\Seeders\SettingSeeder;
use Database\Seeders\SystemSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = disiplinFixture();
});

it('AC-1: registers the kedisiplinan module that requires akademik, with translated texts and categories', function () {
    expect(Module::from('kedisiplinan'))->toBe(Module::Kedisiplinan)
        ->and(Module::Kedisiplinan->requires())->toBe([Module::Akademik])
        ->and(Module::Kedisiplinan->label())->toBe(__('pondok.modules.kedisiplinan.label'))
        ->and(Module::Kedisiplinan->description())->toBe(__('pondok.modules.kedisiplinan.description'))
        ->and(ViolationTypeResource::getModule())->toBe('kedisiplinan')
        ->and(DisciplineLevelResource::getModule())->toBe('kedisiplinan')
        ->and(StudentViolationResource::getModule())->toBe('kedisiplinan')
        ->and(DisciplineRecap::getModule())->toBe('kedisiplinan')
        ->and(array_map(fn (ViolationCategory $category): string => $category->value, ViolationCategory::cases()))->toBe(['ringan', 'sedang', 'berat']);

    foreach (ViolationCategory::cases() as $category) {
        expect($category->label())->toBeString()->not->toBe('')->not->toContain('pondok.');
    }

    expectTranslatedKeys(['pondok.modules.kedisiplinan.label', 'pondok.modules.kedisiplinan.description', 'pondok.navigation.kedisiplinan']);
});

it('AC-1: seeds the switch on for a fresh install and never overwrites an existing off switch', function () {
    Setting::query()->delete();
    $this->seed(SettingSeeder::class);

    expect(Setting::query()->find('modules.kedisiplinan.enabled')?->value)->toBeTrue();

    Setting::set('modules.kedisiplinan.enabled', false);
    $this->seed(SettingSeeder::class);
    $this->seed(SystemSeeder::class);

    expect(Module::Kedisiplinan->isEnabled())->toBeFalse();
});

it('AC-1: closes every resource, the recap and the menu when the module is off, keeps the data, and reopens it', function () {
    $f = $this->f;
    disiplinRaw($f, $f->s1, $f->ringan);
    $paths = ['/admin/violation-types', '/admin/discipline-levels', '/admin/student-violations', '/admin/discipline-recap'];

    $this->actingAs($f->admin);
    $this->get('/admin')->assertOk()->assertSee(__('pondok.navigation.kedisiplinan'));

    foreach ($paths as $path) {
        $this->get($path)->assertOk();
    }

    Setting::set('modules.kedisiplinan.enabled', false);

    expect(StudentViolationResource::canViewAny())->toBeFalse()
        ->and(ViolationTypeResource::canViewAny())->toBeFalse()
        ->and(DisciplineLevelResource::canViewAny())->toBeFalse()
        ->and(DisciplineRecap::canAccess())->toBeFalse();

    foreach ($paths as $path) {
        $this->get($path)->assertForbidden();
    }

    $this->get('/admin')->assertOk()->assertDontSee(__('pondok.navigation.kedisiplinan'));
    expect(disiplinCount())->toBe(1);

    Setting::set('modules.kedisiplinan.enabled', true);
    $this->get('/admin/student-violations')->assertOk();
});

it('AC-2: gives kesantrian every page of the module', function () {
    $f = $this->f;
    $record = disiplinRaw($f, $f->s1, $f->ringan);
    $this->actingAs($f->kes);

    $this->get('/admin')->assertOk()->assertSee(__('pondok.navigation.kedisiplinan'));

    foreach (['/admin/violation-types', '/admin/violation-types/create', '/admin/discipline-levels', '/admin/discipline-levels/create',
        '/admin/student-violations', '/admin/student-violations/create', "/admin/student-violations/{$record->id}/edit", '/admin/discipline-recap'] as $path) {
        $this->get($path)->assertOk();
    }

    Livewire::test(ListStudentViolations::class)->assertTableActionVisible('cancel', $record);
});

it('AC-2: lets a musyrif view and record violations and open the recap, but nothing else', function () {
    $f = $this->f;
    $record = disiplinRaw($f, $f->s1, $f->ringan);
    $this->actingAs($f->musyrif);

    $this->get('/admin')->assertOk()->assertSee(__('pondok.navigation.kedisiplinan'));

    foreach (['/admin/student-violations', '/admin/student-violations/create', "/admin/student-violations/{$record->id}", '/admin/discipline-recap'] as $path) {
        $this->get($path)->assertOk();
    }

    foreach (['/admin/violation-types', '/admin/violation-types/create', '/admin/discipline-levels', '/admin/discipline-levels/create',
        "/admin/student-violations/{$record->id}/edit"] as $path) {
        $this->get($path)->assertForbidden();
    }

    Livewire::test(ListStudentViolations::class)->assertTableActionHidden('cancel', $record)->assertTableActionHidden('edit', $record);
});

it('AC-2: lets admin-sekolah only view violations and the recap', function () {
    $f = $this->f;
    $record = disiplinRaw($f, $f->s1, $f->ringan);
    $this->actingAs($f->adminSekolah);

    $this->get('/admin')->assertOk()->assertSee(__('pondok.navigation.kedisiplinan'));

    foreach (['/admin/student-violations', "/admin/student-violations/{$record->id}", '/admin/discipline-recap'] as $path) {
        $this->get($path)->assertOk();
    }

    foreach (['/admin/student-violations/create', "/admin/student-violations/{$record->id}/edit", '/admin/violation-types', '/admin/violation-types/create',
        '/admin/discipline-levels', '/admin/discipline-levels/create'] as $path) {
        $this->get($path)->assertForbidden();
    }

    Livewire::test(ListStudentViolations::class)->assertTableActionHidden('cancel', $record)->assertTableActionHidden('edit', $record);
});

it('AC-2: closes every page and the menu for wali-kelas, bendahara and admin-konten', function () {
    $f = $this->f;
    $record = disiplinRaw($f, $f->s1, $f->ringan);

    foreach ([$f->waliKelas, $f->bendahara, asramaUser('admin-konten')] as $user) {
        $this->actingAs($user);
        $role = $user->getRoleNames()->first();

        $this->get('/admin')->assertOk()->assertDontSee(__('pondok.navigation.kedisiplinan'));

        foreach (['/admin/violation-types', '/admin/discipline-levels', '/admin/student-violations', '/admin/student-violations/create',
            "/admin/student-violations/{$record->id}", '/admin/discipline-recap'] as $path) {
            expect($this->get($path)->status())->toBe(403, "{$role} membuka {$path}");
        }
    }
});
