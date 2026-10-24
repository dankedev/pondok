<?php

use App\Filament\Pages\AttendanceRecap;
use App\Filament\Resources\AttendanceSessions\Pages\CreateAttendanceSession;
use App\Filament\Resources\AttendanceSessions\Pages\EditAttendanceSession;
use App\Models\AcademicYear;
use App\Models\AttendanceSession;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Setting;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = absensiFixture();
});

/*
| VERIFY F025: celah yang dicurigai di luar test RED.
*/

it('saves the current roster or shows a visible error when a student left the roster after the form was loaded', function () {
    $f = $this->f;
    $this->actingAs($f->admin);

    $component = Livewire::test(CreateAttendanceSession::class)
        ->set('data.classroom_id', $f->c7a->id)
        ->set('data.held_on', '2026-10-15')
        ->set("data.statuses.{$f->s1->id}", 'sakit');

    Enrollment::query()->where('student_id', $f->s3->id)->update(['classroom_id' => $f->c7b->id]);

    $component->call('create');

    $saved = AttendanceSession::query()->count() === 1;

    expect($saved || str_contains($component->html(), __('pondok.attendance.errors.student_not_in_roster')))->toBeTrue('galat tidak terlihat dan tidak tersimpan')
        ->and(absensiStored($f->c7a, '2026-10-15')[$f->s3->id] ?? null)->toBeNull();
});

it('forces izin for a locked student even when the form is manipulated to send alpa', function () {
    $f = $this->f;
    absensiLeave($f->s1, 'kembali', [
        'planned_departure' => '2026-10-10 08:00', 'return_due' => '2026-10-13 17:00',
        'departed_at' => '2026-10-10 09:00', 'returned_at' => '2026-10-13 15:00',
    ]);
    $this->actingAs($f->admin);

    Livewire::test(CreateAttendanceSession::class)
        ->set('data.classroom_id', $f->c7a->id)
        ->set('data.held_on', '2026-10-12')
        ->set("data.statuses.{$f->s1->id}", 'alpa')
        ->call('create');

    expect(absensiStored($f->c7a, '2026-10-12')[$f->s1->id] ?? null)->toBe('izin');
});

