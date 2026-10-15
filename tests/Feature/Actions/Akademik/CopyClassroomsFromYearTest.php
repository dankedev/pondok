<?php

use App\Actions\Akademik\CopyClassroomsFromYear;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Staff;
use App\Models\Unit;

it('copies classrooms to the new year without the homeroom teacher', function () {
    $from = AcademicYear::factory()->create();
    $to = AcademicYear::factory()->create();
    Classroom::factory()->create([
        'academic_year_id' => $from->id,
        'name' => '1A',
        'gender' => 'P',
        'capacity' => 30,
        'homeroom_staff_id' => Staff::factory(),
    ]);

    $created = app(CopyClassroomsFromYear::class)->handle($from, $to);

    expect($created)->toBe(1)
        ->and(Classroom::query()->where('academic_year_id', $to->id)->sole())
        ->name->toBe('1A')
        ->gender->toBe('P')
        ->capacity->toBe(30)
        ->homeroom_staff_id->toBeNull();
});

it('skips classrooms that already exist in the target year', function () {
    $from = AcademicYear::factory()->create();
    $to = AcademicYear::factory()->create();
    $classroom = Classroom::factory()->create(['academic_year_id' => $from->id, 'name' => '1A']);
    Classroom::factory()->create(['academic_year_id' => $to->id, 'unit_id' => $classroom->unit_id, 'name' => '1A']);

    expect(app(CopyClassroomsFromYear::class)->handle($from, $to))->toBe(0)
        ->and(Classroom::query()->where('academic_year_id', $to->id)->count())->toBe(1);
});

it('copies only classrooms of the given units', function () {
    $from = AcademicYear::factory()->create();
    $to = AcademicYear::factory()->create();
    $included = Unit::factory()->create();
    Classroom::factory()->create(['academic_year_id' => $from->id, 'unit_id' => $included->id]);
    Classroom::factory()->create(['academic_year_id' => $from->id]);

    app(CopyClassroomsFromYear::class)->handle($from, $to, [$included->id]);

    expect(Classroom::query()->where('academic_year_id', $to->id)->pluck('unit_id')->all())->toBe([$included->id]);
});
