<?php

use App\Filament\Resources\Applicants\Pages\ViewApplicant;
use App\Filament\Resources\Applicants\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Livewire\Psb\ApplicationStatus;
use App\Livewire\Psb\RegistrationForm;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\Cohort;
use App\Models\Invoice;
use App\Models\Payment;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->f = pendaftaranFixture();
});

function pendaftaranTagihanContext(object $psb): object
{
    return (object) [
        'year' => $psb->y2026,
        'sltp' => $psb->sltp,
        'slta' => $psb->slta,
        'cohort' => Cohort::factory()->create(['unit_id' => $psb->sltp->id, 'entry_year' => 2026]),
        'cohortSlta' => Cohort::factory()->create(['unit_id' => $psb->slta->id, 'entry_year' => 2026]),
    ];
}

it('VERIFY: scopes a converted invoice by the student unit, not the applicant unit', function () {
    $f = pendaftaranTagihanContext($this->f);
    $applicant = Applicant::factory()->create(['unit_id' => $this->f->sltp->id]);
    $student = tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta);
    $invoice = Invoice::factory()->create(['student_id' => $student->id, 'applicant_id' => $applicant->id, 'academic_year_id' => $f->year->id, 'billing_group' => 'PSB_DAFTAR']);

    $this->actingAs(keuanganUser('bendahara', ['SLTP']));
    expect(Invoice::query()->whereKey($invoice->id)->exists())->toBeFalse();

    $this->actingAs(keuanganUser('bendahara', ['SLTA']));
    expect(Invoice::query()->whereKey($invoice->id)->exists())->toBeTrue();
});

it('VERIFY: prints a converted invoice receipt with the student, not the applicant', function () {
    $f = pendaftaranTagihanContext($this->f);
    $applicant = Applicant::factory()->create(['unit_id' => $f->sltp->id, 'registration_no' => 'PSB-2027-0007', 'name' => 'Nama Pendaftar', 'guardian_name' => 'Wali Pendaftar']);
    $student = tagihanStudent($f, attributes: ['name' => 'Nama Santri', 'nis' => '2026000555']);
    $invoice = Invoice::factory()->create(['student_id' => $student->id, 'applicant_id' => $applicant->id, 'academic_year_id' => $f->year->id, 'billing_group' => 'PSB_DAFTAR']);
    $payment = Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100000]);

    $text = bayarHtmlText($this->actingAs(keuanganUser('bendahara'))->get("/admin/receipts/{$payment->id}")->assertOk()->getContent());

    expect($text)->toContain('2026000555')->toContain('Nama Santri')->not->toContain('Wali Pendaftar');
});

it('VERIFY: does not leak other units invoices through the owner search', function () {
    $f = pendaftaranTagihanContext($this->f);
    $sltp = Applicant::factory()->create(['unit_id' => $f->sltp->id, 'registration_no' => 'PSB-2027-0001', 'name' => 'Ahmad Satu']);
    $slta = Applicant::factory()->create(['unit_id' => $f->slta->id, 'registration_no' => 'PSB-2027-0002', 'name' => 'Ahmad Dua']);
    $make = fn (Applicant $applicant) => Invoice::factory()->create(['student_id' => null, 'applicant_id' => $applicant->id, 'academic_year_id' => $f->year->id, 'billing_group' => 'PSB_DAFTAR']);
    $own = $make($sltp);
    $other = $make($slta);
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    Livewire::test(ListInvoices::class)
        ->searchTable('Ahmad')->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$other])
        ->searchTable('PSB-2027-0002')->assertCanNotSeeTableRecords([$own, $other]);
});

it('VERIFY: hides documents of out-of-scope applicants in the relation manager', function () {
    $sltp = pendaftaranApplicant(['registration_no' => 'PSB-2027-0001']);
    $document = pendaftaranDocument($sltp, $this->f->kk);
    $this->actingAs(psbUser('admin-psb', ['SD']));

    Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $sltp, 'pageClass' => ViewApplicant::class])
        ->assertCanNotSeeTableRecords([$document]);

    $this->get("/admin/applicants/{$sltp->id}")->assertNotFound();
});

it('VERIFY: warns with a danger notification and changes nothing when deleting a paid applicant', function () {
    $applicant = pendaftaranApplicant();
    pendaftaranPayRegistration($applicant);
    $this->actingAs(psbUser('admin-psb'));

    Livewire::test(ViewApplicant::class, ['record' => $applicant->id])->assertActionVisible('delete')->callAction('delete');

    $notifications = asramaNotifications();

    expect($notifications->pluck('status')->all())->toContain('danger')
        ->and(Applicant::withoutUnitScope()->whereKey($applicant->id)->exists())->toBeTrue()
        ->and(Invoice::withoutUnitScope()->where('applicant_id', $applicant->id)->where('status', 'batal')->count())->toBe(0);
});

it('VERIFY: never renders the token hash in the admin detail', function () {
    $applicant = pendaftaranApplicant();
    $this->actingAs(psbUser('admin-psb'));

    $html = Livewire::test(ViewApplicant::class, ['record' => $applicant->id])->html();

    expect($html)->not->toContain($applicant->access_token_hash)->not->toContain('ABCD-EFGH-JK23');
});

it('VERIFY: escapes hostile applicant data on the public status page and the admin detail', function () {
    $applicant = pendaftaranApplicant(['name' => '<script>alert(1)</script>', 'registration_no' => 'PSB-2027-0001']);

    $status = Livewire::test(ApplicationStatus::class)->set('registration_no', 'PSB-2027-0001')->set('token', 'ABCD-EFGH-JK23')->call('login');
    $this->actingAs(psbUser('admin-psb'));
    $admin = Livewire::test(ViewApplicant::class, ['record' => $applicant->id]);

    expect($status->html())->not->toContain('<script>alert(1)')
        ->and($admin->html())->not->toContain('<script>alert(1)');
});

