<?php

namespace App\Actions\Kedisiplinan;

use App\Models\StudentViolation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Membatalkan pelanggaran yang salah catat. Baris tetap ada, tidak dihitung ke total poin,
 * dan tidak bisa diubah atau dibatalkan ulang.
 */
class CancelStudentViolation
{
    public function handle(User $actor, StudentViolation $violation, string $reason): StudentViolation
    {
        throw_unless($actor->can('cancel', $violation), AuthorizationException::class, __('pondok.kedisiplinan.errors.forbidden'));

        if ($violation->isCancelled()) {
            throw ValidationException::withMessages(['cancel_reason' => __('pondok.kedisiplinan.errors.already_cancelled')]);
        }

        $valid = Validator::make(['cancel_reason' => trim($reason)], [
            'cancel_reason' => ['required', 'string', 'max:'.StudentViolation::CANCEL_REASON_MAX_LENGTH],
        ], [], __('pondok.kedisiplinan.fields'))->validate();

        return DB::transaction(function () use ($violation, $actor, $valid): StudentViolation {
            $violation->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $valid['cancel_reason'],
            ])->save();

            activity()
                ->inLog('kedisiplinan')
                ->performedOn($violation)
                ->causedBy($actor)
                ->event('cancelled')
                ->withProperties([
                    'student_id' => $violation->student_id,
                    'violation_type_id' => $violation->violation_type_id,
                    'occurred_on' => $violation->occurred_on->toDateString(),
                    'points' => $violation->points,
                    'cancel_reason' => $violation->cancel_reason,
                ])
                ->log('cancelled');

            return $violation;
        });
    }
}
