<?php

use App\Models\Dormitory;
use App\Models\DormitorySupervisor;
use App\Models\Room;
use App\Models\RoomAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('AC-24: rejects a duplicate room name in one dormitory', function () {
    $dormitory = asramaDormitory();
    Room::factory()->create(['dormitory_id' => $dormitory->id, 'name' => 'Kamar 101']);

    Room::factory()->create(['dormitory_id' => $dormitory->id, 'name' => 'Kamar 101']);
})->throws(UniqueConstraintViolationException::class);

it('AC-24: allows the same room name in another dormitory', function () {
    Room::factory()->create(['dormitory_id' => asramaDormitory()->id, 'name' => 'Kamar 101']);
    Room::factory()->create(['dormitory_id' => asramaDormitory()->id, 'name' => 'Kamar 101']);

    expect(Room::query()->where('name', 'Kamar 101')->count())->toBe(2);
});

it('AC-24: rejects a duplicate dormitory code', function () {
    Dormitory::factory()->create(['code' => 'ABK-1']);

    Dormitory::factory()->create(['code' => 'ABK-1']);
})->throws(UniqueConstraintViolationException::class);

it('AC-25: restricts deleting a dormitory with rooms', function () {
    $room = asramaRoom();

    expect(fn () => $room->dormitory->delete())->toThrow(QueryException::class)
        ->and(Dormitory::query()->whereKey($room->dormitory_id)->exists())->toBeTrue();
});

it('AC-25: restricts deleting a dormitory or room with supervisor history', function () {
    $dormitory = asramaDormitory();
    DormitorySupervisor::factory()->ended()->create(['dormitory_id' => $dormitory->id, 'room_id' => null]);

    expect(fn () => $dormitory->delete())->toThrow(QueryException::class)
        ->and(Dormitory::query()->whereKey($dormitory->id)->exists())->toBeTrue();

    $room = asramaRoom();
    DormitorySupervisor::factory()->ended()->forRoom($room)->create();

    expect(fn () => $room->delete())->toThrow(QueryException::class)
        ->and(Room::query()->whereKey($room->id)->exists())->toBeTrue();
});

it('AC-25: restricts deleting a room with assignment history', function () {
    $room = asramaRoom();
    RoomAssignment::factory()->ended()->create(['room_id' => $room->id]);

    expect(fn () => $room->delete())->toThrow(QueryException::class)
        ->and(Room::query()->whereKey($room->id)->exists())->toBeTrue();
});

it('AC-38: rejects a second active assignment for one student', function () {
    $student = asramaStudent();
    $assignment = asramaResident($student, asramaRoom());

    RoomAssignment::query()->create([
        'student_id' => $student->id,
        'room_id' => asramaRoom()->id,
        'academic_year_id' => $assignment->academic_year_id,
        'start_on' => '2026-08-01',
    ]);
})->throws(UniqueConstraintViolationException::class);

it('AC-38: allows many ended assignments next to one active assignment', function () {
    $student = asramaStudent();
    $room = asramaRoom();
    RoomAssignment::factory()->ended()->count(2)->create(['student_id' => $student->id, 'room_id' => $room->id]);
    asramaResident($student, $room);

    expect(RoomAssignment::query()->where('student_id', $student->id)->count())->toBe(3)
        ->and(RoomAssignment::query()->where('student_id', $student->id)->whereNull('end_on')->count())->toBe(1);
});

it('AC-38: frees the active slot when an assignment ends', function () {
    $student = asramaStudent();
    $first = asramaResident($student, asramaRoom());

    expect(DB::table('room_assignments')->where('id', $first->id)->value('active_marker'))->toBe(1);

    $first->update(['end_on' => '2026-10-01']);

    expect(DB::table('room_assignments')->where('id', $first->id)->value('active_marker'))->toBeNull();

    $second = asramaResident($student, asramaRoom(), ['start_on' => '2026-10-01']);

    expect($second->exists)->toBeTrue()
        ->and(RoomAssignment::query()->where('student_id', $student->id)->whereNull('end_on')->count())->toBe(1);
});
