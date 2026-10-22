<?php

use App\Http\Controllers\Dokumen\LetterVerificationController;
use App\Http\Middleware\AddPrivacyHeaders;
use App\Http\Middleware\EnsureModuleIsEnabled;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\MalformedUrlException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Saklar modul dicek sebelum autentikasi (dan karenanya sebelum route model binding): modul mati
        // dijawab 403/404 sesuai saklar, termasuk untuk tamu (bukan redirect ke halaman masuk) dan id yang tidak ada.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EnsureModuleIsEnabled::class);
        // Header privasi (verifikasi surat, portal wali) paling luar (sebelum saklar modul dan throttle):
        // respons 403/404 dan 429 ikut membawanya.
        $middleware->prependToPriorityList(before: EnsureModuleIsEnabled::class, prepend: AddPrivacyHeaders::class);
        // Middleware `auth`/`guest` Laravel hanya dipakai portal wali (F079); panel Filament memakai middleware sendiri.
        // Closure karena route belum terdaftar saat bootstrap.
        $middleware->redirectGuestsTo(fn (): string => route('portal-wali.login'));
        $middleware->redirectUsersTo(fn (): string => route('portal-wali.home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Kode verifikasi surat berisi byte UTF-8 rusak ditolak sebelum routing (400); halaman publik
        // menjawabnya dengan halaman "kode tidak ditemukan" yang sama (404) beserta header privasi.
        $exceptions->render(fn (MalformedUrlException $exception, Request $request) => LetterVerificationController::malformedUrlResponse($request));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
