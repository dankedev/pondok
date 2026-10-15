<?php

use App\Actions\Keuangan\GenerateMonthlyInvoices;
use App\Actions\Keuangan\ResolveFeeOverride;
use App\Actions\Keuangan\ResolveFeeRate;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->f = tagihanFixture();
});

/*
| Gabungan / terpisah
*/

it('AC-7: combines fee types of one billing group into one invoice', function () {
    $f = $this->f;
    $student = tagihanStudent($f, resident: true);

    $result = tagihanGenerate();

    $invoice = Invoice::withoutUnitScope()->where('student_id', $student->id)->sole();

    expect($result['created_invoices'])->toBe(1)
        ->and($invoice->items()->count())->toBe(3)
        ->and($invoice->total)->toBe(1200000)
        ->and($invoice->status)->toBe('belum')
        ->and($invoice->period->toDateString())->toBe('2026-10-01')
        ->and($invoice->academic_year_id)->toBe($f->year->id)
        ->and($invoice->due_on->toDateString())->toBe('2026-10-10')
        ->and($invoice->billing_group)->toBe('BULANAN')
        ->and($invoice->generated_by)->toBeNull()
        ->and($invoice->paid_total)->toBe(0);
});

it('AC-7: reads the due day from settings and falls back for values outside 1-28', function (mixed $setting, string $expected) {
    tagihanStudent($this->f);
    Setting::set('keuangan.due_day', $setting);

    tagihanGenerate();

    expect(Invoice::withoutUnitScope()->sole()->due_on->toDateString())->toBe($expected);
})->with([
    'custom day' => [5, '2026-10-05'],
    'last allowed day' => [28, '2026-10-28'],
    'too high' => [31, '2026-10-10'],
    'zero' => [0, '2026-10-10'],
]);

it('AC-7: returns an empty result when no monthly fee type is active', function () {
    FeeType::query()->update(['is_active' => false]);
    tagihanStudent($this->f);

    $result = tagihanGenerate();

    expect($result)->toMatchArray(['period' => '2026-10-01', 'created_invoices' => 0, 'added_items' => 0, 'existing_invoices' => 0, 'skipped' => []])
        ->and(Invoice::withoutUnitScope()->count())->toBe(0);
});

it('AC-8: splits invoices per billing group', function (array $groups, array $expected) {
    $f = $this->f;
    $f->spp->update(['billing_group' => $groups[0]]);
    $f->asrama->update(['billing_group' => $groups[1]]);
    $f->makan->update(['billing_group' => $groups[2]]);
    $student = tagihanStudent($f, resident: true);

    $result = tagihanGenerate();

    $invoices = Invoice::withoutUnitScope()->where('student_id', $student->id)->get()->keyBy('billing_group');

    expect($result['created_invoices'])->toBe(count($expected))
        ->and($invoices->keys()->sort()->values()->all())->toBe(collect($expected)->keys()->sort()->values()->all());

    foreach ($expected as $group => [$itemCount, $total]) {
        expect($invoices[$group]->items()->count())->toBe($itemCount)
            ->and($invoices[$group]->total)->toBe($total);
    }
})->with([
    'two groups' => [['SPP', 'ASRAMA', 'ASRAMA'], ['SPP' => [1, 500000], 'ASRAMA' => [2, 700000]]],
    'three groups' => [['SPP', 'ASRAMA', 'MAKAN'], ['SPP' => [1, 500000], 'ASRAMA' => [1, 300000], 'MAKAN' => [1, 400000]]],
]);

it('AC-9: snapshots item amounts and the jenjang rate source', function () {
    $f = $this->f;
    $student = tagihanStudent($f);

    tagihanGenerate();

    $item = tagihanItems(tagihanInvoiceFor($student))->sole();

    expect($item->fee_type_id)->toBe($f->spp->id)
        ->and($item->description)->toBe('SPP Oktober 2026')
        ->and($item->base_amount)->toBe(500000)
        ->and($item->discount_amount)->toBe(0)
        ->and($item->amount)->toBe(500000)
        ->and($item->rate_source)->toBe('jenjang')
        ->and($item->fee_override_id)->toBeNull()
        ->and($item->paid_amount)->toBe(0);
});

it('AC-9: marks a cohort rate with the angkatan rate source', function () {
    $f = $this->f;
    keuanganRate($f->spp, $f->year, $f->sltp, 550000, $f->cohort);
    $student = tagihanStudent($f);

    tagihanGenerate();

    $item = tagihanItems(tagihanInvoiceFor($student))->sole();

    expect($item->amount)->toBe(550000)->and($item->rate_source)->toBe('angkatan');
});

