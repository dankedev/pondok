<?php

use App\Actions\Keuangan\CancelPayment;
use App\Actions\Psb\CheckReRegistrationPayment;
use App\Livewire\Psb\ApplicationStatus;
use App\Models\Applicant;
use App\Models\Document;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\RoomAssignment;
use App\Models\Setting;
use App\Models\Student;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->f = daftarUlangFixture();
});

it('VERIFY: does not count a cancelled payment toward the minimum', function () {
    $applicant = daftarUlangApplicant($this->f, paid: 4000000);
    $payment = Payment::withoutGlobalScopes()->where('invoice_id', seleksiInvoices($applicant, group: 'PSB_TERIMA')->sole()->id)->sole();
    app(CancelPayment::class)->handle($payment, $this->f->user, 'Salah catat');
    $before = daftarUlangSnapshot();

    daftarUlangAssertRejected(fn () => daftarUlangRun($applicant), 'min_payment_not_met', 'payment');

    expect(daftarUlangSnapshot())->toBe($before);
});

it('VERIFY: compares the percent with integer arithmetic at the boundary', function (int $total, int $paid, int $required, bool $meets) {
    Setting::set('psb.min_paid_percent', $required);
    $applicant = daftarUlangApplicant($this->f, paid: 0);
    Invoice::withoutUnitScope()->whereKey(seleksiInvoices($applicant, group: 'PSB_TERIMA')->sole()->id)->update(['total' => $total, 'paid_total' => $paid]);

    expect(app(CheckReRegistrationPayment::class)->handle($applicant)['meets'])->toBe($meets);
})->with([
    'exactly the percent' => [3, 1, 33, true],
    'one rupiah short' => [10000001, 5000000, 50, false],
    'exactly half of an odd total' => [10000002, 5000001, 50, true],
    'rounds down for display but fails' => [200, 99, 50, false],
]);

it('VERIFY: converts two siblings onto one guardian and keeps both as billing contact', function () {
    $first = daftarUlangRun(daftarUlangApplicant($this->f));
    $second = daftarUlangRun(daftarUlangApplicant($this->f), ['relation' => 'ayah']);

    expect($second['guardian_reused'])->toBeTrue()
        ->and($second['guardian']->id)->toBe($first['guardian']->id)
        ->and(Guardian::query()->count())->toBe(1)
        ->and(DB::table('student_guardian')->where('guardian_id', $first['guardian']->id)->where('is_billing_contact', true)->count())->toBe(2)
        ->and(DB::table('student_guardian')->where('student_id', $first['student']->id)->value('relation'))->toBe('wali')
        ->and($first['student']->nis)->not->toBe($second['student']->nis);
});

it('VERIFY: does not place the student in a classroom or room', function () {
    daftarUlangRun(daftarUlangApplicant($this->f));

    expect(Enrollment::query()->count())->toBe(0)->and(RoomAssignment::query()->count())->toBe(0);
});

it('VERIFY: never deletes finance data and keeps the restrict foreign keys', function () {
    $applicant = daftarUlangApplicant($this->f);
    $student = daftarUlangRun($applicant)['student'];
    $invoice = seleksiInvoices($applicant, group: 'PSB_TERIMA')->sole();

    expect(fn () => DB::table('students')->where('id', $student->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('invoices')->where('id', $invoice->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('applicants')->where('id', $applicant->id)->delete())->toThrow(QueryException::class);
});

it('VERIFY: copies nothing that is not valid and leaves private source files in place', function (string $status) {
    $applicant = daftarUlangApplicant($this->f);
    daftarUlangRequirement('PROPOSAL', 'santri');
    $source = daftarUlangValidDocument($applicant, 'PROPOSAL', $status);

    $result = daftarUlangRun($applicant);

    expect($result['documents_copied'])->toBe(2)
        ->and(Document::withoutUnitScope()->where('source_applicant_document_id', $source->id)->exists())->toBeFalse()
        ->and(Storage::disk('private')->exists($source->file_path))->toBeTrue()
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    foreach (Document::withoutUnitScope()->get() as $document) {
        expect($document->file_path)->not->toStartWith('public')->and(Storage::disk('public')->exists($document->file_path))->toBeFalse();
    }
})->with(['menunggu', 'ditolak']);

it('VERIFY: keeps an SD applicant closed to a super-admin scoped to SLTP', function () {
    Filament::setCurrentPanel('admin');
    $super = psbUser('super-admin', ['SLTP']);
    $sd = daftarUlangApplicant($this->f, unit: 'SD', invoices: false);
    $sltp = daftarUlangApplicant($this->f);

    $this->actingAs($super)->get("/admin/applicants/{$sd->id}")->assertNotFound();

    seleksiView($sltp, $super)->callAction('reRegister');

    expect(Student::withoutGlobalScopes()->where('applicant_id', $sltp->id)->exists())->toBeTrue();
});

it('VERIFY: gives no re-register action to roles without the permission', function (string $role) {
    Filament::setCurrentPanel('admin');
    $applicant = daftarUlangApplicant($this->f);
    $user = psbUser($role);

    $this->actingAs($user)->get("/admin/applicants/{$applicant->id}")->assertForbidden();
})->with(['bendahara', 'admin-sekolah', 'kesantrian', 'admin-konten']);

it('VERIFY: does not leak the student number on the public status page without the token', function () {
    $this->withoutVite();
    $applicant = daftarUlangApplicant($this->f);
    $student = daftarUlangRun($applicant)['student'];

    $this->get('/psb/status')->assertOk()->assertDontSee($student->nis)->assertDontSee('Ahmad Fauzan');

    $component = Livewire\Livewire::test(ApplicationStatus::class)
        ->set('registration_no', $applicant->registration_no)->set('token', 'SALAH-SALAH-SALAH')->call('login');

    expect(tagihanPlainText($component))->not->toContain($student->nis)->not->toContain('Daftar ulang selesai');
});

it('VERIFY: logs nothing personal in the document copy and conversion activities', function () {
    daftarUlangRun(daftarUlangApplicant($this->f));

    $json = Activity::query()->whereIn('event', ['converted', 'applicant_billing_transferred', 'copied_from_applicant'])->get()->map(fn ($a) => json_encode($a->properties).$a->description)->implode(' ');

    expect($json)->not->toContain('Ahmad')->not->toContain('Budi')->not->toContain('0123456789')->not->toContain('081234567890')->not->toContain('asli.pdf');
});

it('VERIFY: a failed conversion leaves the applicant still convertible afterwards', function () {
    $applicant = daftarUlangApplicant($this->f, paid: 1000000);

    daftarUlangAssertRejected(fn () => daftarUlangRun($applicant), 'min_payment_not_met', 'payment');
    bayarRecord(seleksiInvoices($applicant, group: 'PSB_TERIMA')->sole(), 3000000);

    expect(daftarUlangRun($applicant)['student'])->toBeInstanceOf(Student::class)
        ->and(Applicant::withoutGlobalScopes()->findOrFail($applicant->id)->status)->toBe('diterima');
});
