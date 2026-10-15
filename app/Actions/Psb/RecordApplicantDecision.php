<?php

namespace App\Actions\Psb;

use App\Models\Applicant;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Kelas bantu internal Action keputusan PSB (F011), bukan dipanggil UI.
 *
 * Memvalidasi transisi dan catatan pada baris pendaftar yang sudah dikunci
 * pemanggil, lalu menyimpan keputusan (status, reviewer, waktu, catatan) dan
 * tepat satu activity `psb` manual tanpa data pribadi. Validasi dilakukan
 * sebelum perubahan apa pun.
 */
class RecordApplicantDecision
{
    public const NOTE_MAX_LENGTH = 1000;

    /**
     * `$from` membatasi status asal bila beberapa Action menuju status yang sama
     * (mis. `diajukan` dari pengembalian atau pemulihan).
     *
     * @param  list<string>|null  $from
     *
     * @throws ValidationException transisi tidak sah atau catatan tidak valid
     */
    public function handle(Applicant $locked, User $by, string $to, ?string $note, bool $noteRequired, ?array $from = null): Applicant
    {
        $this->ensureCanTransition($locked, $to, $from);

        $note = $this->note($note, $noteRequired);
        $from = $locked->status;

        activity()->withoutLogs(function () use ($locked, $by, $to, $note): void {
            $locked->forceFill([
                'status' => $to,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();
        });

        activity('psb')
            ->performedOn($locked)
            ->causedBy($by)
            ->event('updated')
            ->withProperties([
                'old' => ['status' => $from],
                'attributes' => ['status' => $to],
                'note' => $note,
            ])
            ->log(__('pondok.applicant.activity_decided'));

        return $locked;
    }

    /**
     * @param  list<string>|null  $from
     */
    public function ensureCanTransition(Applicant $locked, string $to, ?array $from = null): void
    {
        if (! $locked->canTransitionTo($to) || ($from !== null && ! in_array($locked->status, $from, true))) {
            throw ValidationException::withMessages([
                'status' => __('pondok.psb.errors.invalid_transition'),
            ]);
        }
    }

    /**
     * Pendaftar dibaca ulang dan dikunci (di dalam transaksi pemanggil);
     * terhapus (soft delete) dianggap tidak ditemukan.
     *
     * @throws ValidationException
     */
    public function lock(Applicant $applicant): Applicant
    {
        // `withoutGlobalScopes()` juga melepas scope soft delete, jadi yang terhapus disaring di sini.
        $locked = Applicant::withoutGlobalScopes()->lockForUpdate()->find($applicant->getKey());

        if ($locked === null || $locked->trashed()) {
            throw ValidationException::withMessages([
                'applicant' => __('pondok.psb.errors.applicant_not_found'),
            ]);
        }

        return $locked;
    }

    /**
     * Salin keadaan baris terkunci ke instance milik pemanggil.
     */
    public function sync(Applicant $applicant, Applicant $locked): Applicant
    {
        $applicant->setRawAttributes($locked->getAttributes(), sync: true);

        return $applicant;
    }

    private function note(?string $note, bool $required): ?string
    {
        $note = trim((string) $note);

        if ($note === '' && $required) {
            throw ValidationException::withMessages([
                'review_note' => __('pondok.psb.errors.review_note_required'),
            ]);
        }

        if (mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw ValidationException::withMessages([
                'review_note' => __('pondok.psb.errors.review_note_invalid'),
            ]);
        }

        return $note === '' ? null : $note;
    }
}