it('AC-10: resolves the dormitory rate from the active room', function () {
    $f = $this->f;
    keuanganRate($f->asrama, $f->year, $f->sltp, 350000, null, $f->dormA);
    $inA = tagihanStudent($f, resident: true, dormitory: $f->dormA);
    $inB = tagihanStudent($f, resident: true, dormitory: $f->dormB);

    tagihanGenerate();

    expect(tagihanItems(tagihanInvoiceFor($inA))[$f->asrama->id]->amount)->toBe(350000)
        ->and(tagihanItems(tagihanInvoiceFor($inB))[$f->asrama->id]->amount)->toBe(300000);
});

it('AC-11: numbers invoices per period', function () {
    $f = $this->f;
    tagihanStudent($f);
    tagihanStudent($f);
    tagihanStudent($f);

    tagihanGenerate();

    expect(Invoice::withoutUnitScope()->pluck('invoice_no')->sort()->values()->all())
        ->toBe(['INV-202610-00001', 'INV-202610-00002', 'INV-202610-00003']);

    tagihanGenerate('2026-11-01');

    expect(Invoice::withoutUnitScope()->whereDate('period', '2026-11-01')->pluck('invoice_no')->sort()->values()->all())
        ->toBe(['INV-202611-00001', 'INV-202611-00002', 'INV-202611-00003']);
});

it('AC-11: continues after the highest existing number of the period', function () {
    $f = $this->f;
    $other = tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta);
    tagihanInvoice($other, $f->year, ['billing_group' => 'LAIN', 'invoice_no' => 'INV-202610-00007']);
    $student = tagihanStudent($f);

    tagihanGenerate();

    expect(tagihanInvoiceFor($student)->invoice_no)->toBe('INV-202610-00008');
});

/*
| Mukim / nonmukim dan sasaran santri
*/

it('AC-12: does not bill resident fees to non-residents', function () {
    $f = $this->f;
    $never = tagihanStudent($f);
    $ended = tagihanStudent($f);
    asramaResident($ended, asramaRoom(10, 'L', [], $f->dormA), ['start_on' => '2026-07-15', 'end_on' => '2026-08-31']);

    tagihanGenerate();

    foreach ([$never, $ended] as $student) {
        $invoice = tagihanInvoiceFor($student);

        expect(tagihanItems($invoice)->keys()->all())->toBe([$f->spp->id])
            ->and($invoice->total)->toBe(500000);
    }
});

it('AC-13: bills nonmukim fees only to non-residents', function () {
    $f = $this->f;
    $transport = FeeType::factory()->monthly()->nonResident()->create(['code' => 'TRANSPORT', 'name' => 'Transport', 'billing_group' => 'BULANAN', 'priority' => 4]);
    keuanganRate($transport, $f->year, $f->sltp, 100000);
    $resident = tagihanStudent($f, resident: true);
    $commuter = tagihanStudent($f);

    tagihanGenerate();

    expect(tagihanItems(tagihanInvoiceFor($resident))->keys()->sort()->values()->all())
        ->toBe(collect([$f->spp->id, $f->asrama->id, $f->makan->id])->sort()->values()->all())
        ->and(tagihanItems(tagihanInvoiceFor($commuter))->keys()->sort()->values()->all())
        ->toBe(collect([$f->spp->id, $transport->id])->sort()->values()->all());
});

it('AC-14: bills only active students who entered by the period', function () {
    $f = $this->f;
    $billed = [
        'active' => tagihanStudent($f),
        'entered on the last day' => tagihanStudent($f, attributes: ['entered_on' => '2026-10-31']),
        'entered mid month' => tagihanStudent($f, attributes: ['entered_on' => '2026-10-20']),
    ];
    $notBilled = [
        'graduated' => tagihanStudent($f, attributes: ['status' => 'lulus']),
        'left' => tagihanStudent($f, attributes: ['status' => 'keluar']),
        'moved' => tagihanStudent($f, attributes: ['status' => 'pindah']),
        'entered next month' => tagihanStudent($f, attributes: ['entered_on' => '2026-11-01']),
    ];
    $deleted = tagihanStudent($f);
    $deleted->delete();

    tagihanGenerate();

    foreach ($billed as $label => $student) {
        expect(tagihanInvoiceFor($student))->not->toBeNull("{$label} harus ditagih");
    }

    foreach ([...$notBilled, 'soft deleted' => $deleted] as $label => $student) {
        expect(Invoice::withoutUnitScope()->where('student_id', $student->id)->exists())->toBeFalse("{$label} tidak boleh ditagih");
    }
});