it('VERIFY: leaks no applicant data on the public pages without a session', function () {
    pendaftaranApplicant(['name' => 'Rahasia Pendaftar', 'registration_no' => 'PSB-2027-0042', 'guardian_phone' => '081311112222']);

    foreach (['/psb', '/psb/status'] as $url) {
        $this->get($url)->assertOk()->assertDontSee('Rahasia Pendaftar')->assertDontSee('PSB-2027-0042')->assertDontSee('081311112222');
    }
});

it('VERIFY: shows one applicant no data of another applicant', function () {
    pendaftaranApplicant(['name' => 'Orang Pertama', 'registration_no' => 'PSB-2027-0001']);
    pendaftaranApplicant(['name' => 'Orang Kedua', 'registration_no' => 'PSB-2027-0002'], 'WXYZ-2345-ABCD');

    $component = Livewire::test(ApplicationStatus::class)->set('registration_no', 'PSB-2027-0001')->set('token', 'ABCD-EFGH-JK23')->call('login');

    expect(tagihanPlainText($component))->toContain('Orang Pertama')->not->toContain('Orang Kedua')->not->toContain('PSB-2027-0002');
});

it('VERIFY: cannot forge the applicant by setting the session property from the client', function () {
    $other = pendaftaranApplicant(['name' => 'Orang Kedua', 'registration_no' => 'PSB-2027-0002'], 'WXYZ-2345-ABCD');

    $component = Livewire::test(ApplicationStatus::class);

    foreach (['registration_no' => 'PSB-2027-0002', 'token' => 'SALAH-SALAH-SALA'] as $property => $value) {
        $component->set($property, $value);
    }
    $component->call('login');

    expect(tagihanPlainText($component))->not->toContain('Orang Kedua')->and($other->exists)->toBeTrue();
});

it('VERIFY: enforces the boundaries of submitted fields', function (array $overrides, ?string $reason, ?string $field) {
    if ($reason === null) {
        expect(pendaftaranSubmit($overrides)['applicant']->exists)->toBeTrue();

        return;
    }

    pendaftaranAssertRejected(fn () => pendaftaranSubmit($overrides), $reason, $field);
})->with([
    'name 255' => [['name' => str_repeat('a', 255)], null, null],
    'name 256' => [['name' => str_repeat('a', 256)], 'applicant_name_invalid', 'name'],
    'birth place 101' => [['birth_place' => str_repeat('a', 101)], 'birth_place_invalid', 'birth_place'],
    'phone shortest' => [['guardian_phone' => '081234567'], null, null],
    'phone too short' => [['guardian_phone' => '08123456'], 'phone_invalid', 'guardian_phone'],
    'phone longest' => [['guardian_phone' => '08123456789012'], null, null],
    'phone too long' => [['guardian_phone' => '081234567890123'], 'phone_invalid', 'guardian_phone'],
    'phone with 62 prefix' => [['guardian_phone' => '6281234567890'], null, null],
    'future birth date' => [['birth_date' => '2027-01-01'], 'birth_date_invalid', 'birth_date'],
    'email too long' => [['email' => str_repeat('a', 250).'@b.co'], 'email_invalid', 'email'],
    'nisn 11 digits' => [['nisn' => '01234567890'], 'nisn_invalid', 'nisn'],
]);

it('VERIFY: does not trust client side wave, track or unit ids of the wrong type', function (mixed $value) {
    pendaftaranAssertRejected(fn () => pendaftaranSubmit(['admission_wave_id' => $value]), 'wave_not_found', 'admission_wave_id');
})->with([[''], [null], ['abc'], [[1]]]);

it('VERIFY: keeps every registration invoice amount an integer', function () {
    $applicant = pendaftaranSubmit()['applicant'];
    $invoice = Invoice::withoutUnitScope()->where('applicant_id', $applicant->id)->sole();

    expect($invoice->getAttributes()['total'])->toBeInt()->and($invoice->items()->sole()->getAttributes()['amount'])->toBeInt();
});

it('VERIFY: keeps uploads private and out of the public disk after a replace', function () {
    $applicant = pendaftaranApplicant();
    pendaftaranPayRegistration($applicant);
    $component = Livewire::test(ApplicationStatus::class)->set('registration_no', 'PSB-2027-0001')->set('token', 'ABCD-EFGH-JK23');

    expect(Storage::disk('public')->allFiles())->toBe([])
        ->and(Storage::disk('private')->allFiles())->toBeArray()
        ->and($component)->not->toBeNull();
});

it('VERIFY: does not show the form inputs for a different applicant after the status changed to rejected mid-session', function () {
    $applicant = pendaftaranApplicant(['registration_no' => 'PSB-2027-0001']);
    pendaftaranPayRegistration($applicant);
    $component = Livewire::test(ApplicationStatus::class)->set('registration_no', 'PSB-2027-0001')->set('token', 'ABCD-EFGH-JK23')->call('login');
    $applicant->forceFill(['status' => 'ditolak'])->save();

    $component->set("documents.{$this->f->kk->id}", pendaftaranPdf())->call('uploadDocument', $this->f->kk->id);

    expect(ApplicantDocument::withoutUnitScope()->count())->toBe(0);
});

it('VERIFY: lets a scoped admin register an applicant of another unit through the public form', function () {
    $this->actingAs(psbUser('admin-psb', ['SD']));

    $component = pendaftaranFillWizard(Livewire::test(RegistrationForm::class), ['consent' => true]);
    $this->travel(5)->seconds();
    $component->call('submit');

    expect(Applicant::withoutUnitScope()->count())->toBe(1);
});
