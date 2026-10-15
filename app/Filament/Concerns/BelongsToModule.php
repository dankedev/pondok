<?php

namespace App\Filament\Concerns;

use App\Models\Setting;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Pasang ke resource modul non-inti. Bila saklar `modules.<nama>.enabled`
 * mati, semua aksi resource ditolak sehingga menu hilang dan halaman 403.
 */
trait BelongsToModule
{
    abstract public static function getModule(): string;

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        if (! Setting::moduleEnabled(static::getModule())) {
            return Response::deny(__('pondok.common.module_disabled'));
        }

        return parent::getAuthorizationResponse($action, $record);
    }
}
