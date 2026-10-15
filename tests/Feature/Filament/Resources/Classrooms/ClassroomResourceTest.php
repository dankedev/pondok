<?php

use App\Filament\Resources\Classrooms\Pages\ManageClassrooms;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create()->assignRole('admin-sekolah'));
});

it('rejects a duplicate classroom name in the same unit and year', function () {
    $existing = Classroom::factory()->create(['name' => '1A']);

    Livewire::test(ManageClassrooms::class)
        ->callAction('create', [
            'academic_year_id' => $existing->academic_year_id,
            'unit_id' => $existing->unit_id,
            'grade' => 1,
            'name' => '1A',
            'gender' => 'campur',
        ])
        ->assertHasFormErrors(['name' => 'unique']);
});

it('promotes the students of a classroom from the table', function () {
    $from = Classroom::factory()->create([
        'academic_year_id' => AcademicYear::factory()->create(['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']),
    ]);
    $to = Classroom::factory()->create([
        'unit_id' => $from->unit_id,
        'academic_year_id' => AcademicYear::factory()->create(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']),
    ]);
    $student = Student::factory()->create(['unit_id' => $from->unit_id]);
    Enrollment::factory()->create(['student_id' => $student->id, 'classroom_id' => $from->id]);

    Livewire::test(ManageClassrooms::class)
        ->filterTable('academic_year_id', $from->academic_year_id)
        ->callAction(TestAction::make('promote')->table($from), ['target_classroom_id' => $to->id])
        ->assertNotified();

    expect($student->enrollments()->pluck('classroom_id')->all())->toContain($to->id);
});

it('copies classrooms from another academic year', function () {
    $from = AcademicYear::factory()->create();
    $to = AcademicYear::factory()->create();
    Classroom::factory()->count(2)->create(['academic_year_id' => $from->id]);

    Livewire::test(ManageClassrooms::class)
        ->callAction('copy', ['from_academic_year_id' => $from->id, 'to_academic_year_id' => $to->id])
        ->assertNotified();

    expect(Classroom::query()->where('academic_year_id', $to->id)->count())->toBe(2);
});
