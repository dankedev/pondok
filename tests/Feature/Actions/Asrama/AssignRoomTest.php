<?php

use App\Actions\Asrama\AssignRoom;
use App\Models\Cohort;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\UnitSeeder;

function asramaAssign(Student $student, Room $room, ?string $startOn = null, ?string $note = null): RoomAssignment
{
    return app(AssignRoom::class)->handle($student, $room, $startOn ? Carbon::parse($startOn) : null, $note);
}

it('AC-1: places an active student in a room of the matching dormitory', function () {
    $year = asramaActiveYear();
    $student = asramaStudent('L');
    $room = asramaRoom(2, 'L');

    $assignment = asramaAssign($student, $room);

    expect($assignment)
        ->student_id->toBe($student->id)
        ->room_id->toBe($room->id)
        ->academic_year_id->toBe($year->id)
        ->end_on->toBeNull()
        ->and($assignment->start_on->toDateString())->toBe(today()->toDateString())
        ->and(RoomAssignment::query()->count())->toBe(1);
});

it('AC-4: ignores ended assignments when checking capacity', function () {
    asramaActiveYear();
    $room = asramaRoom(1);
    RoomAssignment::factory()->ended()->create(['room_id' => $room->id]);

    $assignment = asramaAssign(asramaStudent('L'), $room);

    expect($assignment->exists)->toBeTrue()
        ->and(RoomAssignment::query()->whereNull('end_on')->count())->toBe(1);
});

it('AC-7: returns the active assignment when assigned to the same room again', function (bool $roomIsFull) {
    asramaActiveYear();
    $student = asramaStudent('L');
    $room = asramaRoom($roomIsFull ? 1 : 5);

    $first = asramaAssign($student, $room);
    $second = asramaAssign($student, $room);

    expect($second->id)->toBe($first->id)
        ->and($second->end_on)->toBeNull()
        ->and(RoomAssignment::query()->count())->toBe(1);
})->with(['room with space' => false, 'full room' => true]);

it('AC-2, AC-3, AC-6: rejects the assignment', function (Closure $arrange, string $reason) {
    [$student, $room] = $arrange();

    asramaAssertRejected(fn () => asramaAssign($student, $room), $reason, 'room_id');

    expect(RoomAssignment::query()->where('student_id', $student->id)->exists())->toBeFalse();
})->with([
    'other gender' => [function () {
        asramaActiveYear();

        return [asramaStudent('P'), asramaRoom(5, 'L')];
    }, 'gender_mismatch'],
    'full room' => [function () {
        asramaActiveYear();
        $room = asramaRoom(1);
        asramaResident(asramaStudent('L'), $room);

        return [asramaStudent('L'), $room];
    }, 'room_full'],
    'inactive student' => [function () {
        asramaActiveYear();

        return [asramaStudent('L', ['status' => 'lulus']), asramaRoom()];
    }, 'student_inactive'],
    'inactive room' => [function () {
        asramaActiveYear();

        return [asramaStudent('L'), asramaRoom(5, 'L', ['is_active' => false])];
    }, 'room_inactive'],
    'inactive dormitory' => [function () {
        asramaActiveYear();

        return [asramaStudent('L'), asramaRoom(5, 'L', [], asramaDormitory('L', ['is_active' => false]))];
    }, 'dormitory_inactive'],
    'no active academic year' => [fn () => [asramaStudent('L'), asramaRoom()], 'no_active_academic_year'],
]);

