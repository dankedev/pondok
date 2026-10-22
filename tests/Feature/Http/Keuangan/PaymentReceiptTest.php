<?php

use App\Actions\Keuangan\CancelPayment;
use App\Models\Applicant;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $this->f = tagihanFixture();
    Setting::set('school.name', 'Pondok Contoh');
    $this->officer = keuanganUser('bendahara');
    $this->officer->forceFill(['name' => 'Bu Bendahara'])->save();
    $this->student = tagihanStudent($this->f, resident: true, attributes: ['name' => 'Ahmad Fauzi', 'nis' => '2026000123']);
    $this->invoice = bayarInvoice($this->f, $this->student);
});

/**
 * Pembayaran 500.000 oleh petugas bendahara untuk santri fixture.
 */
function receiptPayment(mixed $test, array $overrides = []): Payment
{
    return bayarRecord($test->invoice, 500000, $overrides, $test->officer);
}

it('AC-40: prints a receipt without external assets', function () {
    $payment = receiptPayment($this);
    $items = $this->invoice->items()->with('feeType')->get()->keyBy(fn ($item) => $item->feeType->code);

    $response = $this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk();
    $html = $response->getContent();
    $text = bayarHtmlText($html);

    expect($response->headers->get('Content-Type'))->toContain('text/html')
        ->and($text)->toContain('Pondok Contoh')
        ->toContain(__('pondok.receipt.title'))
        ->toContain('KW-202610-00001')
        ->toContain('15 Okt 2026')
        ->toContain('2026000123')
        ->toContain('Ahmad Fauzi')
        ->toContain($this->invoice->invoice_no)
        ->toContain('Oktober 2026')
        ->toContain($items['MAKAN']->description)
        ->toContain('Rp 400.000')
        ->toContain($items['ASRAMA']->description)
        ->toContain('Rp 100.000')
        ->toContain('Rp 500.000')
        ->toContain('Lima ratus ribu rupiah')
        ->toContain('Tunai')
        ->toContain('Bu Bendahara')
        ->toContain('15 Okt 2026, 10.00')
        ->not->toContain($items['SPP']->description)
        ->not->toContain('pondok.receipt.')
        ->not->toContain('pondok.payment.')
        ->not->toContain(__('pondok.receipt.cancelled'));
});

it('AC-40: stays free of Vite, CDN assets, scripts and colours', function () {
    $payment = receiptPayment($this);

    $html = $this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent();

    expect($html)->toContain('<html lang="id"')
        ->not->toContain('@vite')
        ->not->toContain('/build/')
        ->not->toContain('<script src')
        ->not->toContain('<link ')
        ->not->toContain('wire:');

    preg_match_all('#https?://([^/"\'\s)>]+)#i', $html, $matches);
    $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

    foreach ($matches[1] as $host) {
        expect($host)->toBe($appHost);
    }

    preg_match('#<style\b[^>]*>(.*?)</style>#is', $html, $style);

    expect($style[1] ?? '')->toContain('A5')
        ->not->toMatch('/#[0-9a-fA-F]{3,8}\b/')
        ->not->toMatch('/rgba?\(/i')
        ->not->toMatch('/hsla?\(/i');
});

it('AC-41: names the billing contact as payer', function () {
    $contact = Guardian::factory()->create(['name' => 'Haji Mahmud']);
    $other = Guardian::factory()->create(['name' => 'Ibu Siti']);
    $this->student->guardians()->attach($other->id, ['relation' => 'ibu', 'is_billing_contact' => false]);
    $this->student->guardians()->attach($contact->id, ['relation' => 'ayah', 'is_billing_contact' => true]);
    $payment = receiptPayment($this);

    $text = bayarHtmlText($this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent());

    expect($text)->toMatch('/'.preg_quote(__('pondok.receipt.received_from'), '/').'\W*Haji Mahmud/u')
        ->not->toContain('Ibu Siti');
});

it('AC-41: falls back to the student name without a billing contact', function (bool $withNonContact) {
    if ($withNonContact) {
        $this->student->guardians()->attach(Guardian::factory()->create(['name' => 'Ibu Siti'])->id, ['relation' => 'ibu', 'is_billing_contact' => false]);
    }
    $payment = receiptPayment($this);

    $text = bayarHtmlText($this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent());

    expect($text)->toMatch('/'.preg_quote(__('pondok.receipt.received_from'), '/').'\W*Ahmad Fauzi/u')
        ->not->toContain('Ibu Siti');
})->with(['no guardians' => [false], 'only a non-contact guardian' => [true]]);

it('AC-41: shows the school address and phone only when they are set', function () {
    $payment = receiptPayment($this);

    $without = bayarHtmlText($this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent());

    Setting::set('school.address', 'Jl. Pesantren No. 1');
    Setting::set('school.contacts', [profilContact(['phone' => '0812-0000-1111', 'is_print' => true])]);

    $with = bayarHtmlText($this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent());

    expect($without)->not->toContain('Jl. Pesantren')->not->toContain('0812-0000-1111')
        ->and($with)->toContain('Jl. Pesantren No. 1')->toContain('0812-0000-1111');
});

