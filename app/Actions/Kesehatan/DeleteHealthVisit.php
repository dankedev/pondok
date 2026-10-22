<?php

namespace App\Actions\Kesehatan;

use App\Actions\Kesehatan\Concerns\GuardsHealthVisit;
use App\Models\HealthVisit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Menghapus kunjungan kesehatan yang salah catat (mis. salah santri). Hanya super-admin.
 */
class DeleteHealthVisit
{
    use GuardsHealthVisit;

    public function handle(HealthVisit $visit, User $actor): void
    {
        $this->authorize($actor->can('delete', $visit));

        DB::transaction(function () use ($visit, $actor): void {
            $visit->delete();

            $this->log($visit, $actor, 'deleted');
        });
    }
}
