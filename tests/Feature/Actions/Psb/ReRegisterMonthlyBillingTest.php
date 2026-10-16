<?php

use App\Models\FeeOverride;
use App\Models\Invoice;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->f = daftarUlangFixture();
    keuanganRate($this->f->spp, $this->f->y2026, $this->f->sltp, 500000);
});

it('AC-18: starts monthly billing from the entry month', function () {
    $applicant = daftarUlangApplicant($this->f);
    $track = FeeOverride::factory()->forApplicant($applicant->id)->percent(50)->between('2026-10-15', null)->create(['fee_type_id' => $this->f->spp->id]);
    $student = daftarUlangRun($applicant)['student'];

    expect($student->fresh()->entered_on->toDateString())->toBe('2027-07-01');

    foreach (['2026-10-01', '2026-11-01', '2027-06-01'] as $period) {
        tagihanGenerate($period);

        expect(tagihanInvoiceFor($student, 'BULANAN', $period))->toBeNull("Santri baru tidak boleh ditagih bulanan pada {$period}");
    }

    tagihanGenerate('2027-07-01');
    $invoice = tagihanInvoiceFor($student, 'BULANAN', '2027-07-01');
    $item = tagihanItems($invoice)->get($this->f->spp->id);

    expect($invoice)->not->toBeNull()
        ->and($item)->base_amount->toBe(500000)->discount_amount->toBe(250000)->amount->toBe(250000)->fee_override_id->toBe($track->id)
        ->and(Invoice::withoutUnitScope()->where('student_id', $student->id)->where('billing_group', 'PSB_TERIMA')->count())->toBe(1);
});

it('AC-18: starts billing next month when the applicant converts after the school year began', function () {
    $applicant = daftarUlangApplicant($this->f);
    $this->travelTo(CarbonImmutable::parse('2027-08-15 10:00', 'Asia/Jakarta'));
    $student = daftarUlangRun($applicant)['student'];

    expect($student->fresh()->entered_on->toDateString())->toBe('2027-09-01');

    tagihanGenerate('2027-08-01');

    expect(tagihanInvoiceFor($student, 'BULANAN', '2027-08-01'))->toBeNull();

    tagihanGenerate('2027-09-01');

    expect(tagihanInvoiceFor($student, 'BULANAN', '2027-09-01'))->not->toBeNull();
});

it('AC-18: does not clash with the admission invoices that now belong to the student', function () {
    $applicant = daftarUlangApplicant($this->f);
    $student = daftarUlangRun($applicant)['student'];

    tagihanGenerate('2027-07-01');

    expect(Invoice::withoutUnitScope()->where('student_id', $student->id)->where('status', '<>', 'batal')->pluck('billing_group')->sort()->values()->all())
        ->toBe(['BULANAN', 'PSB_DAFTAR', 'PSB_TERIMA']);
});