it('AC-41: shows the note when the payment has one', function () {
    $payment = receiptPayment($this, ['note' => 'Titipan lewat wali kelas']);

    expect(bayarHtmlText($this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent()))
        ->toContain('Titipan lewat wali kelas');
});

it('AC-41: prints a receipt for an invoice without a student', function () {
    $applicantInvoice = Invoice::factory()->create(['academic_year_id' => $this->f->year->id, 'student_id' => null, 'applicant_id' => Applicant::factory()->create()->id, 'billing_group' => 'PSB', 'total' => 300000]);
    $payment = Payment::factory()->create(['invoice_id' => $applicantInvoice->id, 'amount' => 300000, 'receipt_no' => 'KW-202610-00099', 'received_by' => $this->officer->id]);

    $this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->assertSee('KW-202610-00099');
});

it('AC-42: marks cancelled receipts', function () {
    $payment = receiptPayment($this);
    app(CancelPayment::class)->handle($payment, $this->officer, 'Salah input nominal');

    $text = bayarHtmlText($this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent());

    expect(__('pondok.receipt.cancelled'))->toBe('DIBATALKAN')
        ->and($text)->toContain('DIBATALKAN')
        ->toContain('Salah input nominal')
        ->toContain('KW-202610-00001')
        ->toContain('Rp 500.000');
});

it('AC-43: guards the receipt page', function (string $case) {
    $payment = receiptPayment($this);

    match ($case) {
        'guest' => $this->get("/admin/receipts/{$payment->id}")->assertRedirect(route('filament.admin.auth.login')),
        'no permission' => $this->actingAs(keuanganUser('admin-sekolah'))->get("/admin/receipts/{$payment->id}")->assertForbidden(),
        'musyrif' => $this->actingAs(keuanganUser('musyrif'))->get("/admin/receipts/{$payment->id}")->assertForbidden(),
        'unknown id' => $this->actingAs($this->officer)->get('/admin/receipts/999999')->assertNotFound(),
    };
})->with(['guest', 'no permission', 'musyrif', 'unknown id']);

it('AC-29: hides receipts of other units behind a 404', function () {
    $f = $this->f;
    $slta = tagihanInvoice(tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta), $f->year, ['billing_group' => 'X']);
    $other = Payment::factory()->create(['invoice_id' => $slta->id]);
    $own = receiptPayment($this);
    $scoped = keuanganUser('bendahara', ['SLTP']);

    $this->actingAs($scoped)->get("/admin/receipts/{$other->id}")->assertNotFound();
    $this->actingAs($scoped)->get("/admin/receipts/{$own->id}")->assertOk();
    $this->actingAs(keuanganUser('bendahara'))->get("/admin/receipts/{$other->id}")->assertOk();
});

it('AC-47: has no raw lang keys', function () {
    $payment = receiptPayment($this);

    $text = bayarHtmlText($this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent());

    expect($text)->not->toContain('pondok.')->not->toContain('receipt.')->not->toContain('payment.');
});

it('VERIFY: escapes user supplied text on the receipt', function () {
    $this->student->forceFill(['name' => '<script>alert(1)</script>'])->save();
    $payment = receiptPayment($this, ['note' => '<img src=x onerror=alert(2)>']);

    $html = $this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)')->not->toContain('<img src=x');
});

it('VERIFY: prints a receipt and opens the detail when the receiver is unknown', function () {
    $payment = receiptPayment($this);
    $payment->forceFill(['received_by' => null])->save();

    $this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk();
    $this->actingAs($this->officer)->get("/admin/payments/{$payment->id}")->assertOk();
    $this->actingAs($this->officer)->get('/admin/payments')->assertOk();
});

it('AC-39: prints the applicant details and the guardian on the receipt', function () {
    $applicant = Applicant::factory()->create(['unit_id' => $this->f->sltp->id, 'registration_no' => 'PSB-2027-0001', 'name' => 'Ahmad Fauzan', 'guardian_name' => 'Budi Santoso']);
    $invoice = Invoice::factory()->create(['academic_year_id' => $this->f->year->id, 'student_id' => null, 'applicant_id' => $applicant->id, 'billing_group' => 'PSB_DAFTAR', 'total' => 250000]);
    $payment = Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 250000, 'receipt_no' => 'KW-202610-00098', 'received_by' => $this->officer->id]);

    $text = bayarHtmlText($this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent());

    expect($text)->toContain('PSB-2027-0001')->toContain('Ahmad Fauzan')
        ->toContain(__('pondok.receipt.received_from').' Budi Santoso');
});

it('AC-53: prints the print logo and never the desktop or mobile logo', function () {
    Storage::fake('public');
    foreach (['cetak', 'desktop', 'mobile'] as $name) {
        Storage::disk('public')->put("school/{$name}.png", $name);
        Setting::set($name === 'cetak' ? 'school.logo_path' : "school.logo_{$name}_path", "school/{$name}.png");
    }
    Setting::set('school.address', 'Jl. Pesantren No. 1');
    Setting::set('school.contacts', [profilContact(['phone' => '0812-0000-1111', 'is_print' => true])]);
    $payment = receiptPayment($this);

    $html = $this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent();

    expect($html)->toContain(Storage::disk('public')->url('school/cetak.png'))
        ->not->toContain('school/desktop.png')->not->toContain('school/mobile.png')
        ->and(bayarHtmlText($html))->toContain('Pondok Contoh')->toContain('Jl. Pesantren No. 1')->toContain('0812-0000-1111');
});

