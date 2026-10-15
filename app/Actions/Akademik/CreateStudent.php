<?php

namespace App\Actions\Akademik;

use App\Models\Cohort;
use App\Models\Student;
use Illuminate\Validation\ValidationException;

/**
 * Membuat santri baru dan memastikan jenjangnya sama dengan jenjang angkatan.
 */
class CreateStudent
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes): Student
    {
        $cohort = Cohort::query()->findOrFail($attributes['cohort_id']);

        if ((int) $attributes['unit_id'] !== $cohort->unit_id) {
            throw ValidationException::withMessages([
                'cohort_id' => __('pondok.akademik.errors.cohort_unit_mismatch'),
            ]);
        }

        return Student::query()->create($attributes);
    }
}
