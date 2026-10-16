<?php

use App\Http\Controllers\Dokumen\LetterVerificationController;
use App\Http\Middleware\AddPrivacyHeaders;
use App\Http\Middleware\EnsureModuleIsEnabled;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\MalformedUrlException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Saklar modul dicek sebelum route model binding (modul mati selalu 403).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureModuleIsEnabled::class);
        // Header privasi halaman verifikasi surat paling luar (sebelum throttle dan saklar modul):
        // respons 403 dan 429 ikut membawanya.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AddPrivacyHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Kode verifikasi surat berisi byte UTF-8 rusak ditolak sebelum routing (400); halaman publik
        // menjawabnya dengan halaman "kode tidak ditemukan" yang sama (404) beserta header privasi.
        $exceptions->render(fn (MalformedUrlException $exception, Request $request) => LetterVerificationController::malformedUrlResponse($request));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
