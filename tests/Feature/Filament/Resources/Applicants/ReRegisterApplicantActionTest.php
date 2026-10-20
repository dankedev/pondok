<?php

use App\Filament\Resources\Applicants\Pages\ListApplicants;
use App\Models\Applicant;
use App\Models\Invoice;
use App\Models\Student;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->f = daftarUlangFixture();
});

/**
 * Kontrak panel: aksi header `reRegister` pada `ViewApplicant` (form `entered_on`, `nis`, `relation`, `address`),
 * filter tabel `re_registered` (TernaryFilter) pada `ListApplicants`.
 */
function daftarUlangStudentOf(Applicant $applicant): ?Student
{
    return Student::withoutGlobalScopes()->where('applicant_id', $applicant->id)->first();
}

it('AC-32: shows the re-register action only to a user who may use it on an accepted applicant', function () {
    $applicant = daftarUlangApplicant($this->f);

    seleksiView($applicant, $this->f->user)->assertActionVisible('reRegister');

    Applicant::withoutGlobalScopes()->whereKey($applicant->id)->update(['status' => 'diverifikasi']);
    seleksiView($applicant, $this->f->user)->assertActionHidden('reRegister');

    Applicant::withoutGlobalScopes()->whereKey($applicant->id)->update(['status' => 'diterima']);
    $viewer = dashboardUserWith(['view-any:applicant', 'view:applicant', 'decide:applicant']);
    seleksiView($applicant, $viewer)->assertActionHidden('reRegister');

    $allowed = dashboardUserWith(['view-any:applicant', 'view:applicant', 're-register:applicant']);
    seleksiView($applicant, $allowed)->assertActionVisible('reRegister');
});

it('AC-32: hides the re-register action after the conversion', function () {
    $applicant = daftarUlangApplicant($this->f);
    daftarUlangRun($applicant);

    seleksiView($applicant, $this->f->user)->assertActionHidden('reRegister');
});

it('AC-32: opens the form with the default entry date and relation, and converts with them', function () {
    $applicant = daftarUlangApplicant($this->f);
    $component = seleksiView($applicant, $this->f->user);

    $component->mountAction('reRegister')
        ->assertActionDataSet(fn (array $state): bool => str_starts_with((string) ($state['entered_on'] ?? ''), '2027-07-01') && ($state['relation'] ?? null) === 'wali');

    seleksiView($applicant, $this->f->user)->callAction('reRegister');

    $student = daftarUlangStudentOf($applicant);

    expect($student)->not->toBeNull()
        ->and($student->entered_on->toDateString())->toBe('2027-07-01')
        ->and(DB::table('student_guardian')->where('student_id', $student->id)->value('relation'))->toBe('wali');
});

it('AC-32: converts through the form, notifies with the student number, and hides the action afterwards', function () {
    $applicant = daftarUlangApplicant($this->f);

    seleksiView($applicant, $this->f->user)
        ->callAction('reRegister', ['relation' => 'ibu', 'address' => 'Jl. Melati 5', 'entered_on' => '2026-11-01'])
        ->assertHasNoActionErrors();

    $student = daftarUlangStudentOf($applicant);
    $notifications = seleksiNotificationText();

    expect($student?->name)->toBe('Ahmad Fauzan')
        ->and($student?->address)->toBe('Jl. Melati 5')
        ->and($student->entered_on->toDateString())->toBe('2026-11-01')
        ->and(DB::table('student_guardian')->where('student_id', $student->id)->value('relation'))->toBe('ibu')
        ->and($notifications)->toContain(seleksiText('pondok.applicant.re_registered', ['name' => 'Ahmad Fauzan', 'nis' => $student->nis]))
        ->toContain(seleksiText('pondok.applicant.re_registered_next_step'));

    seleksiView(Applicant::withoutGlobalScopes()->findOrFail($applicant->id), $this->f->user)->assertActionHidden('reRegister');
});

it('AC-32: uses a manual student number from the form', function () {
    $applicant = daftarUlangApplicant($this->f);

    seleksiView($applicant, $this->f->user)->callAction('reRegister', ['nis' => 'PK-2027-01']);

    expect(daftarUlangStudentOf($applicant)?->nis)->toBe('PK-2027-01');
});

