<?php

use App\Actions\Keuangan\SaveFeeOverride;
use App\Models\FeeOverride;
use App\Models\FeeType;
use App\Models\Scholarship;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function keringananSave(array $data, ?User $approver = null, ?FeeOverride $override = null): FeeOverride
{
    return app(SaveFeeOverride::class)->handle($data, $approver ?? keuanganUser('bendahara'), $override);
}

it('AC-15: saves an override with the approver', function () {
    $f = keringananFixture();
    $approver = keuanganUser('bendahara');

    $override = keringananSave(keringananOverrideData($f, ['value' => '250000', 'reason' => ' Hafal 10 juz ']), $approver);

    expect(FeeOverride::query()->count())->toBe(1)
        ->and($override->fresh())
        ->student_id->toBe($f->a->id)
        ->fee_type_id->toBe($f->spp->id)
        ->mode->toBe('nominal')
        ->value->toBe(250000)
        ->reason->toBe('Hafal 10 juz')
        ->approved_by->toBe($approver->id)
        ->scholarship_id->toBeNull()
        ->and($override->fresh()->valid_from->toDateString())->toBe('2026-07-01')
        ->and($override->fresh()->valid_until)->toBeNull();
});

it('AC-15: accepts dates as Carbon instances', function () {
    $f = keringananFixture();

    $override = keringananSave(keringananOverrideData($f, [
        'valid_from' => CarbonImmutable::parse('2026-07-01'),
        'valid_until' => CarbonImmutable::parse('2026-12-31'),
    ]));

    expect($override->fresh()->valid_from->toDateString())->toBe('2026-07-01')
        ->and($override->fresh()->valid_until->toDateString())->toBe('2026-12-31');
});

it('AC-15: keeps the given mode and value over scholarship defaults', function () {
    $f = keringananFixture();
    $scholarship = Scholarship::factory()->create(['mode' => 'persen', 'default_value' => 50]);

    $override = keringananSave(keringananOverrideData($f, ['scholarship_id' => $scholarship->id, 'mode' => 'potongan', 'value' => 100000]));

    expect($override->fresh())->scholarship_id->toBe($scholarship->id)->mode->toBe('potongan')->value->toBe(100000);
});

it('AC-16: rejects the override', function (Closure $data, string $reason, string $field) {
    $f = keringananFixture();

    keuanganAssertRejected(fn () => keringananSave(keringananOverrideData($f, $data($f))), $reason, $field);

    expect(FeeOverride::query()->count())->toBe(0);
})->with([
    'no owner' => [fn () => ['student_id' => null, 'applicant_id' => null], 'owner_required', 'student_id'],
    'unknown student' => [fn () => ['student_id' => 999999], 'student_not_found', 'student_id'],
    'soft deleted student' => [function (object $f): array {
        $f->a->delete();

        return [];
    }, 'student_not_found', 'student_id'],
    'graduated student' => [function (object $f): array {
        $f->a->update(['status' => 'lulus']);

        return [];
    }, 'student_not_active', 'student_id'],
    'unknown fee type' => [fn () => ['fee_type_id' => 999999], 'fee_type_not_found', 'fee_type_id'],
    'unknown scholarship' => [fn () => ['scholarship_id' => 999999], 'scholarship_not_found', 'scholarship_id'],
    'inactive scholarship' => [fn () => ['scholarship_id' => Scholarship::factory()->inactive()->create()->id], 'scholarship_inactive', 'scholarship_id'],
    'invalid mode' => [fn () => ['mode' => 'gratis'], 'invalid_mode', 'mode'],
    'negative value' => [fn () => ['value' => -1], 'value_invalid', 'value'],
    'decimal value' => [fn () => ['value' => '1.5'], 'value_invalid', 'value'],
    'null value' => [fn () => ['value' => null], 'value_invalid', 'value'],
    'percent above 100' => [fn () => ['mode' => 'persen', 'value' => 101], 'percent_range', 'value'],
    'blank reason' => [fn () => ['reason' => '  '], 'reason_invalid', 'reason'],
    'long reason' => [fn () => ['reason' => str_repeat('a', 256)], 'reason_invalid', 'reason'],
    'null start' => [fn () => ['valid_from' => null], 'date_invalid', 'valid_from'],
    'wrong start format' => [fn () => ['valid_from' => '15-10-2026'], 'date_invalid', 'valid_from'],
    'wrong end' => [fn () => ['valid_until' => 'bukan-tanggal'], 'date_invalid', 'valid_until'],
    'end before start' => [fn () => ['valid_from' => '2026-07-01', 'valid_until' => '2026-06-30'], 'until_before_from', 'valid_until'],
]);

