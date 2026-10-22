<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pintu portal wali (F079): hanya user aktif berperan `wali`. Dicek setiap permintaan,
 * jadi wali yang dinonaktifkan di tengah sesi langsung ditolak. Staf tanpa peran wali
 * mendapat 403 yang menunjuk ke halaman masuk admin.
 */
class EnsurePortalWaliUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Halaman 403 sendiri: halaman error global tidak menampilkan pesan pengecualian.
        if (! $user?->is_active || ! $user->hasRole('wali')) {
            return response()->view('portal-wali.forbidden', status: 403);
        }

        return $next($request);
    }
}
