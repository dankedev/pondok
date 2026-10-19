<?php

use App\Actions\Asrama\ImportRoomAssignmentRow;
use App\Models\Dormitory;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\UnitSeeder;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(UnitSeeder::class);
    $this->actingAs(User::factory()->create());
    asramaActiveYear();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function penghuniRow(array $overrides = []): array
{
    return ['nis' => '2026001', 'dormitory' => 'Asrama Abu Bakar', 'room' => 'Kamar 101', 'start_on' => '2026-07-15', ...$overrides];
}

/**
 * Siapkan santri, asrama, dan kamar yang cocok dengan penghuniRow() bawaan.
 *
 * @return array{student: Student, dormitory: Dormitory, room: Room}
 */
function penghuniSetup(int $capacity = 5): array
{
    $dormitory = asramaDormitory('L', ['name' => 'Asrama Abu Bakar']);

    return [
        'student' => asramaStudent('L', ['nis' => '2026001']),
        'dormitory' => $dormitory,
        'room' => asramaRoom($capacity, 'L', ['name' => 'Kamar 101'], $dormitory),
    ];
}

function penghuniImportFails(array $overrides = []): void
{
    try {
        app(ImportRoomAssignmentRow::class)->handle(penghuniRow($overrides));
    } catch (ValidationException|RowImportFailedException $exception) {
        $message = $exception instanceof ValidationException
            ? collect($exception->errors())->flatten()->implode(' ')
            : $exception->getMessage();

        expect($message)->not->toBe('')->and($message)->not->toContain('pondok.');

        return;
    }

    test()->fail('Baris seharusnya gagal, tetapi berhasil diimpor.');
}

it('AC-3: places the student matching dormitory and room names case-insensitively with surrounding spaces', function () {
    ['student' => $student, 'room' => $room] = penghuniSetup();

    $assignment = app(ImportRoomAssignmentRow::class)->handle(penghuniRow([
        'dormitory' => '  asrama ABU bakar ', 'room' => 'kamar 101 ', 'start_on' => '2026-08-01',
    ]));

    expect($assignment)->toBeInstanceOf(RoomAssignment::class)
        ->student_id->toBe($student->id)
        ->room_id->toBe($room->id)
        ->end_on->toBeNull()
        ->and($assignment->start_on->toDateString())->toBe('2026-08-01');
});

it('AC-3: uses today when start_on is empty', function (?string $empty) {
    penghuniSetup();

    $assignment = app(ImportRoomAssignmentRow::class)->handle(penghuniRow(['start_on' => $empty]));

    expect($assignment->start_on->toDateString())->toBe(today()->toDateString());
})->with(['null' => [null], 'empty string' => ['']]);

it('AC-6: picks the room inside the named dormitory when another dormitory has a room with the same name', function () {
    ['student' => $student, 'room' => $room] = penghuniSetup();
    asramaRoom(5, 'L', ['name' => 'Kamar 101']);

    $assignment = app(ImportRoomAssignmentRow::class)->handle(penghuniRow());

    expect($assignment->room_id)->toBe($room->id)->and($assignment->student_id)->toBe($student->id);
});

it('AC-6: fails when the room exists only in another dormitory and creates nothing', function () {
    penghuniSetup();
    asramaRoom(5, 'L', ['name' => 'Kamar 202']);

    penghuniImportFails(['room' => 'Kamar 202']);

    expect(RoomAssignment::query()->count())->toBe(0)->and(Room::query()->count())->toBe(2);
});

it('AC-6: fails for an unknown dormitory, unknown room, or unknown NIS without creating anything', function (array $override) {
    penghuniSetup();
    $dormitories = Dormitory::query()->count();
    $rooms = Room::query()->count();

    penghuniImportFails($override);

    expect(RoomAssignment::query()->count())->toBe(0)
        ->and(Dormitory::query()->count())->toBe($dormitories)
        ->and(Room::query()->count())->toBe($rooms);
})->with([
    'unknown dormitory' => [['dormitory' => 'Asrama Tidak Ada']],
    'unknown room' => [['room' => 'Kamar 999']],
    'unknown NIS' => [['nis' => '9999999']],
]);

it('AC-6: fails when two dormitories share the same name instead of picking one', function () {
    ['dormitory' => $first] = penghuniSetup();
    $twin = asramaDormitory('L', ['name' => 'asrama abu bakar']);
    asramaRoom(5, 'L', ['name' => 'Kamar 101'], $twin);

    penghuniImportFails();

    expect(RoomAssignment::query()->count())->toBe(0)->and($first->exists)->toBeTrue();
});

it('AC-7: is a no-op returning the active assignment, even when the room is full by the student itself and start_on differs', function () {
    ['student' => $student, 'room' => $room] = penghuniSetup(1);

    $first = app(ImportRoomAssignmentRow::class)->handle(penghuniRow(['start_on' => '2026-07-15']));
    $again = app(ImportRoomAssignmentRow::class)->handle(penghuniRow(['start_on' => '2026-09-01']));

    expect($again->id)->toBe($first->id)
        ->and(RoomAssignment::query()->where('student_id', $student->id)->count())->toBe(1)
        ->and($again->fresh()->start_on->toDateString())->toBe('2026-07-15')
        ->and($room->occupiedCount())->toBe(1);
});

it('AC-4: moves a resident to another room and keeps history', function () {
    ['student' => $student, 'dormitory' => $dormitory] = penghuniSetup();
    $old = asramaRoom(5, 'L', ['name' => 'Kamar 202'], $dormitory);
    asramaResident($student, $old, ['start_on' => '2026-07-15']);

    app(ImportRoomAssignmentRow::class)->handle(penghuniRow(['start_on' => '2026-08-10']));

    $rows = RoomAssignment::query()->where('student_id', $student->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->end_on->toDateString())->toBe('2026-08-10')
        ->and($rows[1]->end_on)->toBeNull();
});

it('AC-4: fails when start_on is before the current placement and leaves data unchanged', function () {
    ['student' => $student, 'dormitory' => $dormitory] = penghuniSetup();
    $old = asramaRoom(5, 'L', ['name' => 'Kamar 202'], $dormitory);
    $current = asramaResident($student, $old, ['start_on' => '2026-07-15']);

    penghuniImportFails(['start_on' => '2026-07-01']);

    expect(RoomAssignment::query()->count())->toBe(1)->and($current->fresh()->end_on)->toBeNull();
});

it('AC-5 and AC-8: fails with the reason from AssignRoom for full rooms, gender mismatch, inactive student, room, dormitory, and soft-deleted student', function (Closure $arrange) {
    $setup = penghuniSetup(1);
    $arrange($setup);

    penghuniImportFails();

    expect(RoomAssignment::query()->where('student_id', $setup['student']->id)->count())->toBe(0);
})->with([
    'room full' => [fn (array $s) => asramaResident(asramaStudent('L'), $s['room'])],
    'gender mismatch' => [fn (array $s) => $s['dormitory']->update(['gender' => 'P'])],
    'student left' => [fn (array $s) => $s['student']->update(['status' => 'keluar'])],
    'room inactive' => [fn (array $s) => $s['room']->update(['is_active' => false])],
    'dormitory inactive' => [fn (array $s) => $s['dormitory']->update(['is_active' => false])],
    'student soft-deleted' => [fn (array $s) => $s['student']->delete()],
]);
