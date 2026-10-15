<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menolak route modul non-inti (403) bila saklar `modules.<nama>.enabled` mati.
 *
 * Didaftarkan di daftar prioritas sebelum `SubstituteBindings`, sehingga id
 * yang tidak ada pun dijawab 403 (bukan 404) saat modul mati.
 */
class EnsureModuleIsEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(Setting::moduleEnabled($module), 403, __('pondok.common.module_disabled'));

        return $next($request);
    }
}
