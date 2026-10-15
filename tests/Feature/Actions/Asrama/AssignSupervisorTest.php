<?php

use App\Actions\Asrama\AssignSupervisor;
use App\Models\AcademicYear;
use App\Models\Dormitory;
use App\Models\DormitorySupervisor;
use App\Models\Room;
use App\Models\Staff;
use Carbon\Carbon;

function asramaSupervise(Dormitory $dormitory, Staff $staff, string $role, AcademicYear $year, ?Room $room = null, ?string $startOn = null): DormitorySupervisor
{
    return app(AssignSupervisor::class)->handle($dormitory, $staff, $role, $year, $room, $startOn ? Carbon::parse($startOn) : null);
}

it('AC-18: assigns a supervisor to a whole dormitory or a room', function () {
    $year = AcademicYear::factory()->active()->create();
    $dormitory = asramaDormitory();
    $room = asramaRoom(5, 'L', [], $dormitory);
    $staff = Staff::factory()->create();

    $whole = asramaSupervise($dormitory, $staff, DormitorySupervisor::ROLE_SUPERVISOR, $year);
    $perRoom = asramaSupervise($dormitory, $staff, DormitorySupervisor::ROLE_SUPERVISOR, $year, $room);

    expect($whole)
        ->dormitory_id->toBe($dormitory->id)
        ->staff_id->toBe($staff->id)
        ->room_id->toBeNull()
        ->role->toBe('musyrif')
        ->academic_year_id->toBe($year->id)
        ->end_on->toBeNull()
        ->and($perRoom->room_id)->toBe($room->id);
});

it('AC-19: rejects the supervisor assignment', function (Closure $arrange, string $reason) {
    $year = AcademicYear::factory()->active()->create();
    [$dormitory, $staff, $role, $room] = $arrange();

    asramaAssertRejected(fn () => asramaSupervise($dormitory, $staff, $role, $year, $room), $reason);

    expect(DormitorySupervisor::query()->count())->toBe(0);
})->with([
    'room of another dormitory' => [fn () => [asramaDormitory(), Staff::factory()->create(), 'musyrif', asramaRoom()], 'room_dormitory_mismatch'],
    'inactive staff' => [fn () => [asramaDormitory(), Staff::factory()->create(['is_active' => false]), 'musyrif', null], 'staff_inactive'],
    'head with a room' => [function () {
        $dormitory = asramaDormitory();

        return [$dormitory, Staff::factory()->create(), 'kepala_asrama', asramaRoom(5, 'L', [], $dormitory)];
    }, 'head_requires_dormitory'],
    'unknown role' => [fn () => [asramaDormitory(), Staff::factory()->create(), 'satpam', null], 'invalid_role'],
]);

it('AC-20: returns the existing active assignment', function () {
    $year = AcademicYear::factory()->active()->create();
    $dormitory = asramaDormitory();
    $room = asramaRoom(5, 'L', [], $dormitory);
    $staff = Staff::factory()->create();

    foreach ([null, $room] as $scope) {
        $first = asramaSupervise($dormitory, $staff, 'musyrif', $year, $scope);
        $second = asramaSupervise($dormitory, $staff, 'musyrif', $year, $scope);

        expect($second->id)->toBe($first->id);
    }

    expect(DormitorySupervisor::query()->count())->toBe(2);
});

it('AC-21: ends the previous head when a new head is assigned', function () {
    $year = AcademicYear::factory()->active()->create();
    $dormitory = asramaDormitory();
    $old = asramaSupervise($dormitory, Staff::factory()->create(), 'kepala_asrama', $year, null, '2026-07-15');

    $new = asramaSupervise($dormitory, Staff::factory()->create(), 'kepala_asrama', $year, null, '2026-09-01');

    expect($old->fresh()->end_on->toDateString())->toBe('2026-09-01')
        ->and($new->end_on)->toBeNull()
        ->and(DormitorySupervisor::query()->count())->toBe(2);
});

it('AC-21: rejects a new head dated before the current head', function () {
    $year = AcademicYear::factory()->active()->create();
    $dormitory = asramaDormitory();
    $old = asramaSupervise($dormitory, Staff::factory()->create(), 'kepala_asrama', $year, null, '2026-08-01');

    asramaAssertRejected(
        fn () => asramaSupervise($dormitory, Staff::factory()->create(), 'kepala_asrama', $year, null, '2026-07-20'),
        'start_before_current_head',
    );

    expect($old->fresh()->end_on)->toBeNull()
        ->and(DormitorySupervisor::query()->count())->toBe(1);
});

it('AC-21: keeps several musyrif active in one dormitory', function () {
    $year = AcademicYear::factory()->active()->create();
    $dormitory = asramaDormitory();

    asramaSupervise($dormitory, Staff::factory()->create(), 'musyrif', $year);
    asramaSupervise($dormitory, Staff::factory()->create(), 'musyrif', $year);

    expect(DormitorySupervisor::query()->whereNull('end_on')->count())->toBe(2);
});

it('does not end a head of another dormitory or another academic year', function () {
    $year = AcademicYear::factory()->active()->create();
    $otherYear = AcademicYear::factory()->create();
    $dormitory = asramaDormitory();
    $headOtherDormitory = asramaSupervise(asramaDormitory(), Staff::factory()->create(), 'kepala_asrama', $year);
    $headOtherYear = asramaSupervise($dormitory, Staff::factory()->create(), 'kepala_asrama', $otherYear);

    asramaSupervise($dormitory, Staff::factory()->create(), 'kepala_asrama', $year);

    expect($headOtherDormitory->fresh()->end_on)->toBeNull()
        ->and($headOtherYear->fresh()->end_on)->toBeNull();
});