it('AC-2 (F044): prints the same name, address and phone after the school.phone migration', function () {
    profilRawSet('school.address', 'Jl. Pesantren No. 1');
    profilRawSet('school.phone', '0812-0000-1111');
    $migration = require database_path('migrations/2026_10_19_000001_move_school_phone_to_contacts.php');
    $migration->up();
    $payment = receiptPayment($this);

    $text = bayarHtmlText($this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent());

    expect($text)->toContain('Pondok Contoh')->toContain('Jl. Pesantren No. 1')->toContain('0812-0000-1111');
});

it('AC-2 (F044): omits the phone line without a print contact and never prints other contacts', function () {
    Setting::set('school.address', 'Jl. Pesantren No. 1');
    Setting::set('school.contacts', [profilContact(['phone' => '081200007777'])]);
    $payment = receiptPayment($this);

    $text = bayarHtmlText($this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent());

    expect($text)->toContain('Jl. Pesantren No. 1')->not->toContain('081200007777')->not->toContain('Telp.');
});

it('AC-3 (F044): a receipt uses the current print contact phone', function () {
    Setting::set('school.contacts', [profilContact(['key' => 'aaaa1111', 'phone' => '081200001111', 'is_print' => true])]);
    $payment = receiptPayment($this);
    Setting::set('school.contacts', [profilContact(['key' => 'aaaa1111', 'phone' => '081200001111']), profilContact(['key' => 'bbbb2222', 'phone' => '081200002222', 'is_print' => true])]);

    $text = bayarHtmlText($this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent());

    expect($text)->toContain('081200002222')->not->toContain('081200001111');
});

it('AC-1 (F062): prints the old officer block without a signer', function () {
    $payment = receiptPayment($this);

    $text = bayarHtmlText($this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent());

    expect($text)->toContain('Petugas')->toContain('Bu Bendahara')
        ->not->toContain('Dicatat oleh')->not->toContain('Penandatangan');
});

it('AC-2 (F062): prints the signer with title and the recording officer', function () {
    Setting::set('keuangan.receipt_signer_staff_id', Staff::factory()->create(['name' => 'Ustadz Yusuf', 'position' => 'Bendahara Pondok'])->id);
    $payment = receiptPayment($this);

    $text = bayarHtmlText($this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent());

    expect($text)->toContain('Penandatangan')->toContain('Ustadz Yusuf')->toContain('Bendahara Pondok')
        ->toContain('Dicatat oleh: Bu Bendahara');
});

it('AC-3 (F062): reprints the original signer after the staff changed and was deleted', function () {
    $staff = Staff::factory()->create(['name' => 'Ustadz Yusuf', 'position' => 'Bendahara Pondok']);
    Setting::set('keuangan.receipt_signer_staff_id', $staff->id);
    $payment = receiptPayment($this);
    $staff->update(['name' => 'Nama Baru', 'position' => 'Jabatan Baru']);
    $staff->delete();

    $text = bayarHtmlText($this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent());

    expect($text)->toContain('Ustadz Yusuf')->toContain('Bendahara Pondok')
        ->not->toContain('Nama Baru')->not->toContain('Jabatan Baru');
});

it('AC-5 (F062): prints no title line for a signer without position', function () {
    Setting::set('keuangan.receipt_signer_staff_id', Staff::factory()->create(['name' => 'Ustadz Yusuf', 'position' => '  '])->id);
    $payment = receiptPayment($this);

    $text = bayarHtmlText($this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent());

    expect($text)->toMatch('/Penandatangan Ustadz Yusuf Dicatat oleh: Bu Bendahara/');
});

it('AC-6 (F062): a cancelled payment keeps its signer snapshot and the cancelled mark', function () {
    Setting::set('keuangan.receipt_signer_staff_id', Staff::factory()->create(['name' => 'Ustadz Yusuf', 'position' => 'Bendahara Pondok'])->id);
    $payment = receiptPayment($this);
    app(CancelPayment::class)->handle($payment, $this->officer, 'Salah input nominal');

    $text = bayarHtmlText($this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent());

    expect($text)->toContain('DIBATALKAN')->toContain('Ustadz Yusuf')->toContain('Bendahara Pondok');
});

it('VERIFY F062: escapes the signer name and title on the receipt', function () {
    Setting::set('keuangan.receipt_signer_staff_id', Staff::factory()->create(['name' => '<script>alert(3)</script>', 'position' => '<img src=x onerror=alert(4)>'])->id);
    $payment = receiptPayment($this);

    $html = $this->actingAs($this->officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(3)')->not->toContain('<img src=x')->toContain('&lt;script&gt;');
});
