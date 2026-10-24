<?php

namespace App\Actions\Kedisiplinan;

use App\Models\DisciplineLevel;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Menghapus ambang poin. Aman karena ambang hanya label rekap dan tidak dirujuk tabel lain.
 */
class DeleteDisciplineLevel
{
    public function handle(User $actor, DisciplineLevel $level): void
    {
        throw_unless($actor->can('delete', $level), AuthorizationException::class, __('pondok.kedisiplinan.errors.forbidden'));

        $level->delete();
    }
}
