<?php

use App\Actions\Psb\SaveAdmissionWave;
use App\Models\AdmissionWave;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

function psbSaveWave(object $f, array $data = [], ?AdmissionWave $wave = null): AdmissionWave
{
    return app(SaveAdmissionWave::class)->handle(psbWaveData($f, $data), $wave);
}

beforeEach(function () {
    $this->f = psbFixture();
});

it('AC-8: creates a wave with normalized values', function () {
    $wave = psbSaveWave($this->f, ['name' => ' Gelombang 1 ', 'quota' => '150']);

    expect($wave->fresh())
        ->academic_year_id->toBe($this->f->y2027->id)
        ->name->toBe('Gelombang 1')
        ->quota->toBe(150)
        ->is_open->toBeTrue()
        ->and($wave->fresh()->opens_on->format('Y-m-d'))->toBe('2027-01-01')
        ->and($wave->fresh()->closes_on->format('Y-m-d'))->toBe('2027-03-31')
        ->and(AdmissionWave::query()->count())->toBe(1);
});

it('AC-8: stores an empty quota as NULL', function (mixed $quota) {
    $wave = psbSaveWave($this->f, ['quota' => $quota]);

    expect($wave->fresh()->quota)->toBeNull();
})->with(['empty string' => [''], 'null' => [null]]);

it('AC-8: keeps the wave closed when is_open is false', function () {
    expect(psbSaveWave($this->f, ['is_open' => false])->fresh()->is_open)->toBeFalse();
});

it('AC-8: accepts a wave that opens and closes on the same day', function () {
    $wave = psbSaveWave($this->f, ['opens_on' => '2027-02-01', 'closes_on' => '2027-02-01']);

    expect($wave->fresh()->closes_on->format('Y-m-d'))->toBe('2027-02-01');
});

it('AC-8: accepts a name of exactly 100 characters and a quota of 1', function () {
    $wave = psbSaveWave($this->f, ['name' => str_repeat('a', 100), 'quota' => 1]);

    expect($wave->fresh())->name->toHaveLength(100)->quota->toBe(1);
});

it('AC-9: rejects an invalid wave', function (Closure $overrides, string $reason, string $field) {
    $f = $this->f;
    psbWave($f->y2027, 'Gelombang 1', '2027-05-01', '2027-05-31');

    psbAssertRejected(fn () => psbSaveWave($f, ['name' => 'Gelombang 2', ...$overrides($f)]), $reason, $field);

    expect(AdmissionWave::query()->count())->toBe(1);
})->with([
    'unknown academic year' => [fn () => ['academic_year_id' => 999], 'academic_year_not_found', 'academic_year_id'],
    'blank name' => [fn () => ['name' => '  '], 'name_required', 'name'],
    'name above 100 characters' => [fn () => ['name' => str_repeat('a', 101)], 'name_required', 'name'],
    'taken name in the same academic year' => [fn () => ['name' => 'Gelombang 1'], 'wave_name_taken', 'name'],
    'missing opens_on' => [fn () => ['opens_on' => null], 'date_invalid', 'opens_on'],
    'opens_on in the wrong format' => [fn () => ['opens_on' => '01-01-2027'], 'date_invalid', 'opens_on'],
    'closes_on that does not exist' => [fn () => ['closes_on' => '2027-02-30'], 'date_invalid', 'closes_on'],
    'closes before opens' => [fn () => ['opens_on' => '2027-01-01', 'closes_on' => '2026-12-31'], 'closes_before_opens', 'closes_on'],
    'zero quota' => [fn () => ['quota' => 0], 'quota_invalid', 'quota'],
    'negative quota' => [fn () => ['quota' => -1], 'quota_invalid', 'quota'],
    'decimal quota' => [fn () => ['quota' => '1.5'], 'quota_invalid', 'quota'],
    'text quota' => [fn () => ['quota' => 'abc'], 'quota_invalid', 'quota'],
]);

it('AC-9: accepts the same wave name in another academic year', function () {
    psbWave($this->f->y2027, 'Gelombang 1');

    $wave = psbSaveWave($this->f, ['academic_year_id' => $this->f->y2026->id]);

    expect($wave->academic_year_id)->toBe($this->f->y2026->id)->and(AdmissionWave::query()->count())->toBe(2);
});

it('AC-10: rejects overlapping date ranges in one academic year', function (string $opens, string $closes) {
    psbWave($this->f->y2027, 'Gelombang 1', '2027-01-01', '2027-03-31');

    psbAssertRejected(fn () => psbSaveWave($this->f, ['name' => 'Gelombang 2', 'opens_on' => $opens, 'closes_on' => $closes]), 'wave_dates_overlap', 'opens_on');

    expect(AdmissionWave::query()->count())->toBe(1);
})->with([
    'starts on the last day of the other' => ['2027-03-31', '2027-05-31'],
    'ends on the first day of the other' => ['2026-12-01', '2027-01-01'],
    'surrounds the other' => ['2026-12-01', '2027-06-30'],
    'sits inside the other' => ['2027-02-01', '2027-02-15'],
    'is the same range' => ['2027-01-01', '2027-03-31'],
]);

