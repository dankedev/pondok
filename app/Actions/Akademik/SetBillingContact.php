<?php

namespace App\Actions\Akademik;

use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menandai satu wali sebagai kontak tagihan santri. Wali lain dilepas tandanya.
 */
class SetBillingContact
{
    public function handle(Student $student, Guardian $guardian): void
    {
        if (! $student->guardians()->whereKey($guardian->id)->exists()) {
            throw ValidationException::withMessages([
                'guardian_id' => __('pondok.akademik.errors.guardian_not_attached'),
            ]);
        }

        DB::transaction(function () use ($student, $guardian): void {
            DB::table('student_guardian')
                ->where('student_id', $student->id)
                ->update(['is_billing_contact' => false]);

            $student->guardians()->updateExistingPivot($guardian->id, ['is_billing_contact' => true]);
        });
    }
}
