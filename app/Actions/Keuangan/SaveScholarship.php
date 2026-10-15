<?php

namespace App\Actions\Keuangan;

use App\Models\FeeOverride;
use App\Models\Scholarship;
use Illuminate\Validation\ValidationException;

/**
 * Membuat atau mengubah program beasiswa. Nama unik (unique DB tetap
 * pengaman terakhir). Mengubah mode atau nilai bawaan tidak mengubah
 * keringanan yang sudah ada.
 */
class SaveScholarship
{
    /**
     * @param  array{name: mixed, mode: mixed, default_value: mixed, sponsor?: mixed, is_active?: mixed}  $data
     */
    public function handle(array $data, ?Scholarship $scholarship = null): Scholarship
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 255) {
            $this->fail('name', 'name_required');
        }

        $taken = Scholarship::query()
            ->where('name', $name)
            ->when($scholarship, fn ($query, Scholarship $scholarship) => $query->whereKeyNot($scholarship->getKey()))
            ->exists();

        if ($taken) {
            $this->fail('name', 'scholarship_name_taken');
        }

        $mode = $data['mode'] ?? null;

        if (! in_array($mode, FeeOverride::MODES, true)) {
            $this->fail('mode', 'invalid_mode');
        }

        $value = $this->wholeNumber($data['default_value'] ?? null);

        if ($value === null) {
            $this->fail('default_value', 'value_invalid');
        }

        if ($mode === FeeOverride::MODE_PERCENT && $value > 100) {
            $this->fail('default_value', 'percent_range');
        }

        $sponsor = trim((string) ($data['sponsor'] ?? ''));

        $scholarship ??= new Scholarship;
        $scholarship->fill([
            'name' => $name,
            'mode' => $mode,
            'default_value' => $value,
            'sponsor' => $sponsor === '' ? null : $sponsor,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ])->save();

        return $scholarship;
    }

    /**
     * Bilangan bulat >= 0 (int atau string berisi digit saja); selain itu null.
     */
    private function wholeNumber(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $value = filter_var(trim($value), FILTER_VALIDATE_INT);
        }

        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.keuangan.errors.{$reason}"),
        ]);
    }
}