it('AC-32: warns about documents that were not copied', function () {
    $applicant = daftarUlangApplicant($this->f);
    daftarUlangRequirement('SK_STAFF', 'staff');
    daftarUlangValidDocument($applicant, 'SK_STAFF');

    seleksiView($applicant, $this->f->user)->callAction('reRegister');

    $notifications = asramaNotifications();

    expect(daftarUlangStudentOf($applicant))->not->toBeNull()
        ->and($notifications->pluck('status')->all())->toContain('success')->toContain('warning')
        ->and($notifications->map(fn (array $notification): string => ($notification['title'] ?? '').' '.($notification['body'] ?? ''))->implode(' '))->toContain('Syarat SK_STAFF');
});

it('AC-32: notifies a danger message when the payment is too low', function () {
    $applicant = daftarUlangApplicant($this->f, paid: 1000000);

    $component = seleksiView($applicant, $this->f->user)->callAction('reRegister');

    expect(seleksiSeeMessage($component, 'min_payment_not_met'))->toBeTrue()
        ->and(daftarUlangStudentOf($applicant))->toBeNull()
        ->and(Student::withoutGlobalScopes()->count())->toBe(0);
});

it('AC-32: raises a danger notification when the payment is too low', function () {
    $applicant = daftarUlangApplicant($this->f, paid: 1000000);

    seleksiView($applicant, $this->f->user)->callAction('reRegister');

    expect(asramaNotifications()->pluck('status')->all())->toContain('danger');
});

it('AC-32: shows an error on the entry date field when it is too early', function () {
    $applicant = daftarUlangApplicant($this->f);

    seleksiView($applicant, $this->f->user)
        ->callAction('reRegister', ['entered_on' => '2026-10-31'])
        ->assertHasActionErrors(['entered_on']);

    expect(daftarUlangStudentOf($applicant))->toBeNull();
});

it('AC-33: shows the payment status in the re-registration section before the conversion', function () {
    $ready = daftarUlangApplicant($this->f);
    $text = tagihanPlainText(seleksiView($ready, $this->f->user));

    expect($text)->toContain('Rp 4.000.000 dari Rp 8.000.000 (50%)')
        ->toContain('50%')
        ->toContain('Memenuhi syarat')
        ->not->toContain('Sudah daftar ulang');

    $unpaid = daftarUlangApplicant($this->f, paid: 0);
    $unpaidText = tagihanPlainText(seleksiView($unpaid, $this->f->user));

    expect($unpaidText)->toContain('Belum memenuhi')->not->toContain('Memenuhi syarat');
});

it('AC-12: shows the required minimum in rupiah with a note when it is unset or capped', function (?int $minimum, int $total, string $required, ?string $note) {
    daftarUlangMinimum('SLTP', $minimum);
    $applicant = daftarUlangApplicant($this->f, paid: 0);
    Invoice::withoutUnitScope()->whereKey(seleksiInvoices($applicant, group: 'PSB_TERIMA')->sole()->id)->update(['total' => $total]);

    $text = tagihanPlainText(seleksiView($applicant, $this->f->user));

    expect($text)->toContain('Syarat minimal')->toContain($required)
        ->and($text)->not->toContain('% dari')
        ->and(str_contains($text, 'belum diatur, jadi wajib lunas'))->toBe($note === 'belum diatur')
        ->and(str_contains($text, 'cukup lunas'))->toBe($note === 'cukup lunas');
})->with([
    'nominal set' => [3000000, 8000000, 'Rp 3.000.000', null],
    'nominal unset' => [null, 8000000, 'Rp 8.000.000', 'belum diatur'],
    'total below nominal' => [10000000, 2000000, 'Rp 2.000.000', 'cukup lunas'],
]);

it('AC-7: names the rupiah amounts in the notification when the payment is too low', function () {
    daftarUlangMinimum('SLTP', 3000000);
    $applicant = daftarUlangApplicant($this->f, paid: 1000000);

    seleksiView($applicant, $this->f->user)->callAction('reRegister');

    expect(seleksiNotificationText())->toContain('Rp 1.000.000')->toContain('Rp 3.000.000');
});

