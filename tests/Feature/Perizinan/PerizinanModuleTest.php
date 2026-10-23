<?php

use App\Actions\Perizinan\SaveLeaveType;
use App\Enums\Module;
use App\Filament\Resources\HomecomingPeriods\HomecomingPeriodResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\LeaveTypes\LeaveTypeResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Filament\Widgets\OverdueLeavesWidget;
use App\Models\HomecomingPeriod;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Setting;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Database\Seeders\InstallSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\SystemSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

$resources = [
    'leave request' => LeaveRequestResource::class,
    'leave type' => LeaveTypeResource::class,
    'homecoming period' => HomecomingPeriodResource::class,
    'visit' => VisitResource::class,
];

it('AC-1: registers the perizinan module that requires akademik and asrama, with translated texts', function () {
    expect(Module::from('perizinan'))->toBe(Module::Perizinan)
        ->and(Module::Perizinan->requires())->toEqualCanonicalizing([Module::Akademik, Module::Asrama])
        ->and(Module::Perizinan->label())->toBe(__('pondok.modules.perizinan.label'))
        ->and(Module::Perizinan->description())->toBe(__('pondok.modules.perizinan.description'));

    expectTranslatedKeys(['pondok.modules.perizinan.label', 'pondok.modules.perizinan.description', 'pondok.navigation.perizinan']);
});

it('AC-1: declares the module on every resource and registers them in the panel', function () use ($resources) {
    $panelResources = Filament::getPanel('admin')->getResources();

    foreach ($resources as $name => $resource) {
        expect($resource::getModule())->toBe('perizinan', $name)
            ->and($panelResources)->toContain($resource);
    }

    expect(OverdueLeavesWidget::getDashboardModule())->toBe('perizinan')
        ->and(OverdueLeavesWidget::getDashboardPermission())->toBe('view-leave-metrics')
        ->and(OverdueLeavesWidget::getRequiredTables())->toBe(['leave_requests']);
});

it('AC-1: seeds the switch on for a fresh install and never overwrites an existing off switch', function () {
    Setting::query()->delete();
    $this->seed(SettingSeeder::class);

    expect(Setting::query()->find('modules.perizinan.enabled')?->value)->toBeTrue();

    Setting::set('modules.perizinan.enabled', false);
    $this->seed(SettingSeeder::class);
    $this->seed(SystemSeeder::class);

    expect(Module::Perizinan->isEnabled())->toBeFalse();
});

it('AC-1: closes every resource, URL and the widget when the module is off, keeps the data, and reopens it', function () use ($resources) {
    $f = perizinanFixture();
    perizinanRaw($f->s1, $f->keluar);
    perizinanRawVisit($f->s1);
    perizinanPeriod($f);
    $this->actingAs($f->admin);

    foreach ($resources as $name => $resource) {
        expect($resource::canViewAny())->toBeTrue($name);
    }

    $this->get('/admin')->assertOk()->assertSee(__('pondok.navigation.perizinan'));

    Setting::set('modules.perizinan.enabled', false);

    foreach ($resources as $name => $resource) {
        expect($resource::canViewAny())->toBeFalse($name);
    }

    foreach (['/admin/leave-requests', '/admin/leave-types', '/admin/homecoming-periods', '/admin/visits'] as $url) {
        expect($this->get($url)->status())->toBeIn([403, 404], $url);
    }

    $this->get('/admin')->assertOk()->assertDontSee(__('pondok.navigation.perizinan'));

    expect(OverdueLeavesWidget::isModuleAvailable())->toBeFalse()
        ->and(bayarHtmlText(Livewire::test(OverdueLeavesWidget::class)->html()))->toContain(__('pondok.dashboard.module_unavailable'))
        ->and(LeaveRequest::withoutGlobalScopes()->count())->toBe(1)
        ->and(Visit::withoutGlobalScopes()->count())->toBe(1)
        ->and(HomecomingPeriod::query()->count())->toBe(1);

    Setting::set('modules.perizinan.enabled', true);

    expect(LeaveRequestResource::canViewAny())->toBeTrue();
    $this->get('/admin/leave-requests')->assertOk();
});

