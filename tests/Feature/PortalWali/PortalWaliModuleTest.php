<?php

use App\Actions\PortalWali\CreateGuardianPortalAccount;
use App\Enums\Module;
use App\Filament\Resources\Guardians\Pages\EditGuardian;
use App\Models\Guardian;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
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
    $this->get('/wali/login')->assertOk();
    $this->actingAs($this->f->wali)->get('/wali')->assertOk();
    Setting::set('modules.portal-wali.enabled', false);

    $this->get('/wali/login')->assertNotFound();

    foreach (['/wali', "/wali/santri/{$this->f->s1->id}", "/wali/tagihan/{$invoice->id}", "/wali/documents/{$document->id}/download"] as $path) {
        $this->get($path)->assertNotFound();
    }
});

it('AC-2: closes every wali page via canAccess when the module is off', function () {
    Filament::setCurrentPanel('wali');
    $this->actingAs($this->f->wali);
    $pages = Filament::getPanel('wali')->getPages();

    expect($pages)->not->toBeEmpty();

    Setting::set('modules.portal-wali.enabled', false);

    foreach ($pages as $page) {
        expect($page::canAccess())->toBeFalse("{$page} masih terbuka saat portal-wali mati");
    }
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