it('AC-15: ignores inactive and non-monthly fee types', function (string $frequency) {
    $f = $this->f;
    $inactive = FeeType::factory()->monthly()->inactive()->create(['code' => 'OFF', 'name' => 'Nonaktif', 'billing_group' => 'BULANAN']);
    $other = FeeType::factory()->create(['code' => 'LAIN', 'name' => 'Lain', 'frequency' => $frequency, 'billing_group' => 'BULANAN']);
    keuanganRate($inactive, $f->year, $f->sltp, 10000);
    keuanganRate($other, $f->year, $f->sltp, 20000);
    $student = tagihanStudent($f);

    tagihanGenerate();

    expect(tagihanItems(tagihanInvoiceFor($student))->keys()->all())->toBe([$f->spp->id]);
})->with(['tahunan', 'saat_daftar', 'saat_diterima', 'manual']);

it('AC-16: ignores the unit scope of the logged-in user', function () {
    $this->seed(RolePermissionSeeder::class);
    $f = $this->f;
    keuanganRate($f->spp, $f->year, $f->slta, 450000);
    $sltp = tagihanStudent($f);
    $slta = tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta);
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    $result = tagihanGenerate();

    expect($result['created_invoices'])->toBe(2)
        ->and(tagihanInvoiceFor($sltp))->not->toBeNull()
        ->and(tagihanItems(tagihanInvoiceFor($slta))->sole()->amount)->toBe(450000);
});

/*
| Keringanan
*/

it('AC-17: applies the three override modes', function (string $mode, int $value, array $expected) {
    $f = $this->f;
    $student = tagihanStudent($f);
    $override = keringananOverride(['student_id' => $student->id, 'fee_type_id' => $f->spp->id, 'mode' => $mode, 'value' => $value, 'valid_from' => '2026-07-01', 'valid_until' => null]);

    tagihanGenerate();

    $item = tagihanItems(tagihanInvoiceFor($student))->sole();

    expect($item->base_amount)->toBe($expected[0])
        ->and($item->discount_amount)->toBe($expected[1])
        ->and($item->amount)->toBe($expected[2])
        ->and($item->fee_override_id)->toBe($override->id);
})->with([
    'potongan' => ['potongan', 100000, [500000, 100000, 400000]],
    'persen' => ['persen', 50, [500000, 250000, 250000]],
    'nominal free' => ['nominal', 0, [500000, 500000, 0]],
]);

it('AC-18: applies overrides that overlap the billing month', function (string $from, ?string $until, bool $applied) {
    $f = $this->f;
    $student = tagihanStudent($f);
    keringananOverride(['student_id' => $student->id, 'fee_type_id' => $f->spp->id, 'mode' => 'potongan', 'value' => 100000, 'valid_from' => $from, 'valid_until' => $until]);

    tagihanGenerate();

    expect(tagihanItems(tagihanInvoiceFor($student))->sole()->amount)->toBe($applied ? 400000 : 500000);
})->with([
    'ended before the month' => ['2026-01-01', '2026-09-30', false],
    'starts after the month' => ['2026-11-01', null, false],
    'ends inside the month' => ['2026-07-01', '2026-10-05', true],
]);

it('AC-19: marks a fully free invoice as paid', function () {
    $f = $this->f;
    $student = tagihanStudent($f, resident: true);

    foreach ([$f->spp, $f->asrama, $f->makan] as $type) {
        keringananOverride(['student_id' => $student->id, 'fee_type_id' => $type->id, 'mode' => 'nominal', 'value' => 0, 'valid_from' => '2026-07-01', 'valid_until' => null]);
    }

    tagihanGenerate();

    $invoice = tagihanInvoiceFor($student);

    expect($invoice->total)->toBe(0)
        ->and($invoice->status)->toBe('lunas')
        ->and($invoice->items()->count())->toBe(3)
        ->and($invoice->items()->sum('amount'))->toBe(0);
});

/*
| Tanpa tarif
*/

it('AC-20: skips and logs a fee type without a rate', function () {
    $f = $this->f;
    FeeRate::query()->where('fee_type_id', $f->asrama->id)->delete();
    $student = tagihanStudent($f, resident: true);
    Log::spy();

    $result = tagihanGenerate();

    $invoice = tagihanInvoiceFor($student);

    expect(tagihanItems($invoice)->keys()->sort()->values()->all())->toBe(collect([$f->spp->id, $f->makan->id])->sort()->values()->all())
        ->and($invoice->total)->toBe(900000)
        ->and($result['skipped'])->toContain(['student_id' => $student->id, 'fee_type_id' => $f->asrama->id, 'reason' => 'missing_rate']);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => ($context['student_id'] ?? null) === $student->id
        && ($context['fee_type_id'] ?? null) === $f->asrama->id
        && ($context['reason'] ?? null) === 'missing_rate'
        && array_key_exists('period', $context))->once();
});

