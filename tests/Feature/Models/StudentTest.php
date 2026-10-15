<?php

use App\Models\Cohort;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\UnitSeeder;

beforeEach(function () {
    $this->seed(UnitSeeder::class);

    $this->units = Unit::query()->pluck('id', 'code');

    foreach (['SD', 'SLTP', 'SLTA'] as $code) {
        Student::factory()
            ->forCohort(Cohort::factory()->create(['unit_id' => $this->units[$code]]))
            ->create(['name' => "Santri {$code}"]);
    }
});

it('returns students of every unit to a user without unit_user rows', function () {
    $this->actingAs(User::factory()->create());

    expect(Student::query()->count())->toBe(3);
});

it('returns only students of the assigned units to a scoped user', function () {
    $user = User::factory()->create();
    $user->units()->attach([$this->units['SD'], $this->units['SLTA']]);

    $this->actingAs($user);

    expect(Student::query()->orderBy('name')->pluck('name')->all())->toBe(['Santri SD', 'Santri SLTA']);
});

it('does not resolve a student from another unit for a scoped user', function () {
    $user = User::factory()->create();
    $user->units()->attach($this->units['SLTP']);
    $sdStudent = Student::query()->where('unit_id', $this->units['SD'])->sole();

    $this->actingAs($user);

    expect(Student::query()->find($sdStudent->id))->toBeNull();
});

it('keeps a deleted student in the table as soft deleted', function () {
    $student = Student::query()->firstOrFail();

    $student->delete();

    expect(Student::query()->count())->toBe(2)
        ->and(Student::withTrashed()->find($student->id)?->trashed())->toBeTrue();
});
