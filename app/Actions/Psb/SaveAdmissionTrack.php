<?php

namespace App\Actions\Psb;

use App\Models\AdmissionTrack;
use App\Models\Scholarship;
use Illuminate\Validation\ValidationException;

/**
 * Membuat atau mengubah jalur pendaftaran.
 *
 * Kode huruf besar unik (unique DB tetap pengaman terakhir). Program beasiswa
 * baru harus aktif; program yang sudah terpasang tetap boleh dipertahankan
 * walau kemudian dinonaktifkan. Tidak membuat keringanan apa pun: itu
 * dilakukan saat pendaftar jalur ini diterima (F011).
 */
class SaveAdmissionTrack
{
    /**
     * @param  array{code: mixed, name: mixed, scholarship_id?: mixed, terms?: mixed, is_active?: mixed}  $data
     */
    public function handle(array $data, ?AdmissionTrack $track = null): AdmissionTrack
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));

        if (preg_match('/^[A-Z0-9_]{1,30}$/', $code) !== 1) {
            $this->fail('code', 'code_format');
        }

        $codeTaken = AdmissionTrack::query()
            ->where('code', $code)
            ->when($track, fn ($query, AdmissionTrack $track) => $query->whereKeyNot($track->getKey()))
            ->exists();

        if ($codeTaken) {
            $this->fail('code', 'track_code_taken');
        }

        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 255) {
            $this->fail('name', 'name_required');
        }

        $scholarshipId = $this->scholarshipId($data['scholarship_id'] ?? null, $track);
        $terms = trim((string) ($data['terms'] ?? ''));

        $track ??= new AdmissionTrack;
        $track->fill([
            'code' => $code,
            'name' => $name,
            'scholarship_id' => $scholarshipId,
            'terms' => $terms === '' ? null : $terms,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ])->save();

        return $track;
    }

    private function scholarshipId(mixed $value, ?AdmissionTrack $track): ?int
    {
        if (blank($value)) {
            return null;
        }

        $scholarship = Scholarship::query()->find((int) $value) ?? $this->fail('scholarship_id', 'scholarship_not_found');

        if (! $scholarship->is_active && $scholarship->id !== $track?->scholarship_id) {
            $this->fail('scholarship_id', 'scholarship_inactive');
        }

        return $scholarship->id;
    }

    private function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.psb.errors.{$reason}"),
        ]);
    }
}
