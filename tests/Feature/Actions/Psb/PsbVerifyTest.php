<?php

use App\Actions\Psb\SaveAdmissionRequirement;
use App\Actions\Psb\SaveAdmissionWave;
use App\Models\AdmissionWave;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->f = psbFixture();
});

it('VERIFY: rejects a quota larger than the unsigned integer column instead of failing in the database', function (mixed $quota) {
    $call = fn () => app(SaveAdmissionWave::class)->handle(psbWaveData($this->f, ['quota' => $quota]));

    expect($call)->toThrow(ValidationException::class)
        ->and(AdmissionWave::query()->count())->toBe(0);
})->with(['beyond 32 bit' => ['99999999999'], 'beyond 64 bit' => ['99999999999999999999999']]);

it('VERIFY: rejects dates that are not strict Y-m-d', function (string $field, mixed $value) {
    psbAssertRejected(
        fn () => app(SaveAdmissionWave::class)->handle(psbWaveData($this->f, [$field => $value])),
        'date_invalid',
        $field,
    );
})->with([
    'not padded opens_on' => ['opens_on', '2027-1-1'],
    'datetime opens_on' => ['opens_on', '2027-01-01 10:00:00'],
    'slash closes_on' => ['closes_on', '2027/03/31'],
    'array closes_on' => ['closes_on', ['2027-03-31']],
]);

it('VERIFY: rejects a non scalar quota and name without a server error', function (string $field, mixed $value, string $reason) {
    psbAssertRejected(fn () => app(SaveAdmissionWave::class)->handle(psbWaveData($this->f, [$field => $value])), $reason, $field);
})->with([
    'array quota' => ['quota', [1], 'quota_invalid'],
    'null name' => ['name', null, 'name_required'],
]);

it('VERIFY: rejects a requirement name above 255 characters and accepts exactly 255', function () {
    psbAssertRejected(fn () => app(SaveAdmissionRequirement::class)->handle(psbRequirementData($this->f, ['name' => str_repeat('a', 256)])), 'name_required', 'name');

    $requirement = app(SaveAdmissionRequirement::class)->handle(psbRequirementData($this->f, ['name' => str_repeat('a', 255)]));

    expect($requirement->fresh()->name)->toHaveLength(255);
});

it('VERIFY: counts an unscoped sd requirement beside a later all-units edit as a conflict', function () {
    $sd = app(SaveAdmissionRequirement::class)->handle(psbRequirementData($this->f));
    $akta = app(SaveAdmissionRequirement::class)->handle(psbRequirementData($this->f, ['document_type_id' => $this->f->types['AKTA']->id, 'name' => 'Akta', 'unit_id' => null]));

    psbAssertRejected(
        fn () => app(SaveAdmissionRequirement::class)->handle(psbRequirementData($this->f, ['document_type_id' => $this->f->types['KK']->id, 'unit_id' => null]), $akta),
        'requirement_exists',
        'document_type_id',
    );

    expect($sd->fresh()->unit_id)->toBe($this->f->sd->id)->and($akta->fresh()->document_type_id)->toBe($this->f->types['AKTA']->id);
});
