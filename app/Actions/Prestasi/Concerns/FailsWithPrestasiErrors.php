<?php

namespace App\Actions\Prestasi\Concerns;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Galat bersama Action prestasi: pesan `pondok.prestasi.errors.<reason>` pada
 * field terkait, dan pemeriksaan cakupan jenjang user login.
 */
trait FailsWithPrestasiErrors
{
    /**
     * @param  array<string, mixed>  $replace
     */
    protected function fail(string $field, string $reason, array $replace = []): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.prestasi.errors.{$reason}", $replace),
        ]);
    }

    /**
     * Semua peserta harus dalam cakupan user login; tanpa user login tidak disaring (pola `UnitScope`).
     */
    protected function ensureFullyVisible(Achievement $achievement): void
    {
        $user = Auth::user();

        if ($user instanceof User && ! $achievement->isFullyVisibleTo($user)) {
            $this->fail('achievement', 'outside_scope');
        }
    }
}
