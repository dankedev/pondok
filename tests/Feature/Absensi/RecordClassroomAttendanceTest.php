<?php

use App\Models\AttendanceSession;
use App\Models\Enrollment;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->f = absensiFixture();
});

it('AC-3: records a new session for the active roster only', function () {
    $f = $this->f;

    $session = absensiRecord($f->admin, $f->c7a, '2026-10-15', [$f->s1->id => 'sakit']);

    expect(AttendanceSession::query()->count())->toBe(1)
        ->and($session->recorded_by)->toBe($f->admin->id)
        ->and($session->classroom_id)->toBe($f->c7a->id)
        ->and(absensiStored($f->c7a, '2026-10-15'))->toBe([
            $f->s1->id => 'sakit',
            $f->s2->id => 'hadir',
            $f->s3->id => 'hadir',
        ])
        ->and(DB::table('attendance_records')->whereIn('student_id', [$f->grad->id, $f->o7b->id, $f->o10->id])->count())->toBe(0);
});

it('AC-4: rerunning the same session updates without duplicates and keeps unsent and moved students', function () {
    $f = $this->f;

    absensiRecord($f->admin, $f->c7a, '2026-10-15', [$f->s1->id => 'sakit']);
    absensiRecord($f->admin, $f->c7a, '2026-10-15', [$f->s2->id => 'alpa', $f->s3->id => 'alpa']);

    expect(absensiStored($f->c7a, '2026-10-15'))->toBe([$f->s1->id => 'sakit', $f->s2->id => 'alpa', $f->s3->id => 'alpa']);

    $newcomer = absensiStudent($f, $f->c7a, 'Santri Baru');
    Enrollment::query()->where('student_id', $f->s3->id)->update(['classroom_id' => $f->c7b->id]);

    absensiRecord($f->admin, $f->c7a, '2026-10-15', [$f->s2->id => 'hadir']);

    expect(AttendanceSession::query()->where('classroom_id', $f->c7a->id)->count())->toBe(1)
        ->and(absensiStored($f->c7a, '2026-10-15'))->toBe([
            $f->s1->id => 'sakit',
            $f->s2->id => 'hadir',
            $f->s3->id => 'alpa',
            $newcomer->id => 'hadir',
        ]);

    absensiRecord($f->admin, $f->c7b, '2026-10-15');

    expect(AttendanceSession::query()->count())->toBe(2);
});

it('AC-5: rejects an invalid :case without creating or changing anything', function (string $case) {
    $f = $this->f;
    absensiRecord($f->admin, $f->c7a, '2026-10-14', [$f->s1->id => 'sakit']);
    $before = absensiSnapshot();

    [$date, $statuses] = match ($case) {
        'tanggal besok' => ['2026-10-16', [$f->s1->id => 'hadir']],
        'tanggal sebelum tahun ajaran' => ['2026-06-30', [$f->s1->id => 'hadir']],
        'santri di luar roster' => ['2026-10-14', [$f->s2->id => 'alpa', $f->grad->id => 'sakit']],
        'status tidak dikenal' => ['2026-10-14', [$f->s2->id => 'alpa', $f->s3->id => 'terlambat']],
    };

    expect(fn () => absensiRecord($f->admin, $f->c7a, $date, $statuses))->toThrow(ValidationException::class)
        ->and(absensiSnapshot())->toBe($before);
})->with(['tanggal besok', 'tanggal sebelum tahun ajaran', 'santri di luar roster', 'status tidak dikenal']);

it('AC-9: locks izin only for days fully inside a completed leave, whatever the input and actor', function () {
    $f = $this->f;
    absensiLeave($f->s1, 'kembali', [
        'planned_departure' => '2026-10-10 08:00', 'return_due' => '2026-10-13 17:00',
        'departed_at' => '2026-10-10 09:00', 'returned_at' => '2026-10-13 15:00',
    ]);

    foreach ([['2026-10-11', $f->admin, 'hadir'], ['2026-10-12', $f->homeroom, 'alpa']] as [$date, $actor, $input]) {
        $row = absensiRow(absensiSheet($f->c7a, $date), $f->s1);

        expect($row['locked'])->toBeTrue($date)->and($row['status'])->toBe('izin', $date);

        absensiRecord($actor, $f->c7a, $date, [$f->s1->id => $input, $f->s2->id => 'sakit']);

        expect(absensiStored($f->c7a, $date))->toBe([$f->s1->id => 'izin', $f->s2->id => 'sakit', $f->s3->id => 'hadir'], $date);
    }
});