it('AC-10: accepts a range right after the other, and the same range in another academic year', function () {
    psbWave($this->f->y2027, 'Gelombang 1', '2027-01-01', '2027-03-31');

    psbSaveWave($this->f, ['name' => 'Gelombang 2', 'opens_on' => '2027-04-01', 'closes_on' => '2027-05-31']);
    psbSaveWave($this->f, ['academic_year_id' => $this->f->y2026->id, 'name' => 'Gelombang 1']);

    expect(AdmissionWave::query()->count())->toBe(3);
});

it('AC-10: counts closed waves when checking overlap', function () {
    psbWave($this->f->y2027, 'Gelombang 1', '2027-01-01', '2027-03-31', ['is_open' => false]);

    psbAssertRejected(fn () => psbSaveWave($this->f, ['name' => 'Gelombang 2', 'opens_on' => '2027-02-01', 'closes_on' => '2027-04-30']), 'wave_dates_overlap', 'opens_on');
});

it('AC-10: saves a wave again without clashing with itself', function () {
    $wave = psbSaveWave($this->f);

    $updated = psbSaveWave($this->f, ['quota' => 200], $wave);

    expect($updated->id)->toBe($wave->id)->and($wave->fresh()->quota)->toBe(200)->and(AdmissionWave::query()->count())->toBe(1);
});

it('AC-10: rejects editing a wave into an overlap and leaves it unchanged', function () {
    psbSaveWave($this->f);
    $second = psbSaveWave($this->f, ['name' => 'Gelombang 2', 'opens_on' => '2027-04-01', 'closes_on' => '2027-05-31']);

    psbAssertRejected(fn () => psbSaveWave($this->f, ['name' => 'Gelombang 2', 'opens_on' => '2027-03-01', 'closes_on' => '2027-05-31'], $second), 'wave_dates_overlap', 'opens_on');

    expect($second->fresh()->opens_on->format('Y-m-d'))->toBe('2027-04-01');
});

it('AC-9: rejects renaming a wave into a taken name and leaves it unchanged', function () {
    psbSaveWave($this->f);
    $second = psbSaveWave($this->f, ['name' => 'Gelombang 2', 'opens_on' => '2027-04-01', 'closes_on' => '2027-05-31']);

    psbAssertRejected(fn () => psbSaveWave($this->f, ['name' => 'Gelombang 1', 'opens_on' => '2027-04-01', 'closes_on' => '2027-05-31'], $second), 'wave_name_taken', 'name');

    expect($second->fresh()->name)->toBe('Gelombang 2');
});

it('AC-11: finds waves accepting registrations on a date with inclusive bounds', function () {
    $open = psbWave($this->f->y2027, 'Gelombang 1', '2027-01-01', '2027-03-31', ['is_open' => true, 'quota' => 1]);
    psbWave($this->f->y2026, 'Gelombang 1', '2027-01-01', '2027-03-31', ['is_open' => false]);

    foreach (['2027-01-01', '2027-02-15', '2027-03-31'] as $date) {
        expect(AdmissionWave::acceptingRegistrationsOn(Carbon::parse($date))->pluck('id')->all())->toBe([$open->id]);
    }

    foreach (['2026-12-31', '2027-04-01'] as $date) {
        expect(AdmissionWave::acceptingRegistrationsOn(Carbon::parse($date))->pluck('id')->all())->toBe([]);
    }
});

it('AC-11: ignores the time of day on the closing date', function () {
    $open = psbWave($this->f->y2027, 'Gelombang 1', '2027-01-01', '2027-03-31');

    expect(AdmissionWave::acceptingRegistrationsOn(CarbonImmutable::parse('2027-03-31 23:59:59'))->pluck('id')->all())->toBe([$open->id])
        ->and(AdmissionWave::acceptingRegistrationsOn(CarbonImmutable::parse('2027-01-01 00:00:00'))->pluck('id')->all())->toBe([$open->id]);
});

it('AC-11: answers per wave with the same rule', function () {
    $open = psbWave($this->f->y2027, 'Gelombang 1', '2027-01-01', '2027-03-31', ['is_open' => true, 'quota' => 1]);
    $closed = psbWave($this->f->y2026, 'Gelombang 1', '2027-01-01', '2027-03-31', ['is_open' => false]);

    expect($open->isAcceptingRegistrationsOn(Carbon::parse('2027-01-01')))->toBeTrue()
        ->and($open->isAcceptingRegistrationsOn(Carbon::parse('2027-03-31')))->toBeTrue()
        ->and($open->isAcceptingRegistrationsOn(Carbon::parse('2026-12-31')))->toBeFalse()
        ->and($open->isAcceptingRegistrationsOn(Carbon::parse('2027-04-01')))->toBeFalse()
        ->and($closed->isAcceptingRegistrationsOn(Carbon::parse('2027-02-01')))->toBeFalse();
});
