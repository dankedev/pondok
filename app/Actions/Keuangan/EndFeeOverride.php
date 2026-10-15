<?php

namespace App\Actions\Keuangan;

use App\Models\FeeOverride;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Mengakhiri keringanan dengan mengisi `valid_until` (inklusif); baris tidak
 * dihapus. Tanggal akhir yang direncanakan lebih lambat boleh diperpendek.
 */
class EndFeeOverride
{
    public function handle(FeeOverride $override, ?CarbonInterface $endOn = null): FeeOverride
    {
        $endOn = CarbonImmutable::instance($endOn ?? today())->startOfDay();

        if ($override->valid_until !== null && $override->valid_until->lte($endOn)) {
            $this->fail('override_already_ended');
        }

        if ($endOn->lt($override->valid_from)) {
            $this->fail('end_before_start');
        }

        $override->update(['valid_until' => $endOn->toDateString()]);

        return $override;
    }

    private function fail(string $reason): never
    {
        throw ValidationException::withMessages([
            'valid_until' => __("pondok.keuangan.errors.{$reason}"),
        ]);
    }
}
