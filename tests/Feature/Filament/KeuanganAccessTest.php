<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
});

it('AC-28: lets bendahara manage fee types and rates', function () {
    $user = keuanganUser('bendahara');

    foreach (['/admin/fee-types', '/admin/fee-rates'] as $url) {
        $this->actingAs($user)->get($url)->assertOk();
    }
});

it('AC-28: forbids keuangan resources to other roles', function (string $role) {
    $user = keuanganUser($role);

    $this->actingAs($user)->get('/admin/fee-types')->assertForbidden();
    $this->actingAs($user)->get('/admin/fee-rates')->assertForbidden();
})->with(['admin-sekolah', 'kesantrian', 'musyrif', 'admin-konten', 'admin-psb', 'ustadz-tahfidz', 'petugas-pos']);

it('AC-29: returns 403 and hides the menu when the keuangan module is disabled', function () {
    Setting::set('modules.keuangan.enabled', false);
    $user = keuanganUser('super-admin');

    $this->actingAs($user)->get('/admin/fee-types')->assertForbidden();
    $this->actingAs($user)->get('/admin/fee-rates')->assertForbidden();
    $this->actingAs($user)->get('/admin')->assertOk()->assertDontSee(__('pondok.navigation.keuangan'));
});

it('AC-29: shows the keuangan menu when the module is enabled', function () {
    expect(__('pondok.navigation.keuangan'))->not->toBe('pondok.navigation.keuangan');

    $this->actingAs(keuanganUser('super-admin'))->get('/admin')->assertSee(__('pondok.navigation.keuangan'));
});

it('AC-29: enables the keuangan module on a fresh install', function () {
    Setting::query()->delete();

    $this->seed(SettingSeeder::class);

    expect(Setting::moduleEnabled('keuangan'))->toBeTrue()
        ->and(Setting::moduleEnabled('akademik'))->toBeTrue()
        ->and(Setting::moduleEnabled('asrama'))->toBeTrue();
});

it('AC-28: lets bendahara manage scholarships and overrides', function () {
    $user = keuanganUser('bendahara');

    foreach (['/admin/scholarships', '/admin/fee-overrides'] as $url) {
        $this->actingAs($user)->get($url)->assertOk();
    }
});

it('AC-28: forbids scholarship and override pages to other roles', function (string $role) {
    $user = keuanganUser($role);

    $this->actingAs($user)->get('/admin/scholarships')->assertForbidden();
    $this->actingAs($user)->get('/admin/fee-overrides')->assertForbidden();
})->with(['admin-sekolah', 'kesantrian', 'musyrif', 'admin-konten', 'admin-psb', 'ustadz-tahfidz', 'petugas-pos']);

it('AC-29: returns 403 for scholarship and override pages when the module is disabled', function () {
    Setting::set('modules.keuangan.enabled', false);
    $user = keuanganUser('super-admin');

    $this->actingAs($user)->get('/admin/scholarships')->assertForbidden();
    $this->actingAs($user)->get('/admin/fee-overrides')->assertForbidden();
    $this->actingAs($user)->get('/admin')->assertOk()
        ->assertDontSee(__('pondok.scholarship.plural'))
        ->assertDontSee(__('pondok.fee_override.plural'));
});

it('AC-29: shows the scholarship and override menu when the module is enabled', function () {
    expect(__('pondok.scholarship.plural'))->not->toBe('pondok.scholarship.plural')
        ->and(__('pondok.fee_override.plural'))->not->toBe('pondok.fee_override.plural');

    $this->actingAs(keuanganUser('bendahara'))->get('/admin')->assertOk()
        ->assertSee(__('pondok.scholarship.plural'))
        ->assertSee(__('pondok.fee_override.plural'));
});

it('AC-44: lets bendahara and super-admin open invoices', function (string $role) {
    $invoice = Invoice::factory()->create();
    $user = keuanganUser($role);

    $this->actingAs($user)->get('/admin/invoices')->assertOk();
    $this->actingAs($user)->get("/admin/invoices/{$invoice->id}")->assertOk();
})->with(['bendahara', 'super-admin']);

it('AC-44: forbids invoices to other roles', function (string $role) {
    $invoice = Invoice::factory()->create();
    $user = keuanganUser($role);

    $this->actingAs($user)->get('/admin/invoices')->assertForbidden();
    $this->actingAs($user)->get("/admin/invoices/{$invoice->id}")->assertForbidden();
})->with(['admin-sekolah', 'kesantrian', 'musyrif', 'admin-konten', 'admin-psb', 'ustadz-tahfidz', 'petugas-pos']);

it('AC-44: shows the invoice menu to bendahara', function () {
    expect(__('pondok.invoice.plural'))->not->toBe('pondok.invoice.plural');

    $this->actingAs(keuanganUser('bendahara'))->get('/admin')->assertOk()->assertSee(__('pondok.invoice.plural'));
});

it('AC-45: hides invoices when the module is disabled', function () {
    $invoice = Invoice::factory()->create();
    Setting::set('modules.keuangan.enabled', false);
    $user = keuanganUser('super-admin');

    $this->actingAs($user)->get('/admin/invoices')->assertForbidden();
    $this->actingAs($user)->get("/admin/invoices/{$invoice->id}")->assertForbidden();
    $this->actingAs($user)->get('/admin')->assertOk()->assertDontSee(__('pondok.invoice.plural'));
});

