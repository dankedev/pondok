<?php

namespace App\Actions\Psb;

use App\Models\AdmissionTrack;
use App\Models\FeeType;
use App\Models\Scholarship;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membuat atau mengubah jalur pendaftaran.
 *
 * Kode huruf besar unik (unique DB tetap pengaman terakhir). Program beasiswa
 * baru harus aktif; program yang sudah terpasang tetap boleh dipertahankan
 * walau kemudian dinonaktifkan. Tidak membuat keringanan apa pun: itu
 * dilakukan saat pendaftar jalur ini diterima (F011).
 *
 * `fee_type_ids` (opsional) memetakan jenis biaya penerima beasiswa jalur;
 * tidak dikirim = pemetaan tetap, array kosong = dikosongkan. Tepat satu
 * activity `psb` per penyimpanan yang mengubah sesuatu: log otomatis model
 * dimatikan dan diganti log manual berformat sama ditambah `fee_type_ids`.
 */
class SaveAdmissionTrack
{
    /**
     * @param  array{code: mixed, name: mixed, scholarship_id?: mixed, terms?: mixed, is_active?: mixed, fee_type_ids?: mixed}  $data
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
        $feeTypeIds = array_key_exists('fee_type_ids', $data) ? $this->feeTypeIds($data['fee_type_ids']) : null;

        $track ??= new AdmissionTrack;
        $track->fill([
            'code' => $code,
            'name' => $name,
            'scholarship_id' => $scholarshipId,
            'terms' => $terms === '' ? null : $terms,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return DB::transaction(function () use ($track, $feeTypeIds): AdmissionTrack {
            $creating = ! $track->exists;
            $dirty = array_intersect(array_keys($track->getDirty()), $track->getFillable());
            $old = $creating ? [] : array_combine($dirty, array_map(fn (string $key): mixed => $track->getOriginal($key), $dirty));
            $oldFeeTypeIds = $creating ? [] : $this->mappedIds($track);

            activity()->withoutLogs(fn () => $track->save());

            $attributes = array_combine($dirty, array_map(fn (string $key): mixed => $track->getAttribute($key), $dirty));

            if ($feeTypeIds !== null && $feeTypeIds !== $oldFeeTypeIds) {
                $track->feeTypes()->sync($feeTypeIds);
                $attributes['fee_type_ids'] = $feeTypeIds;
                $old['fee_type_ids'] = $oldFeeTypeIds;
            }

            if ($attributes !== []) {
                activity('psb')
                    ->performedOn($track)
                    ->event($creating ? 'created' : 'updated')
                    ->withProperties($creating ? ['attributes' => $attributes] : ['old' => $old, 'attributes' => $attributes])
                    ->log($creating ? 'created' : 'updated');
            }

            return $track;
        });
    }

    /**
     * Id jenis biaya unik terurut; setiap id harus ada dan berfrekuensi
     * `saat_diterima`, `bulanan`, atau `tahunan`.
     *
     * @return list<int>
     */
    private function feeTypeIds(mixed $value): array
    {
        $ids = collect(is_array($value) ? $value : [])
            ->filter(fn (mixed $id): bool => filled($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $frequencies = FeeType::query()->whereKey($ids)->pluck('frequency', 'id');

        if ($frequencies->count() !== count($ids)) {
            $this->fail('fee_type_ids', 'track_fee_type_not_found');
        }

        if ($frequencies->contains(fn (string $frequency): bool => ! in_array($frequency, AdmissionTrack::SCHOLARSHIP_FREQUENCIES, true))) {
            $this->fail('fee_type_ids', 'track_fee_type_invalid');
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function mappedIds(AdmissionTrack $track): array
    {
        return $track->feeTypes()
            ->orderBy('fee_types.id')
            ->pluck('fee_types.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
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
