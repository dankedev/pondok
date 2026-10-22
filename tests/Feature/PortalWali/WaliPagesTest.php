<?php

use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;

beforeEach(function () {
    $this->f = waliBoot();
});

it('AC-4: the home page lists both siblings with level, class, room and outstanding invoice, and no other wali student', function () {
    $f = $this->f;
    $classroom = Classroom::factory()->create(['academic_year_id' => $f->year->id, 'unit_id' => $f->sltp->id, 'name' => 'Kelas 7 Zaid']);
    Enrollment::factory()->create(['student_id' => $f->s1->id, 'classroom_id' => $classroom->id]);
    asramaResident($f->s1, asramaRoom(10, 'L', ['name' => 'Kamar Melati']));
    waliInvoice($f, $f->s1, ['total' => 1250000]);

    $text = waliText($f->wali);

    expect($text)->toContain('Ahmad Satu')->toContain('1001')->toContain('Budi Dua')->toContain($f->sltp->name)
        ->toContain('Kelas 7 Zaid')->toContain('Kamar Melati')->toContain('Rp 1.250.000')
        ->not->toContain('Citra Lain');
});

it('AC-4: the room disappears from the home page when the Asrama module is off', function () {
    asramaResident($this->f->s1, asramaRoom(10, 'L', ['name' => 'Kamar Melati']));
    Setting::set('modules.asrama.enabled', false);

    expect(waliText($this->f->wali))->toContain('Ahmad Satu')->not->toContain('Kamar Melati');
});

it('AC-4: shows an empty state, not an error, for a wali without guardian row or students', function () {
    $noGuardian = User::factory()->create()->assignRole('wali');
    $noStudents = User::factory()->create()->assignRole('wali');
    waliGuardianFor($noStudents, 'Wali Kosong');

    foreach ([$noGuardian, $noStudents] as $user) {
        expect(waliText($user))->toContain('Belum ada santri yang terhubung dengan akun Anda');
    }
});

it('AC-5: a wali gets 404 on student, invoice and document of another wali, and on unknown ids', function () {
    $f = $this->f;
    $invoice = waliInvoice($f, $f->o1);
    $document = dokumenCreate($f->o1, dokumenType(['name' => 'Ijazah Lain']));
    $this->actingAs($f->wali);

    $this->get("/wali/santri/{$f->s1->id}")->assertOk();
    $this->get("/wali/santri/{$f->o1->id}")->assertNotFound();
    $this->get("/wali/tagihan/{$invoice->id}")->assertNotFound();
    $this->get("/wali/dokumen/{$document->id}/unduh")->assertNotFound();
    $this->get('/wali/santri/999999')->assertNotFound();
    $this->get('/wali/tagihan/999999')->assertNotFound();
    $this->get('/wali/dokumen/999999/unduh')->assertNotFound();
    $this->get('/wali/dokumen/abc/unduh')->assertNotFound();
    $this->get('/wali/santri/abc')->assertNotFound();
    $this->get('/wali/tagihan/abc')->assertNotFound();
});

it('AC-6: an archived student or a detached link disappears from home and every page', function (string $case) {
    $f = $this->f;
    $invoice = waliInvoice($f, $f->s1);
    $document = dokumenCreate($f->s1, dokumenType(['name' => 'Ijazah Terbuka']));

    match ($case) {
        'archived' => $f->s1->delete(),
        'detached' => $f->s1->guardians()->detach($f->guardian->id),
    };

    expect(waliText($f->wali))->not->toContain('Ahmad Satu')->toContain('Budi Dua');

    $this->get("/wali/santri/{$f->s1->id}")->assertNotFound();
    $this->get("/wali/tagihan/{$invoice->id}")->assertNotFound();
    $this->get("/wali/dokumen/{$document->id}/unduh")->assertNotFound();
})->with(['archived', 'detached']);

