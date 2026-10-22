<?php

use App\Actions\Keuangan\SummarizeScholarshipSubsidies;
use App\Models\FeeOverride;
use App\Models\FeeRate;
use App\Models\Scholarship;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $this->f = laporanFixture();
});

function laporanProgramRows(array $programs): array
{
    return array_map(fn (array $row): array => [$row['name'], $row['subsidy'], $row['item_count'], $row['beneficiary_count']], $programs);
}

it('AC-11: totals subsidies per scholarship program', function () {
    laporanSubsidyFixture($this->f);

    $result = app(SummarizeScholarshipSubsidies::class)->handle(laporanFilter('2026-10'));

    expect(laporanProgramRows($result['programs']))->toBe([
        ['Beasiswa Tahfidz', 750000, 2, 2],
        ['Yatim', 300000, 1, 1],
        ['Anak Ustadz', 100000, 1, 1],
    ])
        ->and($result['programs'][0]['sponsor'])->toBe('Yayasan Contoh')
        ->and($result['programs'][2]['sponsor'])->toBeNull()
        ->and($result['total_subsidy'])->toBe(1150000);
});

it('AC-11: reports non-scholarship relief as amount and item count', function () {
    laporanSubsidyFixture($this->f);

    $relief = app(SummarizeScholarshipSubsidies::class)->handle(laporanFilter('2026-10'))['non_scholarship_relief'];

    expect($relief['amount'])->toBe(50000)
        ->and($relief['item_count'])->toBe(1);
});

it('AC-11: keeps the subsidy snapshot when rates, overrides, or scholarships change later', function () {
    laporanSubsidyFixture($this->f);
    FeeRate::factory()->create(['fee_type_id' => $this->f->spp->id, 'effective_from_year_id' => $this->f->year->id, 'unit_id' => $this->f->sltp->id, 'amount' => 9000000]);
    FeeOverride::query()->update(['value' => 1]);
    Scholarship::query()->update(['default_value' => 1]);

    $result = app(SummarizeScholarshipSubsidies::class)->handle(laporanFilter('2026-10'));

    expect($result['total_subsidy'])->toBe(1150000)
        ->and($result['non_scholarship_relief']['amount'])->toBe(50000);
});

it('AC-12: groups subsidies per sponsor', function () {
    laporanSubsidyFixture($this->f);

    $sponsors = app(SummarizeScholarshipSubsidies::class)->handle(laporanFilter('2026-10'))['sponsors'];
    $rows = array_map(fn (array $row): array => [$row['sponsor'], $row['subsidy'], $row['program_count'], $row['beneficiary_count']], $sponsors);

    expect($rows)->toBe([
        ['Yayasan Contoh', 1050000, 2, 2],
        [null, 100000, 1, 1],
    ]);
});

it('AC-12: orders sponsors with equal subsidy by name and keeps zero-subsidy programs', function () {
    $f = $this->f;
    $programs = [
        laporanScholarship('Program B', 'Donatur B'),
        laporanScholarship('Program A', 'Donatur A'),
        laporanScholarship('Program Nol', 'Donatur C'),
    ];

    foreach ($programs as $i => $program) {
        $student = laporanStudent($f);
        $discount = $i === 2 ? 0 : 100000;
        laporanItem(laporanInvoice($f, $student, '2026-10-01', 500000 - $discount), $f->spp, 500000, $discount, laporanOverride($student, $f->spp, $program));
    }

    $result = app(SummarizeScholarshipSubsidies::class)->handle(laporanFilter('2026-10'));

    expect(array_map(fn (array $row): array => [$row['sponsor'], $row['subsidy']], $result['sponsors']))->toBe([
        ['Donatur A', 100000],
        ['Donatur B', 100000],
        ['Donatur C', 0],
    ])
        ->and(array_map(fn (array $row): array => [$row['name'], $row['subsidy']], $result['programs']))->toBe([
            ['Program A', 100000],
            ['Program B', 100000],
            ['Program Nol', 0],
        ]);
});

it('AC-11: returns empty lists and zero totals without subsidies', function () {
    $result = app(SummarizeScholarshipSubsidies::class)->handle(laporanFilter('2026-10'));

    expect($result['programs'])->toBe([])
        ->and($result['sponsors'])->toBe([])
        ->and($result['total_subsidy'])->toBe(0)
        ->and($result['non_scholarship_relief']['amount'])->toBe(0)
        ->and($result['non_scholarship_relief']['item_count'])->toBe(0);
});
