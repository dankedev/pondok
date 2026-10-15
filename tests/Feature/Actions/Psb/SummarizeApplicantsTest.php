<?php

use App\Actions\Psb\SummarizeApplicants;
use App\Models\AdmissionWave;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->f = seleksiFixture();
});

it('AC-29: summarizes the applicants of the latest admission year', function () {
    $old = psbWave($this->f->y2026, 'Gelombang Lama', '2026-01-01', '2026-03-31');

    seleksiSummaryApplicants(1, 'diajukan', ['created_at' => '2026-11-14 00:00:00']);
    seleksiSummaryApplicants(1, 'diajukan', ['created_at' => '2026-11-13 23:59:00']);
    seleksiSummaryApplicants(1, 'diajukan');
    seleksiSummaryApplicants(2, 'diverifikasi');
    seleksiSummaryApplicants(1, 'diterima');
    seleksiSummaryApplicants(1, 'cadangan');
    seleksiSummaryApplicants(1, 'ditolak');
    seleksiSummaryApplicants(1, 'draft');
    pendaftaranApplicant(['status' => 'diajukan'])->delete();
    seleksiSummaryApplicants(4, 'diterima', wave: $old);

    $summary = app(SummarizeApplicants::class)->handle();

    expect($summary['academic_year']->is($this->f->y2027))->toBeTrue()
        ->and($summary['total'])->toBe(8)
        ->and($summary['by_status'])->toEqual(['diajukan' => 3, 'diverifikasi' => 2, 'diterima' => 1, 'cadangan' => 1, 'ditolak' => 1])
        ->and(array_keys($summary['by_status']))->toBe(['diajukan', 'diverifikasi', 'diterima', 'cadangan', 'ditolak'])
        ->and($summary['last_7_days'])->toBe(7);
});

it('AC-29: counts the seven calendar days up to the given day', function () {
    seleksiSummaryApplicants(1, 'diajukan', ['created_at' => '2026-11-14 00:00:00']);
    seleksiSummaryApplicants(1, 'diajukan', ['created_at' => '2026-11-13 23:59:59']);

    expect(app(SummarizeApplicants::class)->handle()['last_7_days'])->toBe(1)
        ->and(app(SummarizeApplicants::class)->handle(CarbonImmutable::parse('2026-11-19 12:00', 'Asia/Jakarta'))['last_7_days'])->toBe(2)
        ->and(app(SummarizeApplicants::class)->handle(CarbonImmutable::parse('2026-11-21 12:00', 'Asia/Jakarta'))['last_7_days'])->toBe(0);
});

it('AC-29: reports zeros and no academic year without admission waves', function () {
    AdmissionWave::query()->delete();

    $summary = app(SummarizeApplicants::class)->handle();

    expect($summary['academic_year'])->toBeNull()
        ->and($summary['total'])->toBe(0)
        ->and($summary['last_7_days'])->toBe(0)
        ->and($summary['by_status'])->toEqual(['diajukan' => 0, 'diverifikasi' => 0, 'diterima' => 0, 'cadangan' => 0, 'ditolak' => 0]);
});

it('AC-29: keeps every status key even when nothing matches', function () {
    seleksiSummaryApplicants(1, 'diterima');

    expect(array_keys(app(SummarizeApplicants::class)->handle()['by_status']))->toBe(['diajukan', 'diverifikasi', 'diterima', 'cadangan', 'ditolak']);
});

it('AC-29: refers to the wave with the latest academic year even when it is closed', function () {
    $old = psbWave($this->f->y2026, 'Gelombang Lama', '2026-01-01', '2026-03-31');
    seleksiSummaryApplicants(2, 'diajukan', wave: $old);
    AdmissionWave::query()->whereKey($this->f->wave->id)->update(['closes_on' => '2026-10-31']);

    $summary = app(SummarizeApplicants::class)->handle();

    expect($summary['academic_year']->is($this->f->y2027))->toBeTrue()
        ->and($summary['total'])->toBe(0);
});

it('AC-29: only counts the units in the scope of the logged in user', function () {
    seleksiSummaryApplicants(2, 'diajukan');
    seleksiSummaryApplicants(3, 'diterima', ['unit_id' => $this->f->sd->id]);

    $this->actingAs(psbUser('admin-psb', ['SLTP']));

    $scoped = app(SummarizeApplicants::class)->handle();

    expect($scoped['total'])->toBe(2)
        ->and($scoped['by_status']['diterima'])->toBe(0)
        ->and($scoped['last_7_days'])->toBe(2);

    $this->actingAs(psbUser('admin-psb'));

    expect(app(SummarizeApplicants::class)->handle()['total'])->toBe(5);
});

it('AC-29: runs one grouped query for the status counts', function () {
    seleksiSummaryApplicants(2, 'diajukan');
    seleksiSummaryApplicants(1, 'ditolak');

    $queries = collect();
    DB::listen(fn ($query) => $queries->push($query->sql));

    app(SummarizeApplicants::class)->handle();

    expect($queries->filter(fn (string $sql): bool => str_contains(strtolower($sql), 'group by') && str_contains($sql, 'status')))->toHaveCount(1);
});
