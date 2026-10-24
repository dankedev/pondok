<?php

use App\Http\Middleware\SetAdminSecurityHeaders;
use App\Http\Middleware\SetPortalSecurityHeaders;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Testing\TestResponse;

/*
| F086: panel admin mengirim header dasar portal dan CSP report-only; portal publik dan portal wali tidak berubah.
*/

function adminHeadersAssert(TestResponse $response): void
{
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Permissions-Policy'))->toBe('camera=(), microphone=(), geolocation=()')
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))->toBe(SetAdminSecurityHeaders::CONTENT_SECURITY_POLICY_REPORT_ONLY)
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
}

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
});

it('AC-1/AC-3: sends the security headers on the admin login and password reset pages', function (string $url) {
    adminHeadersAssert($this->get($url)->assertOk());
})->with(['/admin/login', '/admin/password-reset/request']);

it('AC-2: sends the security headers on authenticated admin pages and panel denials', function () {
    $admin = User::factory()->create()->assignRole('super-admin');
    $noPanel = User::factory()->create();

    adminHeadersAssert($this->actingAs($admin)->get('/admin')->assertOk());
    adminHeadersAssert($this->actingAs($admin)->get('/admin/users')->assertOk());
    adminHeadersAssert($this->actingAs($noPanel)->get('/admin')->assertForbidden());
});

it('AC-2: keeps the admin csp in the report-only header, so policy text never blocks', function () {
    expect(SetAdminSecurityHeaders::CONTENT_SECURITY_POLICY_REPORT_ONLY)
        ->toContain("script-src 'self' 'unsafe-inline' 'unsafe-eval'")
        ->toContain("img-src 'self' data: blob:")
        ->toContain("worker-src 'self' blob:")
        ->toContain("frame-ancestors 'self'")
        ->not->toContain('report-uri');
});

it('AC-4/AC-5: leaves the public portal and the wali portal headers unchanged', function () {
    portalBoot();
    config(['pondok.portal.csp_enabled' => true]);

    $portal = $this->get('/')->assertOk();

    expect($portal->headers->get('Content-Security-Policy'))->toBe(SetPortalSecurityHeaders::CONTENT_SECURITY_POLICY)
        ->and($portal->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($portal->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();

    waliBoot();
    $wali = $this->get('/wali/masuk')->assertOk();

    expect($wali->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($wali->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse()
        ->and($wali->headers->has('X-Frame-Options'))->toBeFalse();
});