it('AC-33: explains that the acceptance invoices are not created yet', function () {
    $applicant = daftarUlangApplicant($this->f, invoices: false);

    $text = tagihanPlainText(seleksiView($applicant, $this->f->user));

    expect($text)->toContain('Belum memenuhi')->toContain(seleksiText('pondok.applicant.acceptance_invoices_not_created'));
});

it('AC-33: shows the student after the conversion, as a link only to a user who may edit students', function () {
    $applicant = daftarUlangApplicant($this->f);
    $student = daftarUlangRun($applicant)['student'];
    $editUrl = "/admin/students/{$student->id}/edit";

    $asAdmin = seleksiView($applicant, $this->f->user);
    $adminText = tagihanPlainText($asAdmin);

    expect($adminText)->toContain('Sudah daftar ulang')->toContain("{$student->nis} — Ahmad Fauzan")->toContain('1 Jul 2027')
        ->and($asAdmin->html())->not->toContain($editUrl);

    $asSuper = seleksiView($applicant, psbUser('super-admin'));

    expect(tagihanPlainText($asSuper))->toContain('Sudah daftar ulang')->toContain("{$student->nis} — Ahmad Fauzan")
        ->and($asSuper->html())->toContain($editUrl);
});

it('AC-33: hides billing the acceptance after the conversion even when a fee group has no invoice', function () {
    $applicant = daftarUlangApplicant($this->f);
    $type = psbFeeType('saat_diterima', ['code' => 'KITAB2', 'name' => 'Kitab tambahan', 'billing_group' => 'PSB_KITAB2', 'priority' => 60]);
    keuanganRate($type, $this->f->y2027, $this->f->sltp, 300000);

    seleksiView($applicant, $this->f->user)->assertActionVisible('generateAcceptanceInvoices');

    daftarUlangRun($applicant);

    seleksiView($applicant, $this->f->user)->assertActionHidden('generateAcceptanceInvoices');
});

it('AC-34: lets an admin scoped to SLTP re-register an SLTP applicant but not open an SD applicant', function () {
    $scoped = psbUser('admin-psb', ['SLTP']);
    $applicant = daftarUlangApplicant($this->f);
    $sd = daftarUlangApplicant($this->f, unit: 'SD', invoices: false);

    seleksiView($applicant, $scoped)->callAction('reRegister');

    expect(Student::query()->where('applicant_id', $applicant->id)->exists())->toBeTrue()
        ->and(daftarUlangStudentOf($applicant)->unit_id)->toBe($this->f->sltp->id);

    $this->actingAs($scoped)->get("/admin/applicants/{$sd->id}")->assertNotFound();

    expect(daftarUlangStudentOf($sd))->toBeNull();
});

it('AC-35: filters the list by re-registration', function () {
    $converted = daftarUlangApplicant($this->f);
    daftarUlangRun($converted);
    $trashed = daftarUlangApplicant($this->f);
    daftarUlangRun($trashed)['student']->delete();
    $waiting = daftarUlangApplicant($this->f);
    $submitted = pendaftaranApplicant(['status' => 'diajukan']);

    $this->actingAs($this->f->user);

    Livewire::test(ListApplicants::class)
        ->filterTable('re_registered', true)
        ->assertCanSeeTableRecords([$converted, $trashed])
        ->assertCanNotSeeTableRecords([$waiting, $submitted]);

    Livewire::test(ListApplicants::class)
        ->filterTable('re_registered', false)
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$converted, $trashed, $submitted]);
});

it('VERIFY F040: infolist shows Rp 0 for a zero minimum and hides the row while acceptance invoices are missing', function () {
    daftarUlangMinimum('SLTP', 0);
    $applicant = daftarUlangApplicant($this->f, paid: 0);

    expect(tagihanPlainText(seleksiView($applicant, $this->f->user)))->toContain('Syarat minimal')->toContain('Rp 0');

    $missing = daftarUlangApplicant($this->f, invoices: false);

    expect(tagihanPlainText(seleksiView($missing, $this->f->user)))->not->toContain('Syarat minimal');
});
