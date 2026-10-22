<?php

namespace App\Actions\Kesehatan;

use App\Actions\Kesehatan\Concerns\GuardsHealthVisit;
use App\Models\HealthVisit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mengoreksi kunjungan kesehatan. Santri dan pencatat tidak berubah; santri yang sudah lulus/keluar
 * tetap boleh dikoreksi. Activity log hanya memuat nama kolom yang berubah, tanpa log bila tidak ada.
 */
class UpdateHealthVisit
{
    use GuardsHealthVisit;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(HealthVisit $visit, User $actor, array $data): HealthVisit
    {
        $this->authorize($actor->can('update', $visit));

        $visit->fill($this->validated($data));

        if (! $visit->isDirty()) {
            return $visit;
        }

        return DB::transaction(function () use ($visit, $actor): HealthVisit {
            $fields = array_keys($visit->getDirty());
            $visit->save();

            $this->log($visit, $actor, 'updated', ['fields' => $fields]);

            return $visit;
        });
    }
}
