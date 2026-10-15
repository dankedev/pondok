<?php

namespace App\Actions\Akademik;

use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * Menghubungkan wali ke santri. Wali pertama otomatis menjadi kontak tagihan.
 */
class AttachGuardian
{
    public function __construct(private SetBillingContact $setBillingContact) {}

    public function handle(Student $student, Guardian $guardian, string $relation, bool $isBillingContact = false): void
    {
        DB::transaction(function () use ($student, $guardian, $relation, $isBillingContact): void {
            $isFirstGuardian = ! $student->guardians()->exists();

            $student->guardians()->syncWithoutDetaching([
                $guardian->id => ['relation' => $relation],
            ]);

            if ($isBillingContact || $isFirstGuardian) {
                $this->setBillingContact->handle($student, $guardian);
            }
        });
    }
}