it('AC-13: more than ten invoices are paginated', function () {
    $f = $this->f;

    foreach (range(1, 11) as $month) {
        waliInvoice($f, $f->s1, [], sprintf('2025-%02d-01', $month));
    }

    $text = waliText($f->wali, "/wali/santri/{$f->s1->id}");
    $invoices = Invoice::query()->where('student_id', $f->s1->id)->get();

    expect($invoices->filter(fn (Invoice $i): bool => str_contains($text, $i->invoice_no)))->toHaveCount(10)
        ->and(bayarHtmlText($this->get("/wali/santri/{$f->s1->id}?page=2")->assertOk()->getContent()))->toContain('Ahmad Satu');
});

it('AC-1: the wali pages render inside the public portal layout and are not indexable', function () {
    $response = $this->actingAs($this->f->wali)->get('/wali')->assertOk();

    $response->assertSee(config('app.name'))->assertSee(__('pondok.portal.nav.skip_to_content'));
    expect($response->getContent())->toContain('noindex');
});

it('AC-8: the student page shows non-cancelled invoices with total, remaining and status but never NISN or address', function () {
    $f = $this->f;
    $f->s1->forceFill(['nisn' => '9988776655', 'address' => 'Jl. Rahasia Nomor 99'])->save();
    $open = waliInvoice($f, $f->s1, ['total' => 400000, 'paid_total' => 150000, 'status' => Invoice::STATUS_PARTIAL]);
    $cancelled = waliInvoice($f, $f->s1, ['status' => Invoice::STATUS_CANCELLED], '2026-09-01');

    $text = waliText($f->wali, "/wali/santri/{$f->s1->id}");

    expect($text)->toContain($open->invoice_no)->toContain('Rp 400.000')->toContain('Rp 250.000')->toContain($open->statusLabel())
        ->not->toContain($cancelled->invoice_no)
        ->not->toContain('9988776655')->not->toContain('Jl. Rahasia Nomor 99');
});

it('AC-8: with Keuangan off the invoice section is gone and the invoice page is 404', function () {
    $f = $this->f;
    $invoice = waliInvoice($f, $f->s1);
    Setting::set('modules.keuangan.enabled', false);
    $this->actingAs($f->wali);

    expect(waliText($f->wali, "/wali/santri/{$f->s1->id}"))->toContain('Ahmad Satu')->not->toContain($invoice->invoice_no);
    $this->get("/wali/tagihan/{$invoice->id}")->assertNotFound();
});

it('AC-8: with Dokumen off the document section is gone', function () {
    $f = $this->f;
    dokumenCreate($f->s1, dokumenType(['name' => 'Ijazah Terbuka']));
    Setting::set('modules.dokumen.enabled', false);

    expect(waliText($f->wali, "/wali/santri/{$f->s1->id}"))->toContain('Ahmad Satu')->not->toContain('Ijazah Terbuka');
});

it('AC-9: the invoice page shows items, remaining and received payments only, with the date format', function () {
    $f = $this->f;
    $invoice = waliInvoice($f, $f->s1, ['total' => 400000, 'paid_total' => 150000, 'status' => Invoice::STATUS_PARTIAL]);
    Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 150000, 'paid_on' => '2026-10-15']);
    Payment::factory()->cancelled()->create(['invoice_id' => $invoice->id, 'amount' => 77000, 'paid_on' => '2026-10-14']);

    $text = waliText($f->wali, "/wali/tagihan/{$invoice->id}");

    expect($text)->toContain('Rp 500.000')->toContain('Rp 100.000')->toContain('Rp 400.000')
        ->toContain('Rp 250.000')->toContain('Rp 150.000')->toContain('15 Okt 2026')
        ->not->toContain('Rp 77.000')->not->toContain('14 Okt 2026');
});

it('AC-9: an own cancelled invoice is 404', function () {
    $cancelled = waliInvoice($this->f, $this->f->s1, ['status' => Invoice::STATUS_CANCELLED]);
    $open = waliInvoice($this->f, $this->f->s1);

    $this->actingAs($this->f->wali)->get("/wali/tagihan/{$open->id}")->assertOk();
    $this->get("/wali/tagihan/{$cancelled->id}")->assertNotFound();
});
