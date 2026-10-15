<?php

use App\Actions\Asrama\SaveRoom;
use App\Models\Cohort;
use App\Models\Dormitory;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\UnitSeeder;

function asramaSaveRoom(Dormitory $dormitory, array $data, ?Room $room = null): Room
{
    return app(SaveRoom::class)->handle($dormitory, [
        'name' => $room?->name ?? 'Kamar 101',
        'floor' => 1,
        'capacity' => $room?->capacity ?? 4,
        'is_active' => $room?->is_active ?? true,
        ...$data,
    ], $room);
}

/**
 * Kamar kapasitas 3 berisi 3 penghuni aktif dari dua jenjang, lalu login sebagai user satu jenjang.
 */
function asramaOccupiedRoom(): Room
{
    test()->seed(UnitSeeder::class);
    $units = Unit::query()->pluck('id', 'code');
    $room = asramaRoom(3);

    foreach (['SD', 'SD', 'SLTP'] as $code) {
        $student = Student::factory()->forCohort(Cohort::factory()->create(['unit_id' => $units[$code]]))->create();
        asramaResident($student, $room);
    }

    $user = User::factory()->create();
    $user->units()->attach($units['SLTP']);
    test()->actingAs($user);

    return $room;
}

it('AC-39: creates a room in the dormitory', function () {
    $dormitory = asramaDormitory();

    $room = asramaSaveRoom($dormitory, ['name' => 'Kamar 202', 'floor' => 2, 'capacity' => 6]);

    expect($room->exists)->toBeTrue()
        ->and($room)->dormitory_id->toBe($dormitory->id)->name->toBe('Kamar 202')->floor->toBe(2)->capacity->toBe(6)
        ->and($room->is_active)->toBeTrue();
});

it('AC-39: rejects saving the room', function (Closure $arrange, string $reason, string $field) {
    [$dormitory, $room, $data] = $arrange();

    asramaAssertRejected(fn () => asramaSaveRoom($dormitory, $data, $room), $reason, $field);

    if ($room) {
        expect($room->fresh())->capacity->toBe(3)->is_active->toBeTrue();
    } else {
        expect(Room::query()->count())->toBe(0);
    }
})->with([
    'capacity zero' => [fn () => [asramaDormitory(), null, ['capacity' => 0]], 'capacity_min', 'capacity'],
    'capacity below occupancy across units' => [function () {
        $room = asramaOccupiedRoom();

        return [$room->dormitory, $room, ['capacity' => 2]];
    }, 'capacity_below_occupancy', 'capacity'],
    'deactivating an occupied room' => [function () {
        $room = asramaOccupiedRoom();

        return [$room->dormitory, $room, ['is_active' => false]];
    }, 'room_has_residents', 'is_active'],
]);

it('AC-39: allows capacity equal to occupancy', function () {
    $room = asramaOccupiedRoom();

    $saved = asramaSaveRoom($room->dormitory, ['capacity' => 3], $room);

    expect($saved->fresh()->capacity)->toBe(3);
});

it('AC-39: deactivates a room without active residents', function () {
    $room = asramaRoom(3);
    RoomAssignment::factory()->ended()->create(['room_id' => $room->id]);

    asramaSaveRoom($room->dormitory, ['is_active' => false], $room);

    expect($room->fresh()->is_active)->toBeFalse();
});