it('AC-2: installs the four default leave types, twice, without overwriting edits', function () {
    $this->seed(InstallSeeder::class);

    $rows = DB::table('leave_types')->get()->keyBy('code');

    expect($rows->keys()->sort()->values()->all())->toBe(['KELUAR', 'KEPERLUAN', 'PULANG', 'SAKIT'])
        ->and($rows['KELUAR']->max_hours)->toBe(6)
        ->and($rows['KELUAR']->approver)->toBe('musyrif')
        ->and($rows['PULANG']->max_hours)->toBeNull()
        ->and($rows['PULANG']->approver)->toBe('kepala_asrama')
        ->and((bool) $rows['PULANG']->requires_guardian)->toBeTrue()
        ->and($rows['SAKIT']->approver)->toBe('kesantrian')
        ->and($rows['KEPERLUAN']->max_hours)->toBe(72)
        ->and($rows['KEPERLUAN']->approver)->toBe('kesantrian')
        ->and((bool) $rows['KEPERLUAN']->requires_guardian)->toBeTrue()
        ->and($rows->every(fn (object $row): bool => (bool) $row->is_active && trim($row->name) !== ''))->toBeTrue();

    DB::table('leave_types')->where('code', 'KELUAR')->update(['max_hours' => 8, 'name' => 'Keluar Sebentar']);

    $this->seed(InstallSeeder::class);

    $keluar = DB::table('leave_types')->where('code', 'KELUAR')->first();

    expect(DB::table('leave_types')->count())->toBe(4)
        ->and($keluar->max_hours)->toBe(8)
        ->and($keluar->name)->toBe('Keluar Sebentar');
});

it('AC-2: lets kesantrian create and update a leave type through the Action and refuses the other roles', function () {
    $f = perizinanFixture();
    $data = ['code' => 'IZIN2', 'name' => 'Izin Dua', 'max_hours' => 12, 'approver' => 'musyrif', 'requires_guardian' => false, 'is_active' => true];
    $this->actingAs($f->kes);

    $type = app(SaveLeaveType::class)->handle($data, $f->kes);

    expect(LeaveType::query()->where('code', 'IZIN2')->sole()->is($type))->toBeTrue()
        ->and($f->kes->can('create', LeaveType::class))->toBeTrue()
        ->and($f->kes->can('update', $type))->toBeTrue();

    app(SaveLeaveType::class)->handle([...$data, 'max_hours' => 24, 'is_active' => false], $f->kes, $type);

    expect($type->fresh()->max_hours)->toBe(24)->and($type->fresh()->is_active)->toBeFalse();

    foreach ([$f->mA, $f->pos, $f->bendahara] as $actor) {
        $this->actingAs($actor);

        expect(fn () => app(SaveLeaveType::class)->handle([...$data, 'code' => 'IZIN3'], $actor))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SaveLeaveType::class)->handle([...$data, 'max_hours' => 99], $actor, $type))->toThrow(AuthorizationException::class);
    }

    expect(LeaveType::query()->count())->toBe(5)->and($type->fresh()->max_hours)->toBe(24);
});

it('AC-15: seeds the demo Idul Fitri period, leave requests of several statuses and visits, idempotently', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00', 'Asia/Jakarta'));
    Storage::fake('private');
    Storage::fake('public');

    $this->seed(DemoSeeder::class);

    $requests = LeaveRequest::withoutGlobalScopes()->get();
    $visits = Visit::withoutGlobalScopes()->count();

    expect(HomecomingPeriod::query()->where('name', 'Libur Idul Fitri 1448 H')->count())->toBe(1)
        ->and($requests->map(fn (LeaveRequest $r): string => perizinanValue($r->status))->unique()->count())->toBeGreaterThanOrEqual(4)
        ->and(LeaveRequest::withoutGlobalScopes()->late()->count())->toBeGreaterThanOrEqual(1)
        ->and($visits)->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(3);

    $this->seed(DemoSeeder::class);

    expect(LeaveRequest::withoutGlobalScopes()->count())->toBe($requests->count())
        ->and(Visit::withoutGlobalScopes()->count())->toBe($visits)
        ->and(HomecomingPeriod::query()->where('name', 'Libur Idul Fitri 1448 H')->count())->toBe(1);
})->group('seeder-demo');
