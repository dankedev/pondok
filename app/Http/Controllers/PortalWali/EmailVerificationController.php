<?php

namespace App\Http\Controllers\PortalWali;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Verifikasi email portal wali (F079) dengan alur bawaan Laravel (`MustVerifyEmail`,
 * URL bertanda tangan dari `VerifyEmail::createUrlUsing` di AppServiceProvider).
 */
class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('portal-wali.home');
        }

        return view('portal-wali.verify-email');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->route('portal-wali.home');
    }

    public function send(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('portal-wali.home');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', __('pondok.portal_wali.verify_sent'));
    }
}
