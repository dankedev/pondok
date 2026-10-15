<?php

use App\Actions\Core\ActivateAcademicYear;
use App\Models\AcademicYear;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Student;

it('AC-14: derives resident status from active room assignments', function () {
    $room = asramaRoom();
    $resident = asramaStudent();
    $never = asramaStudent();
    $left = asramaStudent();
    asramaResident($resident, $room);
    RoomAssignment::factory()->ended()->create(['student_id' => $left->id, 'room_id' => $room->id]);
    RoomAssignment::factory()->ended()->create(['student_id' => $left->id, 'room_id' => $room->id]);

    expect($resident->fresh()->isResident())->toBeTrue()
        ->and($never->fresh()->isResident())->toBeFalse()
        ->and($left->fresh()->isResident())->toBeFalse();
});

it('AC-15: filters resident and nonresident students', function () {
    $room = asramaRoom();
    $resident = asramaStudent();
    $left = asramaStudent();
    $never = asramaStudent();
    asramaResident($resident, $room);
    RoomAssignment::factory()->ended()->create(['student_id' => $left->id, 'room_id' => $room->id]);

    expect(Student::resident()->pluck('id')->all())->toBe([$resident->id])
        ->and(Student::nonResident()->pluck('id')->sort()->values()->all())->toBe(collect([$left->id, $never->id])->sort()->values()->all());
});

it('AC-16: exposes the active room', function () {
    $room = asramaRoom();
    $resident = asramaStudent();
    $left = asramaStudent();
    $never = asramaStudent();
    asramaResident($resident, $room);
    RoomAssignment::factory()->ended()->create(['student_id' => $left->id, 'room_id' => $room->id]);

    expect($resident->fresh()->activeRoom)->toBeInstanceOf(Room::class)
        ->and($resident->fresh()->activeRoom->id)->toBe($room->id)
        ->and($resident->fresh()->activeRoom->dormitory_id)->toBe($room->dormitory_id)
        ->and($left->fresh()->activeRoom)->toBeNull()
        ->and($never->fresh()->activeRoom)->toBeNull();
});

it('AC-17: keeps residency across academic year changes', function () {
    $oldYear = AcademicYear::factory()->active()->create();
    $student = asramaStudent();
    asramaResident($student, asramaRoom(), ['academic_year_id' => $oldYear->id]);

    app(ActivateAcademicYear::class)->handle(AcademicYear::factory()->create());

    expect($student->fresh()->isResident())->toBeTrue()
        ->and(RoomAssignment::query()->whereNull('end_on')->count())->toBe(1);
});