it('AC-21: creates no empty invoice when nothing resolves', function () {
    $f = $this->f;
    $billed = tagihanStudent($f);
    $unrated = tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta);
    Log::spy();

    $result = tagihanGenerate();

    expect(Invoice::withoutUnitScope()->where('student_id', $unrated->id)->exists())->toBeFalse()
        ->and(tagihanInvoiceFor($billed))->not->toBeNull()
        ->and($result['created_invoices'])->toBe(1)
        ->and($result['skipped'])->toContain(['student_id' => $unrated->id, 'fee_type_id' => $f->spp->id, 'reason' => 'missing_rate']);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => ($context['student_id'] ?? null) === $unrated->id)->atLeast()->once();
});

it('AC-22: bills a nominal override without a rate as manual', function () {
    $f = $this->f;
    $student = tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta);
    $override = keringananOverride(['student_id' => $student->id, 'fee_type_id' => $f->spp->id, 'mode' => 'nominal', 'value' => 250000, 'valid_from' => '2026-07-01', 'valid_until' => null]);

    $result = tagihanGenerate();

    $item = tagihanItems(tagihanInvoiceFor($student))->sole();

    expect($result['skipped'])->toBe([])
        ->and($item->base_amount)->toBe(250000)
        ->and($item->discount_amount)->toBe(0)
        ->and($item->amount)->toBe(250000)
        ->and($item->rate_source)->toBe('manual')
        ->and($item->fee_override_id)->toBe($override->id);
});

it('AC-22: skips a discount or percent override without a rate', function (string $mode, int $value) {
    $f = $this->f;
    $student = tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta);
    keringananOverride(['student_id' => $student->id, 'fee_type_id' => $f->spp->id, 'mode' => $mode, 'value' => $value, 'valid_from' => '2026-07-01', 'valid_until' => null]);

    $result = tagihanGenerate();

    expect(Invoice::withoutUnitScope()->where('student_id', $student->id)->exists())->toBeFalse()
        ->and($result['skipped'])->toContain(['student_id' => $student->id, 'fee_type_id' => $f->spp->id, 'reason' => 'missing_rate']);
})->with([['potongan', 100000], ['persen', 50]]);

/*
| Prasyarat, kinerja, audit
*/

it('AC-29: rejects a period outside any academic year', function () {
    tagihanStudent($this->f);

    tagihanAssertRejected(fn () => tagihanGenerate('2030-01-01'), 'no_academic_year', 'period');

    expect(Invoice::withoutUnitScope()->count())->toBe(0);
});

it('AC-30: refuses to run twice for the same period and releases the lock afterwards', function () {
    tagihanStudent($this->f);
    $lock = Cache::lock('keuangan:generate-invoices:2026-10', 60);
    expect($lock->get())->toBeTrue();

    tagihanAssertRejected(fn () => tagihanGenerate(), 'already_running', 'period');

    expect(Invoice::withoutUnitScope()->count())->toBe(0);

    $lock->release();

    expect(tagihanGenerate()['created_invoices'])->toBe(1);

    $again = Cache::lock('keuangan:generate-invoices:2026-10', 60);
    expect($again->get())->toBeTrue();
    $again->release();
});

it('AC-30: allows another period while one period is locked', function () {
    tagihanStudent($this->f);
    $lock = Cache::lock('keuangan:generate-invoices:2026-10', 60);
    $lock->get();

    expect(tagihanGenerate('2026-11-01')['created_invoices'])->toBe(1);

    $lock->release();
});

