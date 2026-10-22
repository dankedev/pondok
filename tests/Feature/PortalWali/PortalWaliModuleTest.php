<?php

use App\Actions\PortalWali\CreateGuardianPortalAccount;
use App\Enums\Module;
use App\Filament\Resources\Guardians\Pages\EditGuardian;
use App\Livewire\PortalWali\ForgotPassword as PortalForgotPassword;
use App\Livewire\PortalWali\Login as PortalLogin;
use App\Livewire\PortalWali\ResetPassword as PortalResetPassword;
use App\Models\Guardian;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = waliBoot();
});

it('AC-1: declares the portal-wali module that requires Akademik with Indonesian texts', function () {
    expect(Module::PortalWali->value)->toBe('portal-wali')
        ->and(Module::PortalWali->requires())->toBe([Module::Akademik]);

    expectTranslatedKeys(['pondok.modules.portal-wali.label', 'pondok.modules.portal-wali.description', 'pondok.portal_wali.activity_account_created']);
});

it('AC-2: answers 404 on every wali route when the module is off, even for a signed-in wali', function () {
    $invoice = waliInvoice($this->f, $this->f->s1);
    $document = dokumenCreate($this->f->s1, dokumenType(['name' => 'Ijazah Terbuka']));
    $this->get('/wali/masuk')->assertOk();
    $this->actingAs($this->f->wali)->get('/wali')->assertOk();
    Setting::set('modules.portal-wali.enabled', false);

    $paths = ['/wali', '/wali/masuk', '/wali/lupa-password', '/wali/atur-password/token-apa-saja', '/wali/email/verifikasi',
        "/wali/santri/{$this->f->s1->id}", "/wali/tagihan/{$invoice->id}", "/wali/dokumen/{$document->id}/unduh"];

    foreach ($paths as $path) {
        $this->get($path)->assertNotFound();
    }

    $this->post('/wali/email/verifikasi')->assertNotFound();
    $this->post('/wali/keluar')->assertNotFound();
});

it('AC-2: answers 404, not a login redirect, to a guest on every wali route when the module is off', function (string $route) {
    $invoice = waliInvoice($this->f, $this->f->s1);
    $document = dokumenCreate($this->f->s1, dokumenType(['name' => 'Ijazah Terbuka']));
    Setting::set('modules.portal-wali.enabled', false);

    $path = match ($route) {
        'masuk' => '/wali/masuk',
        'beranda' => '/wali',
        'santri' => "/wali/santri/{$this->f->s1->id}",
        'tagihan' => "/wali/tagihan/{$invoice->id}",
        'unduh' => "/wali/dokumen/{$document->id}/unduh",
    };

    $this->get($path)->assertNotFound();
})->with(['masuk', 'beranda', 'santri', 'tagihan', 'unduh']);

it('AC-2: a login, forgot-password and reset component loaded before the module is switched off answers 404 on its action', function () {
    Notification::fake();
    $login = Livewire::test(PortalLogin::class);
    $forgot = Livewire::test(PortalForgotPassword::class);
    $reset = Livewire::test(PortalResetPassword::class, ['token' => 'token-apa-saja']);
    Setting::set('modules.portal-wali.enabled', false);

    $login->set('email', $this->f->wali->email)->set('password', 'password')->call('authenticate')->assertNotFound();
    $forgot->set('email', $this->f->wali->email)->call('send')->assertNotFound();
    $reset->set('email', $this->f->wali->email)->set('password', 'Password-Baru-123!')->set('password_confirmation', 'Password-Baru-123!')
        ->call('resetPassword')->assertNotFound();

    $this->assertGuest();
    Notification::assertNothingSent();
});

it('AC-2: hides the create-account action and the Action refuses when the module is off', function () {
    Notification::fake();
    $guardian = Guardian::factory()->create();
    $this->actingAs(keuanganUser('admin-sekolah'));
    Setting::set('modules.portal-wali.enabled', false);

    Livewire::test(EditGuardian::class, ['record' => $guardian->id])->assertActionHidden('createPortalAccount');

    waliAssertRejected(fn () => app(CreateGuardianPortalAccount::class)->handle($guardian, 'baru@contoh.test', keuanganUser('admin-sekolah')));

    expect(User::query()->where('email', 'baru@contoh.test')->exists())->toBeFalse();
    Notification::assertNothingSent();
});
