<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menolak route modul non-inti bila saklar `modules.<nama>.enabled` mati.
 *
 * Bawaan 403 dengan pesan; parameter `404` (mis. `:konten,404`) untuk route publik
 * agar pengunjung tidak melihat pesan admin. Didaftarkan di daftar prioritas sebelum
 * autentikasi (`AuthenticatesRequests`, jadi juga sebelum `SubstituteBindings`): tamu dan id
 * yang tidak ada pun dijawab sesuai saklar saat modul mati, bukan redirect ke halaman masuk.
 */
class EnsureModuleIsEnabled
{
    public function handle(Request $request, Closure $next, string $module, string $status = '403'): Response
    {
        if (! Setting::moduleEnabled($module)) {
            if ($status === '404') {
                abort(404);
            }

            abort(403, __('pondok.common.module_disabled'));
        }

        return $next($request);
    }
}
