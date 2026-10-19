<?php

namespace App\Actions\Dokumen;

use App\Models\Setting;
use App\Models\Staff;
use App\Models\Student;

/**
 * Slot tanda tangan sebuah jenis surat untuk satu santri: label, sumber, dan staff
 * aktif yang terpilih otomatis (`null` bila belum bisa ditentukan). Hanya membaca;
 * dipakai `IssueLetter` dan form penerbitan agar aturannya satu sumber.
 */
class ResolveLetterSigners
{
    public const SETTING_KEY = 'letters.signers';

    public const SOURCE_STAFF = 'staff';

    public const SOURCE_HOMEROOM = 'homeroom';

    public const MAX_SLOTS = 3;

    /**
     * @return list<array{label: string, source: string, staff_id: int|null}>
     */
    public function handle(string $type, Student $student): array
    {
        $slots = $this->slots($type);
        $student->loadMissing('currentEnrollment.classroom.homeroomStaff');
        $homeroom = $student->currentEnrollment?->classroom?->homeroomStaff;

        $staffIds = collect($slots)->where('source', self::SOURCE_STAFF)->pluck('staff_id')->filter()->all();
        $activeIds = Staff::query()->whereIn('id', $staffIds)->where('is_active', true)->pluck('id')->all();

        return array_map(fn (array $slot): array => [
            'label' => $slot['label'],
            'source' => $slot['source'],
            'staff_id' => $slot['source'] === self::SOURCE_HOMEROOM
                ? ($homeroom?->is_active ? $homeroom->id : null)
                : (in_array($slot['staff_id'], $activeIds, true) ? $slot['staff_id'] : null),
        ], $slots);
    }

    /**
     * Slot tersimpan untuk jenis surat, atau satu slot manual bawaan.
     *
     * @return list<array{label: string, source: string, staff_id: int|null}>
     */
    public function slots(string $type): array
    {
        $stored = Setting::get(self::SETTING_KEY);
        $stored = is_array($stored) && is_array($stored[$type] ?? null) ? array_values($stored[$type]) : [];

        $slots = array_map(fn (mixed $slot): array => [
            'label' => (string) ($slot['label'] ?? ''),
            'source' => ($slot['source'] ?? null) === self::SOURCE_HOMEROOM ? self::SOURCE_HOMEROOM : self::SOURCE_STAFF,
            'staff_id' => isset($slot['staff_id']) ? (int) $slot['staff_id'] : null,
        ], array_slice($stored, 0, self::MAX_SLOTS));

        return $slots !== [] ? $slots : [[
            'label' => __('pondok.letter_signers.default_label'),
            'source' => self::SOURCE_STAFF,
            'staff_id' => null,
        ]];
    }
}
