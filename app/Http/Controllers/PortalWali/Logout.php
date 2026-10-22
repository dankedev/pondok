<?php

namespace App\Http\Controllers\PortalWali;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Keluar dari portal wali (F079). Sesi guard `web` dipakai bersama panel admin, jadi staf
 * yang juga wali ikut keluar dari admin.
 */
class Logout extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal-wali.login');
    }
}