/*
|--------------------------------------------------------------------------
| F007: pembayaran
|--------------------------------------------------------------------------
*/

it('AC-27: offers no way to delete payments', function () {
    $payment = Payment::factory()->create();
    $bendahara = keuanganUser('bendahara');
    $super = keuanganUser('super-admin');

    expect($bendahara->can('delete', $payment))->toBeFalse()
        ->and($bendahara->can('deleteAny', Payment::class))->toBeFalse()
        ->and($bendahara->can('update', $payment))->toBeFalse()
        ->and($bendahara->can('cancel', $payment))->toBeTrue()
        ->and($bendahara->can('create', Payment::class))->toBeTrue();

    $this->actingAs($bendahara)->get("/admin/payments/{$payment->id}/edit")->assertNotFound();
    $this->actingAs($bendahara)->get('/admin/payments/create')->assertNotFound();

    expect($super->can('view', $payment))->toBeTrue();
});

it('AC-31: lets bendahara and super-admin open payments', function (string $role) {
    $payment = Payment::factory()->create();
    $user = keuanganUser($role);

    $this->actingAs($user)->get('/admin/payments')->assertOk();
    $this->actingAs($user)->get("/admin/payments/{$payment->id}")->assertOk();
})->with(['bendahara', 'super-admin']);

it('AC-31: forbids payments to other roles', function (string $role) {
    $payment = Payment::factory()->create();
    $user = keuanganUser($role);

    $this->actingAs($user)->get('/admin/payments')->assertForbidden();
    $this->actingAs($user)->get("/admin/payments/{$payment->id}")->assertForbidden();
})->with(['admin-sekolah', 'kesantrian', 'musyrif', 'admin-konten', 'admin-psb', 'ustadz-tahfidz', 'petugas-pos']);

it('AC-31: shows the payment menu to bendahara below invoices', function () {
    expect(__('pondok.payment.plural'))->not->toBe('pondok.payment.plural');

    $html = $this->actingAs(keuanganUser('bendahara'))->get('/admin')->assertOk()->assertSee(__('pondok.payment.plural'))->getContent();

    expect(strpos($html, __('pondok.invoice.plural')))->toBeLessThan(strpos($html, __('pondok.payment.plural')));
});

it('AC-32: hides payments when the module is disabled', function () {
    $payment = Payment::factory()->create();
    Setting::set('modules.keuangan.enabled', false);
    $user = keuanganUser('super-admin');

    $this->actingAs($user)->get('/admin/payments')->assertForbidden();
    $this->actingAs($user)->get("/admin/payments/{$payment->id}")->assertForbidden();
    $this->actingAs($user)->get("/admin/receipts/{$payment->id}")->assertForbidden();
    $this->actingAs($user)->get("/admin/payment-proofs/{$payment->id}")->assertForbidden();
    $this->actingAs($user)->get('/admin')->assertOk()->assertDontSee(__('pondok.payment.plural'));
});

it('AC-32: answers 403 for receipts and proofs of unknown ids when the module is disabled', function () {
    Setting::set('modules.keuangan.enabled', false);
    $user = keuanganUser('super-admin');

    $this->actingAs($user)->get('/admin/receipts/999999')->assertForbidden();
    $this->actingAs($user)->get('/admin/payment-proofs/999999')->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| F008: laporan keuangan
|--------------------------------------------------------------------------
*/

it('AC-18: lets bendahara and super-admin open the finance report', function (string $role) {
    $this->actingAs(keuanganUser($role))->get('/admin/finance-report')->assertOk();
})->with(['bendahara', 'super-admin']);

it('AC-18: forbids the finance report to other roles', function (string $role) {
    $this->actingAs(keuanganUser($role))->get('/admin/finance-report')->assertForbidden();
})->with(['admin-sekolah', 'kesantrian', 'musyrif', 'admin-konten', 'admin-psb', 'ustadz-tahfidz', 'petugas-pos']);

it('AC-18: sends guests to the login page', function () {
    $this->get('/admin/finance-report')->assertRedirect('/admin/login');
});

it('AC-18: shows the finance report menu to bendahara but not to admin-sekolah', function () {
    expect(__('pondok.finance_report.title'))->not->toBe('pondok.finance_report.title');

    $this->actingAs(keuanganUser('bendahara'))->get('/admin')->assertOk()->assertSee(__('pondok.finance_report.title'));
    $this->actingAs(keuanganUser('admin-sekolah'))->get('/admin')->assertOk()->assertDontSee(__('pondok.finance_report.title'));
});

it('AC-19: hides the finance report when the keuangan module is disabled', function () {
    Setting::set('modules.keuangan.enabled', false);
    $user = keuanganUser('super-admin');

    $this->actingAs($user)->get('/admin/finance-report')->assertForbidden();
    $this->actingAs($user)->get('/admin')->assertOk()->assertDontSee(__('pondok.finance_report.title'));
});
