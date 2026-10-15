<?php

use App\Models\AcademicYear;
use App\Models\AdmissionWave;
use App\Models\Applicant;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->f = pendaftaranFixture();
    $this->today = CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta');
});

it('AC-8: counts applicants against the quota', function () {
    $wave = $this->f->wave;
    $wave->update(['quota' => 2]);
    $first = pendaftaranApplicant();
    pendaftaranApplicant();

    expect($wave->fresh()->countedApplicants())->toBe(2)
        ->and($wave->fresh()->isFull())->toBeTrue()
        ->and(AdmissionWave::openForRegistration($this->today))->toHaveCount(0);

    $first->delete();

    expect($wave->fresh()->countedApplicants())->toBe(1)
        ->and($wave->fresh()->isFull())->toBeFalse()
        ->and(AdmissionWave::openForRegistration($this->today)->pluck('id')->all())->toBe([$wave->id]);
});

it('AC-8: does not count draft applicants', function () {
    $wave = $this->f->wave;
    $wave->update(['quota' => 2]);
    pendaftaranApplicant(['status' => 'draft']);
    pendaftaranApplicant(['status' => 'draft']);
    pendaftaranApplicant(['status' => 'diajukan']);

    expect($wave->fresh()->countedApplicants())->toBe(1)->and($wave->fresh()->isFull())->toBeFalse();
});

it('AC-8: counts rejected and accepted applicants too', function () {
    $wave = $this->f->wave;
    $wave->update(['quota' => 2]);
    pendaftaranApplicant(['status' => 'ditolak']);
    pendaftaranApplicant(['status' => 'diterima']);

    expect($wave->fresh()->isFull())->toBeTrue();
});

it('AC-8: counts every unit even for a scoped user', function () {
    $this->seed(RolePermissionSeeder::class);
    $wave = $this->f->wave;
    $wave->update(['quota' => 2]);
    pendaftaranApplicant(['unit_id' => $this->f->sltp->id]);
    pendaftaranApplicant(['unit_id' => $this->f->slta->id]);
    $this->actingAs(psbUser('admin-psb', ['SD']));

    expect($wave->fresh()->countedApplicants())->toBe(2)
        ->and($wave->fresh()->isFull())->toBeTrue()
        ->and(AdmissionWave::openForRegistration($this->today))->toHaveCount(0);
});

it('AC-8: never fills a wave without a quota', function () {
    Applicant::factory()->count(3)->create(['admission_wave_id' => $this->f->wave->id]);

    expect($this->f->wave->fresh()->quota)->toBeNull()
        ->and($this->f->wave->fresh()->isFull())->toBeFalse();
});

it('AC-9: lists waves open for registration ordered by academic year then opening date', function () {
    $f = $this->f;
    $y2028 = AcademicYear::factory()->create(['name' => '2028/2029', 'starts_on' => '2028-07-01', 'ends_on' => '2029-06-30']);
    $inden = psbWave($y2028, 'Gelombang Inden', '2026-10-01', '2027-01-31');
    $closed = psbWave($f->y2027, 'Gelombang Tutup', '2026-10-01', '2026-12-31', ['is_open' => false]);
    $past = psbWave($f->y2027, 'Gelombang Lalu', '2026-01-01', '2026-02-28');
    $future = psbWave($f->y2027, 'Gelombang Depan', '2027-01-01', '2027-03-31');
    $full = psbWave($y2028, 'Gelombang Penuh', '2026-10-01', '2026-12-31', ['quota' => 1]);
    Applicant::factory()->create(['admission_wave_id' => $full->id]);

    $waves = AdmissionWave::openForRegistration($this->today);

    expect($waves->pluck('id')->all())->toBe([$f->wave->id, $inden->id])
        ->and($waves->pluck('id')->all())->not->toContain($closed->id, $past->id, $future->id, $full->id)
        ->and($waves->first()->relationLoaded('academicYear'))->toBeTrue();
});

it('AC-9: orders by academic year start rather than by id', function () {
    $earlier = psbWave($this->f->y2026, 'Gelombang Tahun Ini', '2026-10-01', '2026-12-31');

    expect(AdmissionWave::openForRegistration($this->today)->pluck('id')->all())->toBe([$earlier->id, $this->f->wave->id]);
});
