<?php

namespace App\Actions\Perizinan;

use App\Actions\Perizinan\Concerns\ValidatesVisit;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;

/**
 * Mengubah kunjungan (biasanya mengisi waktu selesai). Santri dan pencatat tidak berubah; activity log
 * hanya memuat nama kolom yang berubah, tanpa log bila tidak ada perubahan.
 */
class UpdateVisit
{
    use ValidatesVisit;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Visit $visit, User $actor, array $data): Visit
    {
        $this->authorize($actor->can('update', $visit), 'visit');

        $visit->fill($this->validatedVisit($data, $visit->student_id));

        if (! $visit->isDirty()) {
            return $visit;
        }

        return DB::transaction(function () use ($visit, $actor): Visit {
            $fields = array_keys($visit->getDirty());
            $visit->save();

            $this->log($visit, $actor, 'updated', ['student_id' => $visit->student_id, 'fields' => $fields]);

            return $visit;
        });
    }
}