it('AC-9: keeps the boundary days and a same-day leave editable with the leave as a hint', function () {
    $f = $this->f;
    absensiLeave($f->s1, 'kembali', [
        'planned_departure' => '2026-10-10 08:00', 'return_due' => '2026-10-13 17:00',
        'departed_at' => '2026-10-10 09:00', 'returned_at' => '2026-10-13 15:00',
    ]);
    $sameDay = absensiLeave($f->s2, 'kembali', [
        'planned_departure' => '2026-10-11 08:00', 'return_due' => '2026-10-11 17:00',
        'departed_at' => '2026-10-11 08:30', 'returned_at' => '2026-10-11 16:30',
    ]);

    foreach (['2026-10-10', '2026-10-13'] as $date) {
        $row = absensiRow(absensiSheet($f->c7a, $date), $f->s1);

        expect($row['locked'])->toBeFalse($date)->and($row['status'])->toBe('hadir', $date)->and($row['leave'])->not->toBeNull($date);

        absensiRecord($f->admin, $f->c7a, $date, [$f->s1->id => 'alpa']);

        expect(absensiStored($f->c7a, $date)[$f->s1->id])->toBe('alpa', $date);
    }

    $row = absensiRow(absensiSheet($f->c7a, '2026-10-11'), $f->s2);

    expect($row['locked'])->toBeFalse()->and($row['leave'])->toBe($sameDay->id);

    absensiRecord($f->admin, $f->c7a, '2026-10-11', [$f->s2->id => 'sakit']);

    expect(absensiStored($f->c7a, '2026-10-11')[$f->s2->id])->toBe('sakit');
});

it('AC-9: does not lock an approved leave that has not departed yet, but shows it as a hint', function () {
    $f = $this->f;
    $leave = absensiLeave($f->s1, 'disetujui', ['planned_departure' => '2026-10-09 08:00', 'return_due' => '2026-10-14 17:00']);

    $row = absensiRow(absensiSheet($f->c7a, '2026-10-12'), $f->s1);

    expect($row['locked'])->toBeFalse()->and($row['leave'])->toBe($leave->id)->and($row['status'])->toBe('hadir');

    absensiRecord($f->admin, $f->c7a, '2026-10-12', [$f->s1->id => 'alpa']);

    expect(absensiStored($f->c7a, '2026-10-12')[$f->s1->id])->toBe('alpa');
});

it('AC-9: keeps locking izin while the student is still out past the return deadline', function () {
    $f = $this->f;
    absensiLeave($f->s1, 'keluar', ['planned_departure' => '2026-10-10 08:00', 'return_due' => '2026-10-12 17:00', 'departed_at' => '2026-10-10 09:00']);

    $locked = absensiRow(absensiSheet($f->c7a, '2026-10-14'), $f->s1);
    $departureDay = absensiRow(absensiSheet($f->c7a, '2026-10-10'), $f->s1);

    expect($locked['locked'])->toBeTrue()->and($locked['status'])->toBe('izin')
        ->and($departureDay['locked'])->toBeFalse();

    absensiRecord($f->admin, $f->c7a, '2026-10-14', [$f->s1->id => 'hadir']);

    expect(absensiStored($f->c7a, '2026-10-14')[$f->s1->id])->toBe('izin');
});

it('AC-9: ignores leaves that are not approved and everything when perizinan is off', function () {
    $f = $this->f;

    foreach (['diajukan', 'ditolak', 'batal'] as $status) {
        absensiLeave($f->s1, $status, ['planned_departure' => '2026-10-09 08:00', 'return_due' => '2026-10-14 17:00']);
    }

    $row = absensiRow(absensiSheet($f->c7a, '2026-10-12'), $f->s1);

    expect($row['locked'])->toBeFalse()->and($row['leave'])->toBeNull();

    absensiLeave($f->s2, 'kembali', [
        'planned_departure' => '2026-10-10 08:00', 'return_due' => '2026-10-13 17:00',
        'departed_at' => '2026-10-10 09:00', 'returned_at' => '2026-10-13 15:00',
    ]);
    Setting::set('modules.perizinan.enabled', false);

    $off = absensiRow(absensiSheet($f->c7a, '2026-10-12'), $f->s2);

    expect($off['locked'])->toBeFalse()->and($off['leave'])->toBeNull();

    absensiRecord($f->admin, $f->c7a, '2026-10-12', [$f->s1->id => 'alpa', $f->s2->id => 'hadir']);

    expect(absensiStored($f->c7a, '2026-10-12'))->toBe([$f->s1->id => 'alpa', $f->s2->id => 'hadir', $f->s3->id => 'hadir']);
});

it('AC-4: survives a concurrent insert of the same session by retrying once', function () {
    $f = $this->f;
    $raced = false;

    AttendanceSession::creating(function (AttendanceSession $session) use (&$raced, $f): void {
        if ($raced) {
            return;
        }

        $raced = true;
        DB::table('attendance_sessions')->insert([
            'classroom_id' => $f->c7a->id, 'held_on' => '2026-10-15', 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    absensiRecord($f->admin, $f->c7a, '2026-10-15', [$f->s1->id => 'sakit']);

    expect($raced)->toBeTrue()
        ->and(AttendanceSession::query()->count())->toBe(1)
        ->and(absensiStored($f->c7a, '2026-10-15'))->toBe([$f->s1->id => 'sakit', $f->s2->id => 'hadir', $f->s3->id => 'hadir']);
});