it('AC-16: accepts an end date equal to the start date and percent boundaries', function () {
    $f = keringananFixture();

    keringananSave(keringananOverrideData($f, ['valid_from' => '2026-07-01', 'valid_until' => '2026-07-01']));
    keringananSave(keringananOverrideData($f, ['valid_from' => '2026-08-01', 'valid_until' => '2026-08-31', 'mode' => 'persen', 'value' => 100]));
    keringananSave(keringananOverrideData($f, ['valid_from' => '2026-09-01', 'valid_until' => '2026-09-30', 'mode' => 'persen', 'value' => 0]));

    expect(FeeOverride::query()->count())->toBe(3);
});

it('AC-17: rejects overlapping overrides', function (string $existingFrom, ?string $existingUntil, string $from, ?string $until) {
    $f = keringananFixture();
    keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'valid_from' => $existingFrom, 'valid_until' => $existingUntil]);

    keuanganAssertRejected(
        fn () => keringananSave(keringananOverrideData($f, ['valid_from' => $from, 'valid_until' => $until])),
        'overlapping_override',
        'valid_from',
    );

    expect(FeeOverride::query()->count())->toBe(1);
})->with([
    'new open ended inside an open ended one' => ['2026-07-01', null, '2027-01-01', null],
    'inclusive boundary on the old start' => ['2026-07-01', null, '2026-01-01', '2026-07-01'],
    'inclusive boundary on the old end' => ['2026-07-01', '2026-12-31', '2026-12-31', null],
    'new inside the old' => ['2026-01-01', '2026-12-31', '2026-03-01', '2026-04-30'],
    'new contains the old' => ['2026-03-01', '2026-04-30', '2026-01-01', null],
]);

it('AC-17: allows adjacent or unrelated overrides', function () {
    $f = keringananFixture();
    $other = FeeType::factory()->create();
    keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-07-01', 'valid_until' => null]);

    keringananSave(keringananOverrideData($f, ['valid_from' => '2026-01-01', 'valid_until' => '2026-06-30']));
    keringananSave(keringananOverrideData($f, ['fee_type_id' => $other->id]));
    keringananSave(keringananOverrideData($f, ['student_id' => $f->b->id]));

    expect(FeeOverride::query()->count())->toBe(4);
});

it('AC-17: allows a new override after an ended one', function () {
    $f = keringananFixture();
    keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-07-01', 'valid_until' => '2026-12-31']);

    keringananSave(keringananOverrideData($f, ['valid_from' => '2027-01-01', 'valid_until' => null]));

    expect(FeeOverride::query()->count())->toBe(2);
});

it('AC-17: rejects overlapping applicant-only overrides per applicant', function () {
    $f = keringananFixture();
    $data = fn (array $o) => keringananOverrideData($f, ['student_id' => null, 'applicant_id' => 77, ...$o]);
    keringananSave($data(['valid_from' => '2026-07-01', 'valid_until' => '2026-12-31']));

    keuanganAssertRejected(fn () => keringananSave($data(['valid_from' => '2026-12-01', 'valid_until' => null])), 'overlapping_override', 'valid_from');
    keringananSave($data(['applicant_id' => 78, 'valid_from' => '2026-12-01', 'valid_until' => null]));

    expect(FeeOverride::query()->count())->toBe(2);
});

it('AC-17: allows editing an override without changing its dates', function () {
    $f = keringananFixture();
    $override = keringananSave(keringananOverrideData($f));

    $updated = keringananSave(keringananOverrideData($f, ['value' => 200000]), null, $override);

    expect($updated->id)->toBe($override->id)->and($override->fresh()->value)->toBe(200000)
        ->and(FeeOverride::query()->count())->toBe(1);
});

