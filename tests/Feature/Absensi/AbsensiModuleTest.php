<?php

use App\Enums\AttendanceStatus;
use App\Enums\Module;
use App\Filament\Pages\AttendanceRecap;
use App\Filament\Resources\AttendanceSessions\AttendanceSessionResource;
use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Classroom;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

it('AC-1: registers the absensi module that requires akademik, with translated statuses and colors', function () {
    expect(Module::from('absensi'))->toBe(Module::Absensi)
        ->and(Module::Absensi->requires())->toBe([Module::Akademik])
        ->and(AttendanceSessionResource::getModule())->toBe('absensi')
        ->and(AttendanceRecap::getModule())->toBe('absensi');

    expectTranslatedKeys(['pondok.modules.absensi.label', 'pondok.modules.absensi.description']);

    $colors = ['hadir' => 'success', 'sakit' => 'warning', 'izin' => 'info', 'alpa' => 'danger'];

    expect(collect(AttendanceStatus::cases())->map(fn (AttendanceStatus $status): string => $status->value)->all())->toBe(array_keys($colors));

    foreach (AttendanceStatus::cases() as $status) {
        expect($status->color())->toBe($colors[$status->value], $status->value)
            ->and($status->label())->toBe(__("pondok.attendance.statuses.{$status->value}"));
    }

    expectTranslatedKeys(collect($colors)->keys()->map(fn (string $value): string => "pondok.attendance.statuses.{$value}"));
});

it('AC-1: seeds the switch on and the wali-kelas role through the system seeder', function () {
    $this->seed(SystemSeeder::class);

    expect(Setting::query()->find('modules.absensi.enabled')?->value)->toBeTrue()
        ->and(Role::query()->where('name', 'wali-kelas')->exists())->toBeTrue();

    Setting::set('modules.absensi.enabled', false);
    $this->seed(SettingSeeder::class);

    expect(Module::Absensi->isEnabled())->toBeFalse();
});

it('AC-1: closes the resource and recap when the module is off, keeps the data and the dashboards', function () {
    $f = absensiFixture();
    absensiRaw($f->c7a, '2026-10-14', [$f->s1->id => 'alpa']);

    $this->actingAs($f->admin)->get('/admin/attendance-sessions')->assertOk();
    $this->get('/admin/attendance-recap')->assertOk();

    Setting::set('modules.absensi.enabled', false);

    expect(AttendanceSessionResource::canViewAny())->toBeFalse()
        ->and(AttendanceRecap::canAccess())->toBeFalse()
        ->and($this->get('/admin/attendance-sessions')->status())->toBeIn([403, 404])
        ->and($this->get('/admin/attendance-sessions/create')->status())->toBeIn([403, 404])
        ->and($this->get('/admin/attendance-recap')->status())->toBeIn([403, 404])
        ->and(AttendanceSession::withoutGlobalScopes()->count())->toBe(1)
        ->and(AttendanceRecord::query()->count())->toBe(1);

    $this->get('/admin')->assertOk();
    $this->actingAs($f->homeroom)->get('/admin')->assertOk();
});

it('AC-13: seeds a wali-kelas demo account and attendance sessions through the Action, idempotently', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00', 'Asia/Jakarta'));
    Storage::fake('private');
    Storage::fake('public');

    $this->seed(DemoSeeder::class);

    $user = User::query()->where('email', 'wali-kelas@demo.test')->firstOrFail();
    $staff = DB::table('staff')->where('user_id', $user->id)->first();
    $room = Classroom::query()->where('academic_year_id', AcademicYear::current()->id)->where('name', '7A')->firstOrFail();
    $admin = User::query()->where('email', 'admin-sekolah@demo.test')->firstOrFail();

    expect($user->hasRole('wali-kelas'))->toBeTrue()
        ->and($staff)->not->toBeNull()
        ->and($room->homeroom_staff_id)->toBe($staff->id);

    $sessions = AttendanceSession::withoutGlobalScopes()->get();
    $statuses = AttendanceRecord::query()->pluck('status')->map(fn (mixed $status): string => $status instanceof BackedEnum ? $status->value : $status)->unique();

    expect($sessions->where('classroom_id', $room->id)->count())->toBeGreaterThanOrEqual(2)
        ->and($sessions->pluck('classroom_id')->unique()->count())->toBeGreaterThanOrEqual(2)
        ->and($sessions->every(fn (AttendanceSession $session): bool => $session->recorded_by === $admin->id))->toBeTrue()
        ->and($statuses->count())->toBeGreaterThanOrEqual(2)
        ->and($statuses->contains('hadir'))->toBeTrue();

    $this->seed(DemoSeeder::class);

    expect(AttendanceSession::withoutGlobalScopes()->count())->toBe($sessions->count());
})->group('seeder-demo');