it('AC-5: counts occupants from every unit when checking capacity', function () {
    $this->seed(UnitSeeder::class);
    asramaActiveYear();
    $units = Unit::query()->pluck('id', 'code');
    $sdStudent = Student::factory()->forCohort(Cohort::factory()->create(['unit_id' => $units['SD']]))->create(['gender' => 'L']);
    $sltpStudent = Student::factory()->forCohort(Cohort::factory()->create(['unit_id' => $units['SLTP']]))->create(['gender' => 'L']);
    $room = asramaRoom(1);
    asramaResident($sdStudent, $room);

    $user = User::factory()->create();
    $user->units()->attach($units['SLTP']);
    $this->actingAs($user);

    asramaAssertRejected(fn () => asramaAssign($sltpStudent, $room), 'room_full', 'room_id');

    expect(RoomAssignment::withoutUnitScope()->where('room_id', $room->id)->count())->toBe(1);
});

it('AC-8: closes the old assignment and adds a new row when moving rooms', function () {
    asramaActiveYear();
    $student = asramaStudent('L');
    $roomA = asramaRoom(5, 'L');
    $roomB = asramaRoom(5, 'L');

    $old = asramaAssign($student, $roomA, '2026-07-15');
    $new = asramaAssign($student, $roomB, '2026-10-01', 'Pindah lantai');

    expect($old->fresh())
        ->room_id->toBe($roomA->id)
        ->and($old->fresh()->end_on->toDateString())->toBe('2026-10-01')
        ->and($new)
        ->room_id->toBe($roomB->id)
        ->end_on->toBeNull()
        ->note->toBe('Pindah lantai')
        ->and($new->start_on->toDateString())->toBe('2026-10-01')
        ->and(RoomAssignment::query()->where('student_id', $student->id)->count())->toBe(2);
});

it('AC-9: keeps the current room when the target room is full', function () {
    asramaActiveYear();
    $student = asramaStudent('L');
    $roomA = asramaRoom(5, 'L');
    $roomB = asramaRoom(1, 'L');
    asramaResident(asramaStudent('L'), $roomB);
    $old = asramaAssign($student, $roomA, '2026-07-15');

    asramaAssertRejected(fn () => asramaAssign($student, $roomB, '2026-10-01'), 'room_full', 'room_id');

    expect($old->fresh()->end_on)->toBeNull()
        ->and(RoomAssignment::query()->where('student_id', $student->id)->count())->toBe(1);
});

it('AC-10: rejects a move dated before the current assignment', function () {
    asramaActiveYear();
    $student = asramaStudent('L');
    $roomA = asramaRoom(5, 'L');
    $roomB = asramaRoom(5, 'L');
    $old = asramaAssign($student, $roomA, '2026-07-15');

    asramaAssertRejected(fn () => asramaAssign($student, $roomB, '2026-07-01'), 'start_before_current', 'room_id');

    expect($old->fresh())
        ->end_on->toBeNull()
        ->room_id->toBe($roomA->id)
        ->and(RoomAssignment::query()->where('student_id', $student->id)->count())->toBe(1);
});

it('AC-11: keeps exactly one active assignment after several moves', function () {
    asramaActiveYear();
    $student = asramaStudent('L');
    [$roomA, $roomB, $roomC] = [asramaRoom(5, 'L'), asramaRoom(5, 'L'), asramaRoom(5, 'L')];

    asramaAssign($student, $roomA, '2026-07-15');
    asramaAssign($student, $roomB, '2026-08-15');
    asramaAssign($student, $roomC, '2026-09-15');

    $assignments = RoomAssignment::query()->where('student_id', $student->id)->get();

    expect($assignments)->toHaveCount(3)
        ->and($assignments->whereNull('end_on'))->toHaveCount(1)
        ->and($assignments->whereNull('end_on')->first()->room_id)->toBe($roomC->id);
});

it('re-checks the student status on the locked row before placing', function () {
    asramaActiveYear();
    $student = asramaStudent('L');
    $room = asramaRoom(5, 'L');
    $staleCopy = Student::query()->findOrFail($student->id);

    $student->forceFill(['status' => 'keluar'])->save();

    asramaAssertRejected(fn () => asramaAssign($staleCopy, $room), 'student_inactive', 'room_id');

    expect(RoomAssignment::query()->count())->toBe(0);
});
