<?php

namespace App\Actions\Asrama;

use App\Models\DormitorySupervisor;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Mengakhiri tugas kepala asrama/musyrif. Barisnya tetap ada sebagai riwayat.
 */
class EndSupervision
{
    public function handle(DormitorySupervisor $supervisor, ?CarbonInterface $endOn = null): DormitorySupervisor
    {
        $endOn = ($endOn ?? today())->toImmutable()->startOfDay();

        if ($supervisor->end_on !== null) {
            $this->fail('supervision_already_ended');
        }

        if ($endOn->lt($supervisor->start_on)) {
            $this->fail('end_before_start');
        }

        $supervisor->update(['end_on' => $endOn]);

        return $supervisor;
    }

    private function fail(string $reason): never
    {
        throw ValidationException::withMessages([
            'end_on' => __("pondok.asrama.errors.{$reason}"),
        ]);
    }
}
