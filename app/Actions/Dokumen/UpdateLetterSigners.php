<?php

namespace App\Actions\Dokumen;

use App\Models\IssuedLetter;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menyimpan pengaturan penandatangan surat (`letters.signers`): 1-3 slot per jenis,
 * tiap slot berlabel dengan sumber staff tertentu atau wali kelas santri. Nilai yang
 * sama dengan setting sekarang tidak ditulis dan tidak dilog; perubahan dilog sebagai
 * `signers_updated`. Surat yang sudah terbit tidak berubah.
 */
class UpdateLetterSigners
{
    public const LABEL_MAX_LENGTH = 60;

    /**
     * @param  array<string, mixed>  $signers  jenis surat => list slot
     */
    public function handle(array $signers, User $actor): void
    {
        $new = $this->validate($signers);
        $old = Setting::get(ResolveLetterSigners::SETTING_KEY);

        if ($new === $old) {
            return;
        }

        DB::transaction(function () use ($new, $old, $actor): void {
            Setting::set(ResolveLetterSigners::SETTING_KEY, $new);

            activity('dokumen')
                ->causedBy($actor)
                ->event('signers_updated')
                ->withProperties(['old' => $old, 'new' => $new])
                ->log('signers_updated');
        });
    }

    /**
     * @param  array<string, mixed>  $signers
     * @return array<string, list<array{label: string, source: string, staff_id?: int}>>
     */
    private function validate(array $signers): array
    {
        $errors = [];
        $clean = [];

        foreach ($signers as $type => $slots) {
            if (! in_array($type, IssuedLetter::TYPES, true) || ! is_array($slots)) {
                $errors["signers.{$type}"] = __('pondok.dokumen.errors.signers_invalid');

                continue;
            }

            $slots = array_values($slots);

            if ($slots === [] || count($slots) > ResolveLetterSigners::MAX_SLOTS) {
                $errors["signers.{$type}"] = __('pondok.letter_signers.slot_count');

                continue;
            }

            $used = [];

            foreach ($slots as $i => $slot) {
                $key = "signers.{$type}.{$i}";
                $label = is_string($slot['label'] ?? null) ? trim($slot['label']) : '';
                $source = $slot['source'] ?? null;

                if ($label === '' || mb_strlen($label) > self::LABEL_MAX_LENGTH) {
                    $errors["{$key}.label"] = __('pondok.letter_signers.label_invalid', ['max' => self::LABEL_MAX_LENGTH]);
                }

                if ($source === ResolveLetterSigners::SOURCE_HOMEROOM) {
                    $clean[$type][] = ['label' => $label, 'source' => $source];

                    continue;
                }

                if ($source !== ResolveLetterSigners::SOURCE_STAFF) {
                    $errors["{$key}.source"] = __('pondok.letter_signers.source_invalid');

                    continue;
                }

                $staffId = is_numeric($slot['staff_id'] ?? null) ? (int) $slot['staff_id'] : null;

                if ($staffId === null || ! Staff::query()->where('is_active', true)->whereKey($staffId)->exists()) {
                    $errors["{$key}.staff_id"] = __('pondok.letter_signers.staff_invalid');
                } elseif (in_array($staffId, $used, true)) {
                    $errors["{$key}.staff_id"] = __('pondok.letter_signers.staff_duplicate');
                }

                $used[] = $staffId;
                $clean[$type][] = ['label' => $label, 'source' => $source, 'staff_id' => $staffId];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }
}
