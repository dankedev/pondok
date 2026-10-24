<?php

use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;

beforeEach(function () {
    $this->f = waliBoot();
});

it('AC-3: lets an active wali into the wali portal but not into the admin panel', function () {
    $this->actingAs($this->f->wali);

    $this->get('/wali')->assertOk();
    $this->get('/admin')->assertForbidden();
});

it('AC-3: keeps built-in non-wali roles out of the wali portal with the admin-login hint', function (string $role) {
    $this->actingAs(keuanganUser($role))->get('/wali')->assertForbidden()->assertSee('masuk lewat halaman admin');
})->with(['super-admin', 'bendahara', 'admin-konten', 'admin-sekolah', 'admin-psb', 'kesantrian', 'musyrif', 'ustadz-tahfidz', 'petugas-pos', 'wali-kelas']);

it('AC-3: keeps an inactive wali out and sends guests to the wali login', function () {
    $this->f->wali->forceFill(['is_active' => false])->save();

    $this->get('/wali')->assertRedirect(route('portal-wali.login'));
    $this->actingAs($this->f->wali)->get('/wali')->assertForbidden();
    $this->get('/wali/masuk')->assertRedirect(route('portal-wali.home'));
});

it('AC-3: a signed-in staff user without the wali role opening the wali login lands on a 403 that points to the admin login', function () {
    $this->actingAs(keuanganUser('bendahara'))->get('/wali/masuk')->assertRedirect(route('portal-wali.home'));
    $this->get('/wali')->assertForbidden()->assertSee('Staf pondok masuk lewat halaman admin');
});

it('AC-3: lets a bendahara who is also a wali into both areas, seeing only linked students in the wali portal', function () {
    $user = User::factory()->create()->assignRole(['bendahara', 'wali']);
    $this->f->guardian->forceFill(['user_id' => $user->id])->save();

    $this->actingAs($user)->get('/admin')->assertOk();

    $text = waliText($user);

    expect($text)->toContain('Ahmad Satu')->toContain('Budi Dua')->not->toContain('Citra Lain');
});

it('AC-7: a wali scoped to another unit still sees own student, class, room, invoice, payment and document', function () {
    $f = $this->f;
    $f->wali->units()->attach($f->sd->id);
    $classroom = Classroom::factory()->create(['academic_year_id' => $f->year->id, 'unit_id' => $f->sltp->id, 'name' => 'Kelas 7 Zaid']);
    Enrollment::factory()->create(['student_id' => $f->s1->id, 'classroom_id' => $classroom->id]);
    asramaResident($f->s1, asramaRoom(10, 'L', ['name' => 'Kamar Melati']));
    $invoice = waliInvoice($f, $f->s1, ['paid_total' => 150000, 'status' => Invoice::STATUS_PARTIAL]);
    Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 150000, 'paid_on' => '2026-10-15']);
    $document = dokumenCreate($f->s1, dokumenType(['name' => 'Ijazah Terbuka']));
    $otherInvoice = waliInvoice($f, $f->o1);
    $otherDocument = dokumenCreate($f->o1, dokumenType(['name' => 'Ijazah Lain']));

    expect(waliText($f->wali))->toContain('Ahmad Satu')->toContain('Kelas 7 Zaid')->toContain('Kamar Melati')->toContain('Rp 250.000')->not->toContain('Citra Lain');
    expect(waliText($f->wali, "/wali/santri/{$f->s1->id}"))->toContain($invoice->invoice_no)->toContain('Ijazah Terbuka');
    expect(waliText($f->wali, "/wali/tagihan/{$invoice->id}"))->toContain('Rp 150.000');

    $this->get("/wali/dokumen/{$document->id}/unduh")->assertOk();
    $this->get("/wali/santri/{$f->o1->id}")->assertNotFound();
    $this->get("/wali/tagihan/{$otherInvoice->id}")->assertNotFound();
    $this->get("/wali/dokumen/{$otherDocument->id}/unduh")->assertNotFound();
});
