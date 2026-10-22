<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Route;

/*
| F082: pintu masuk wali. Header portal menawarkan Wali santri/Staf pondok, login admin menautkan portal wali,
| dan halaman 403 memberi wali tombol ke portal; semuanya hanya saat modul portal-wali aktif.
*/

it('AC-1/AC-2: offers the wali login next to the staff login in both navs only while the module is enabled', function (bool $enabled) {
    portalBoot();
    Setting::set('modules.portal-wali.enabled', $enabled);

    $links = portalNavLinks($this->get('/')->assertOk()->getContent());

    foreach (['desktop', 'mobile'] as $layout) {
        $hrefs = array_column($links[$layout], 'href');

        expect($hrefs)->toContain(route('filament.admin.auth.login'));
        expect(in_array(route('portal-wali.login'), $hrefs, true))->toBe($enabled);
    }
})->with(['module on' => true, 'module off' => false]);

it('AC-3: points the admin login to the wali portal only while the module is enabled', function () {
    waliBoot();
    $hint = 'href="'.route('portal-wali.login').'"';

    $this->get('/admin/login')->assertOk()->assertSee($hint, escape: false)->assertSee(__('pondok.portal_wali.admin_login_link'));

    Setting::set('modules.portal-wali.enabled', false);
    $this->get('/admin/login')->assertOk()->assertDontSee($hint, escape: false);
});

it('AC-4: gives a wali on the admin 403 a button to the wali portal', function () {
    $f = waliBoot();
    config(['app.debug' => false]);

    $this->actingAs($f->wali)->get('/admin')->assertForbidden()
        ->assertSee('href="'.route('portal-wali.home').'"', escape: false)
        ->assertSee(__('pondok.portal_wali.open_portal'));

    Setting::set('modules.portal-wali.enabled', false);
    $this->get('/admin')->assertForbidden()->assertDontSee(__('pondok.portal_wali.open_portal'));
});

it('AC-4: keeps the wali portal button off the 403 page for staff', function () {
    waliBoot();
    config(['app.debug' => false]);
    Route::middleware('web')->get('/_uji-403', fn () => abort(403));

    $this->actingAs(keuanganUser('bendahara'))->get('/_uji-403')->assertForbidden()
        ->assertSee(__('pondok.http_errors.403.title'))
        ->assertDontSee(__('pondok.portal_wali.open_portal'));
});