it('AC-17: rejects an edit that creates an overlap', function (string $change) {
    $f = keringananFixture();
    $other = FeeType::factory()->create();
    keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-07-01', 'valid_until' => '2026-12-31']);
    $movable = keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $other->id, 'valid_from' => '2026-07-01', 'valid_until' => '2026-12-31']);
    $earlier = keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2025-01-01', 'valid_until' => '2025-12-31']);

    $data = match ($change) {
        'fee type into a taken slot' => keringananOverrideData($f, ['fee_type_id' => $f->spp->id, 'valid_from' => '2026-07-01', 'valid_until' => '2026-12-31']),
        'dates stretched over another override' => keringananOverrideData($f, ['valid_from' => '2025-06-01', 'valid_until' => '2026-07-01']),
    };
    $target = $change === 'fee type into a taken slot' ? $movable : $earlier;

    keuanganAssertRejected(fn () => keringananSave($data, null, $target), 'overlapping_override', 'valid_from');

    expect($target->fresh()->valid_from->toDateString())->toBe($target->valid_from->toDateString())
        ->and($target->fresh()->fee_type_id)->toBe($target->fee_type_id);
})->with(['fee type into a taken slot', 'dates stretched over another override']);

it('AC-18: respects the user unit scope', function () {
    $f = keringananFixture();
    $scoped = keuanganUser('bendahara', ['SLTP']);
    $this->actingAs($scoped);

    keuanganAssertRejected(fn () => keringananSave(keringananOverrideData($f, ['student_id' => $f->c->id]), $scoped), 'student_not_found', 'student_id');
    keringananSave(keringananOverrideData($f), $scoped);

    expect(FeeOverride::query()->count())->toBe(1);

    $unscoped = keuanganUser('bendahara');
    $this->actingAs($unscoped);
    keringananSave(keringananOverrideData($f, ['student_id' => $f->c->id]), $unscoped);

    expect(FeeOverride::query()->count())->toBe(2);
});

it('AC-19: edits an override keeping the owner and approver', function () {
    $f = keringananFixture();
    $creator = keuanganUser('bendahara');
    $editor = keuanganUser('bendahara');
    $override = keringananSave(keringananOverrideData($f), $creator);
    $this->actingAs($editor);

    keringananSave(keringananOverrideData($f, [
        'student_id' => $f->c->id,
        'applicant_id' => 55,
        'mode' => 'persen',
        'value' => 40,
        'reason' => 'Alasan baru',
        'valid_from' => '2026-08-01',
        'valid_until' => '2026-12-31',
    ]), $editor, $override);

    expect($override->fresh())
        ->student_id->toBe($f->a->id)
        ->applicant_id->toBeNull()
        ->approved_by->toBe($creator->id)
        ->mode->toBe('persen')
        ->value->toBe(40)
        ->reason->toBe('Alasan baru')
        ->and($override->fresh()->valid_from->toDateString())->toBe('2026-08-01')
        ->and($override->fresh()->valid_until->toDateString())->toBe('2026-12-31')
        ->and(FeeOverride::query()->count())->toBe(1);
});

it('AC-19: validates scholarship status only when it changes', function () {
    $f = keringananFixture();
    $program = Scholarship::factory()->create();
    $override = keringananSave(keringananOverrideData($f, ['scholarship_id' => $program->id]));
    $program->update(['is_active' => false]);

    keringananSave(keringananOverrideData($f, ['scholarship_id' => $program->id, 'value' => 200000]), null, $override);

    expect($override->fresh()->value)->toBe(200000);

    $otherInactive = Scholarship::factory()->inactive()->create();

    keuanganAssertRejected(
        fn () => keringananSave(keringananOverrideData($f, ['scholarship_id' => $otherInactive->id]), null, $override),
        'scholarship_inactive',
        'scholarship_id',
    );

    expect($override->fresh()->scholarship_id)->toBe($program->id);
});

it('AC-20: saves applicant overrides without an applicants table', function () {
    $f = keringananFixture();

    $applicantOnly = keringananSave(keringananOverrideData($f, ['student_id' => null, 'applicant_id' => 77]));
    $both = keringananSave(keringananOverrideData($f, ['student_id' => $f->a->id, 'applicant_id' => 77]));

    expect($applicantOnly->fresh())->student_id->toBeNull()->applicant_id->toBe(77)
        ->and($both->fresh())->student_id->toBe($f->a->id)->applicant_id->toBe(77)
        ->and(Student::query()->count())->toBe(3);
});