it('AC-30: releases the lock when the run fails', function () {
    tagihanStudent($this->f);
    $this->mock(ResolveFeeRate::class, fn (MockInterface $mock) => $mock->shouldReceive('handle')->andThrow(new RuntimeException('gagal')));

    expect(fn () => tagihanGenerate())->toThrow(RuntimeException::class, 'gagal');

    $lock = Cache::lock('keuangan:generate-invoices:2026-10', 60);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('AC-31: normalises the period to the first day', function () {
    tagihanStudent($this->f);

    $result = tagihanGenerate('2026-10-17');

    expect($result['period'])->toBe('2026-10-01')
        ->and(Invoice::withoutUnitScope()->sole()->period->toDateString())->toBe('2026-10-01')
        ->and(Invoice::withoutUnitScope()->sole()->invoice_no)->toStartWith('INV-202610-');
});

it('AC-32: memoises rate resolution per fee type and student profile', function () {
    $f = $this->f;
    $this->partialMock(ResolveFeeRate::class, fn (MockInterface $mock) => $mock->shouldReceive('handle')->passthru()->times(3));

    foreach (range(1, 10) as $ignored) {
        tagihanStudent($f, resident: true);
    }

    $result = tagihanGenerate();

    expect($result['created_invoices'])->toBe(10)
        ->and((int) Invoice::withoutUnitScope()->sum('total'))->toBe(12000000);
});

it('AC-32: resolves overrides only for pairs with an overlapping override', function () {
    $f = $this->f;
    $students = collect(range(1, 10))->map(fn () => tagihanStudent($f, resident: true));
    keringananOverride(['student_id' => $students[0]->id, 'fee_type_id' => $f->spp->id, 'mode' => 'potongan', 'value' => 100000, 'valid_from' => '2026-07-01', 'valid_until' => null]);
    keringananOverride(['student_id' => $students[1]->id, 'fee_type_id' => $f->makan->id, 'mode' => 'potongan', 'value' => 50000, 'valid_from' => '2026-10-15', 'valid_until' => null]);
    keringananOverride(['student_id' => $students[2]->id, 'fee_type_id' => $f->spp->id, 'mode' => 'potongan', 'value' => 100000, 'valid_from' => '2026-01-01', 'valid_until' => '2026-09-30']);
    $this->partialMock(ResolveFeeOverride::class, fn (MockInterface $mock) => $mock->shouldReceive('forStudent')->passthru()->times(2));

    tagihanGenerate();

    expect(tagihanItems(tagihanInvoiceFor($students[0]))[$f->spp->id]->amount)->toBe(400000)
        ->and(tagihanItems(tagihanInvoiceFor($students[1]))[$f->makan->id]->amount)->toBe(350000)
        ->and(tagihanItems(tagihanInvoiceFor($students[2]))[$f->spp->id]->amount)->toBe(500000);
});

it('AC-33: keeps select queries flat per student', function () {
    $f = $this->f;
    $countSelects = function (string $period): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        tagihanGenerate($period);
        DB::disableQueryLog();

        return collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select'))
            ->count();
    };

    foreach (range(1, 10) as $ignored) {
        tagihanStudent($f, resident: true);
    }

    $countSelects('2026-09-01');
    $withTen = $countSelects('2026-10-01');

    foreach (range(1, 10) as $ignored) {
        tagihanStudent($f, resident: true);
    }

    $withTwenty = $countSelects('2026-11-01');

    expect(Invoice::withoutUnitScope()->whereDate('period', '2026-11-01')->count())->toBe(20)
        ->and($withTwenty)->toBe($withTen);
});

it('AC-34: logs one summary activity per run without a causer', function () {
    $f = $this->f;
    tagihanStudent($f, resident: true);
    tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta);

    $result = tagihanGenerate();

    $activity = Activity::query()->where('log_name', 'keuangan')->where('event', 'generated')->sole();

    expect($activity->causer_id)->toBeNull()
        ->and($activity->properties->toArray())->toMatchArray([
            'period' => '2026-10-01',
            'created_invoices' => 1,
            'added_items' => 0,
            'existing_invoices' => 0,
            'skipped_count' => 1,
        ])
        ->and($result['created_invoices'])->toBe(1)
        ->and(Activity::query()->where('subject_type', (new Invoice)->getMorphClass())->count())->toBe(0);
});

it('AC-34: records the causer of a panel run', function () {
    tagihanStudent($this->f);
    $user = User::factory()->create();

    tagihanGenerate('2026-10-01', $user);

    $activity = Activity::query()->where('event', 'generated')->sole();

    expect($activity->causer_id)->toBe($user->id)->and($activity->causer_type)->toBe($user->getMorphClass());
});

it('AC-35: records the generating user on each invoice', function () {
    $f = $this->f;
    $user = User::factory()->create();
    tagihanStudent($f);
    tagihanStudent($f);

    tagihanGenerate('2026-10-01', $user);

    expect(Invoice::withoutUnitScope()->pluck('generated_by')->unique()->all())->toBe([$user->id]);
});

it('AC-7: takes its four collaborators through the constructor', function () {
    expect(app(GenerateMonthlyInvoices::class))->toBeInstanceOf(GenerateMonthlyInvoices::class)
        ->and((new ReflectionClass(GenerateMonthlyInvoices::class))->getConstructor()?->getNumberOfParameters())->toBe(4);
});
