<?php

namespace App\Actions\Psb;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Menyimpan nominal minimal daftar ulang per jenjang (F040), kunci = `unit_id`,
 * nilai `null` (wajib lunas) atau rupiah bulat 0–1 triliun (`0` = tanpa syarat).
 * Cakupan dibaca dari `$by->scopedUnitIds()` (kosong = semua jenjang), bukan dari
 * sesi login. Seluruh payload divalidasi sebelum menulis; hanya jenjang yang
 * nilainya berubah yang ditulis dan dilog `re_registration_minimum_updated`.
 */
class UpdateReRegistrationMinimums
{
    public const MAX_AMOUNT = 1_000_000_000_000;

    /**
     * @param  array<int, int|string|null>  $amounts
     */
    public function handle(array $amounts, User $by): void
    {
        $validated = Validator::make(
            ['amounts' => $amounts],
            ['amounts' => ['array'], 'amounts.*' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT]],
        )->validate()['amounts'] ?? [];

        $scope = $by->scopedUnitIds();
        $units = Unit::query()
            ->whereKey(array_keys($validated))
            ->when($scope !== [], fn ($query) => $query->whereKey($scope))
            ->get()
            ->keyBy('id');

        if ($units->count() !== count($validated)) {
            throw ValidationException::withMessages([
                'amounts' => __('pondok.psb.re_registration_settings.unit_not_allowed'),
            ]);
        }

        DB::transaction(function () use ($validated, $units, $by): void {
            foreach ($validated as $unitId => $amount) {
                /** @var Unit $unit */
                $unit = $units[$unitId];
                $old = $unit->min_reregistration_amount;
                $new = $amount === null ? null : (int) $amount;

                if ($old === $new) {
                    continue;
                }

                $unit->update(['min_reregistration_amount' => $new]);

                activity('psb')
                    ->causedBy($by)
                    ->performedOn($unit)
                    ->event('re_registration_minimum_updated')
                    ->withProperties(['old' => $old, 'new' => $new])
                    ->log('re_registration_minimum_updated');
            }
        });
    }
}