it('does not accept a classroom of a past academic year or outside the unit scope on the create form', function () {
    $f = $this->f;
    $old = AcademicYear::factory()->create(['name' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
    $oldRoom = Classroom::factory()->create(['academic_year_id' => $old->id, 'unit_id' => $f->sltp->id, 'grade' => 7, 'name' => '7Z']);

    $this->actingAs($f->admin);
    Livewire::test(CreateAttendanceSession::class)
        ->set('data.classroom_id', $oldRoom->id)
        ->set('data.held_on', '2026-03-10')
        ->call('create')
        ->assertHasFormErrors(['classroom_id']);

    $this->actingAs($f->adminSltp);

    try {
        Livewire::test(CreateAttendanceSession::class)
            ->set('data.classroom_id', $f->c10a->id)
            ->set('data.held_on', '2026-10-15')
            ->call('create');
    } catch (Throwable) {
        // 404 juga dianggap menolak.
    }

    expect(AttendanceSession::query()->count())->toBe(0);
});

it('ignores forged classroom and date on the edit form and keeps the session in place', function () {
    $f = $this->f;
    $session = absensiRaw($f->c7a, '2026-10-14', [$f->s1->id => 'hadir', $f->s2->id => 'hadir', $f->s3->id => 'hadir']);
    absensiRaw($f->c7b, '2026-10-13', [$f->o7b->id => 'hadir']);
    $this->actingAs($f->admin);

    Livewire::test(EditAttendanceSession::class, ['record' => $session->id])
        ->set('data.classroom_id', $f->c7b->id)
        ->set('data.held_on', '2026-10-13')
        ->set("data.statuses.{$f->s1->id}", 'sakit')
        ->call('save');

    expect($session->fresh()->classroom_id)->toBe($f->c7a->id)
        ->and($session->fresh()->held_on->toDateString())->toBe('2026-10-14')
        ->and(AttendanceSession::query()->count())->toBe(2)
        ->and(absensiStored($f->c7b, '2026-10-13'))->toBe([$f->o7b->id => 'hadir']);
});

it('removes the access of the former homeroom teacher once the classroom moves to another staff', function () {
    $f = $this->f;
    $session = absensiRaw($f->c7a, '2026-10-14', [$f->s1->id => 'hadir']);
    $f->c7a->update(['homeroom_staff_id' => null]);

    $this->actingAs($f->homeroom);
    $this->get("/admin/attendance-sessions/{$session->id}/edit")->assertNotFound();

    expect(fn () => absensiRecord($f->homeroom, $f->c7a, '2026-10-14', [$f->s1->id => 'alpa']))->toThrow(AuthorizationException::class)
        ->and(absensiStored($f->c7a, '2026-10-14')[$f->s1->id])->toBe('hadir');
});

it('keeps the last actor as recorder when another actor saves the session again', function () {
    $f = $this->f;
    $first = absensiRecord($f->homeroom, $f->c7a, '2026-10-14', [$f->s1->id => 'sakit']);
    $second = absensiRecord($f->admin, $f->c7a, '2026-10-14', [$f->s2->id => 'alpa']);

    expect($second->id)->toBe($first->id)
        ->and($second->fresh()->recorded_by)->toBe($f->admin->id)
        ->and(absensiStored($f->c7a, '2026-10-14'))->toBe([$f->s1->id => 'sakit', $f->s2->id => 'alpa', $f->s3->id => 'hadir']);
});

it('treats the exact midnight boundaries of a leave as the plan says', function () {
    $f = $this->f;
    $leave = absensiLeave($f->s1, 'kembali', [
        'planned_departure' => '2026-10-10 00:00', 'return_due' => '2026-10-13 00:00',
        'departed_at' => '2026-10-10 00:00:00', 'returned_at' => '2026-10-13 00:00:00',
    ]);

    $locked = fn (string $date): bool => absensiRow(absensiSheet($f->c7a, $date), $f->s1)['locked'];

    expect($locked('2026-10-10'))->toBeFalse('berangkat tepat 00:00 di hari itu')
        ->and($locked('2026-10-11'))->toBeTrue('hari tengah')
        ->and($locked('2026-10-12'))->toBeTrue('kembali tepat 00:00 hari berikutnya')
        ->and($locked('2026-10-13'))->toBeFalse('hari kembali');

    expect(absensiRow(absensiSheet($f->c7a, '2026-10-13'), $f->s1)['leave'])->toBe($leave->id);
});

it('keeps locking when two leaves touch the same date and one of them covers it fully', function () {
    $f = $this->f;
    absensiLeave($f->s1, 'keluar', ['planned_departure' => '2026-10-09 08:00', 'return_due' => '2026-10-11 17:00', 'departed_at' => '2026-10-09 09:00']);
    absensiLeave($f->s1, 'disetujui', ['planned_departure' => '2026-10-12 08:00', 'return_due' => '2026-10-13 17:00']);

    $row = absensiRow(absensiSheet($f->c7a, '2026-10-12'), $f->s1);

    expect($row['locked'])->toBeTrue()->and($row['status'])->toBe('izin');

    absensiRecord($f->admin, $f->c7a, '2026-10-12', [$f->s1->id => 'hadir']);

    expect(absensiStored($f->c7a, '2026-10-12')[$f->s1->id])->toBe('izin');
});

it('falls back to the current month for an invalid recap month instead of failing', function () {
    $f = $this->f;
    absensiRaw($f->c7a, '2026-10-14', [$f->s1->id => 'alpa']);
    $this->actingAs($f->admin);

    foreach (['zzz', '2026-13', '2026-10-01', ''] as $month) {
        $recap = Livewire::test(AttendanceRecap::class)->set('data.classroom_id', $f->c7a->id)->set('data.month', $month);

        expect(absensiRecapRow($recap, 'Santri Satu'))->toBe([0, 0, 0, 1], "bulan: '{$month}'");
    }
});

it('does not list a former student of the classroom in the roster after graduation but keeps the recap row', function () {
    $f = $this->f;
    absensiRecord($f->admin, $f->c7a, '2026-10-14', [$f->s2->id => 'alpa']);
    $f->s2->update(['status' => 'lulus']);

    $recap = Livewire::test(AttendanceRecap::class)->set('data.classroom_id', $f->c7a->id)->set('data.month', '2026-10');

    expect(absensiRow(absensiSheet($f->c7a, '2026-10-14'), $f->s2))->toBeNull()
        ->and(absensiRecapRow($recap, 'Santri Dua'))->toBe([0, 0, 0, 1]);
});

it('does not leak a switched off absensi module through the recap route even for super admin', function () {
    $f = $this->f;
    Setting::set('modules.absensi.enabled', false);
    $this->actingAs(asramaUser('super-admin'));

    $this->get('/admin/attendance-recap')->assertForbidden();
    $this->get('/admin/attendance-sessions/create')->assertForbidden();
});
