<?php

use App\Filament\Pages\AttendanceRecap;
use App\Filament\Resources\AttendanceSessions\AttendanceSessionResource;
use App\Filament\Resources\AttendanceSessions\Pages\ListAttendanceSessions;
use App\Models\AttendanceSession;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = absensiFixture();
});

it('AC-2: closes the resource and the recap for every role without absensi permissions (:role)', function (string $role) {
    $this->actingAs(asramaUser($role));

    expect(AttendanceSessionResource::canViewAny())->toBeFalse()
        ->and(AttendanceRecap::canAccess())->toBeFalse();
    $this->get('/admin/attendance-sessions')->assertForbidden();
    $this->get('/admin/attendance-recap')->assertForbidden();
})->with(['bendahara', 'admin-konten', 'admin-psb', 'musyrif', 'ustadz-tahfidz', 'petugas-pos']);

it('AC-6: lets a wali kelas see and record only the homeroom classroom', function () {
    $f = $this->f;
    $own = absensiRaw($f->c7a, '2026-10-14', [$f->s1->id => 'alpa', $f->s2->id => 'hadir', $f->s3->id => 'hadir']);
    $foreign = absensiRaw($f->c7b, '2026-10-14', [$f->o7b->id => 'sakit']);
    $this->actingAs($f->homeroom);

    expect(AttendanceSession::visibleClassrooms($f->homeroom)->pluck('id')->all())->toBe([$f->c7a->id])
        ->and(AttendanceSession::query()->pluck('id')->all())->toBe([$own->id]);

    Livewire::test(ListAttendanceSessions::class)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$foreign]);

    $this->get("/admin/attendance-sessions/{$own->id}/edit")->assertOk();
    $this->get("/admin/attendance-sessions/{$foreign->id}/edit")->assertNotFound();
    $this->get('/admin/attendance-sessions/create')->assertOk();

    absensiRecord($f->homeroom, $f->c7a, '2026-10-15', [$f->s1->id => 'sakit']);

    expect(absensiStored($f->c7a, '2026-10-15')[$f->s1->id])->toBe('sakit');

    $before = absensiSnapshot();

    expect(fn () => absensiRecord($f->homeroom, $f->c7b, '2026-10-15', [$f->o7b->id => 'alpa']))->toThrow(AuthorizationException::class)
        ->and(absensiSnapshot())->toBe($before);
});

it('AC-6: shows nothing to a wali kelas without a staff row or without a homeroom classroom', function (string $account) {
    $f = $this->f;
    absensiRaw($f->c7a, '2026-10-14', [$f->s1->id => 'sakit']);
    $user = $f->{$account};
    $this->actingAs($user);

    expect(AttendanceSession::visibleClassrooms($user)->count())->toBe(0)
        ->and(AttendanceSession::query()->count())->toBe(0);

    Livewire::test(ListAttendanceSessions::class)->assertCountTableRecords(0);

    expect(fn () => absensiRecord($user, $f->c7a, '2026-10-15'))->toThrow(AuthorizationException::class);
})->with(['idle', 'noStaff']);

it('AC-7: gives kesantrian a read only view', function () {
    $f = $this->f;
    $session = absensiRaw($f->c7a, '2026-10-14', [$f->s1->id => 'alpa']);
    $this->actingAs($f->kes);

    $this->get('/admin/attendance-sessions')->assertOk();
    $this->get('/admin/attendance-recap')->assertOk();
    $this->get('/admin/attendance-sessions/create')->assertForbidden();
    $this->get("/admin/attendance-sessions/{$session->id}/edit")->assertForbidden();

    $before = absensiSnapshot();

    expect(fn () => absensiRecord($f->kes, $f->c7a, '2026-10-15', [$f->s1->id => 'sakit']))->toThrow(AuthorizationException::class)
        ->and(absensiSnapshot())->toBe($before);
});

it('AC-8: limits sessions, classroom options, recap and recording to the unit of the user', function () {
    $f = $this->f;
    $sltp = absensiRaw($f->c7a, '2026-10-14', [$f->s1->id => 'sakit']);
    $slta = absensiRaw($f->c10a, '2026-10-14', [$f->o10->id => 'alpa']);

    $this->actingAs($f->adminSltp);

    expect(AttendanceSession::visibleClassrooms($f->adminSltp)->pluck('id')->sort()->values()->all())->toBe([$f->c7a->id, $f->c7b->id]);
    Livewire::test(ListAttendanceSessions::class)->assertCanSeeTableRecords([$sltp])->assertCanNotSeeTableRecords([$slta]);
    $this->get("/admin/attendance-sessions/{$slta->id}/edit")->assertNotFound();

    $before = absensiSnapshot();

    expect(fn () => absensiRecord($f->adminSltp, $f->c10a, '2026-10-15'))->toThrow(AuthorizationException::class)
        ->and(absensiSnapshot())->toBe($before);

    $this->actingAs($f->kesSltp);

    Livewire::test(ListAttendanceSessions::class)->assertCanSeeTableRecords([$sltp])->assertCanNotSeeTableRecords([$slta]);

    $this->actingAs($f->admin);

    expect(AttendanceSession::visibleClassrooms($f->admin)->count())->toBe(3);
    Livewire::test(ListAttendanceSessions::class)->assertCanSeeTableRecords([$sltp, $slta]);
});

it('AC-6: shows an empty recap when a classroom outside the scope is sent directly', function () {
    $f = $this->f;
    absensiRaw($f->c7a, '2026-10-14', [$f->s1->id => 'alpa']);
    absensiRaw($f->c7b, '2026-10-14', [$f->o7b->id => 'sakit']);
    absensiRaw($f->c10a, '2026-10-14', [$f->o10->id => 'sakit']);

    $this->actingAs($f->homeroom);
    $own = Livewire::test(AttendanceRecap::class)->set('data.classroom_id', $f->c7a->id)->set('data.month', '2026-10');

    expect(absensiRecapRow($own, 'Santri Satu'))->not->toBeNull();

    foreach ([$f->c7b, $f->c10a] as $classroom) {
        $foreign = Livewire::test(AttendanceRecap::class)->set('data.classroom_id', $classroom->id)->set('data.month', '2026-10');

        expect(absensiRecapRow($foreign, 'Santri Tujuh B'))->toBeNull()
            ->and(absensiRecapRow($foreign, 'Santri Sepuluh'))->toBeNull();
    }

    $this->actingAs($f->adminSltp);
    $slta = Livewire::test(AttendanceRecap::class)->set('data.classroom_id', $f->c10a->id)->set('data.month', '2026-10');

    expect(absensiRecapRow($slta, 'Santri Sepuluh'))->toBeNull();
});
