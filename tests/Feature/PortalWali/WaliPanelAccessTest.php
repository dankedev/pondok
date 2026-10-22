<?php

use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;

beforeEach(function () {
    $this->f = waliBoot();
});

it('AC-3: lets an active wali into the wali panel but not into the admin panel', function () {
    $this->actingAs($this->f->wali);

    $this->get('/wali')->assertOk();
    $this->get('/admin')->assertForbidden();
});

it('AC-3: keeps built-in non-wali roles out of the wali panel', function (string $role) {
    $this->actingAs(keuanganUser($role))->get('/wali')->assertForbidden();
})->with(['super-admin', 'bendahara', 'admin-konten', 'admin-sekolah', 'admin-psb', 'kesantrian', 'musyrif', 'ustadz-tahfidz', 'petugas-pos']);

it('AC-3: keeps an inactive wali out and sends guests to the wali login', function () {
    $this->f->wali->forceFill(['is_active' => false])->save();

    $this->get('/wali')->assertRedirect('/wali/login');
    $this->actingAs($this->f->wali)->get('/wali')->assertForbidden();
});

it('AC-3: lets a bendahara who is also a wali into both panels, seeing only linked students in the wali panel', function () {
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

    $this->get("/wali/documents/{$document->id}/download")->assertOk();
    $this->get("/wali/santri/{$f->o1->id}")->assertNotFound();
    $this->get("/wali/tagihan/{$otherInvoice->id}")->assertNotFound();
    $this->get("/wali/documents/{$otherDocument->id}/download")->assertNotFound();
});
