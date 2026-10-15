<?php

use App\Actions\Keuangan\ResolveFeeOverride;
use App\Models\Applicant;
use App\Models\FeeType;
use App\Models\Scholarship;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;

it('AC-8: applies only overrides active in the billing month', function (string $from, ?string $until, bool $found) {
    $f = keringananFixture();
    $override = keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'valid_from' => $from, 'valid_until' => $until]);

    foreach (['2026-10-01', '2026-10-15', '2026-10-31'] as $period) {
        $result = app(ResolveFeeOverride::class)->forStudent($f->a, $f->spp, CarbonImmutable::parse($period));

        if ($found) {
            expect($result?->id)->toBe($override->id);
        } else {
            expect($result)->toBeNull();
        }
    }
})->with([
    'starts next month' => ['2026-11-01', null, false],
    'ended last month' => ['2026-01-01', '2026-09-30', false],
    'ends on the first day' => ['2026-07-01', '2026-10-01', true],
    'starts on the last days' => ['2026-10-31', null, true],
    'open ended' => ['2025-07-01', null, true],
]);

it('AC-9: ignores other students, fee types, and applicant overrides', function () {
    $f = keringananFixture();
    $other = FeeType::factory()->create();
    keringananOverride(['student_id' => $f->b->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-01-01']);
    keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $other->id, 'valid_from' => '2026-01-01']);
    keringananOverride(['student_id' => null, 'applicant_id' => Applicant::factory()->create()->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-01-01']);

    expect(app(ResolveFeeOverride::class)->forStudent($f->a, $f->spp, CarbonImmutable::parse('2026-10-01')))->toBeNull();
});

it('AC-9: resolves applicant overrides', function () {
    $f = keringananFixture();
    $applicant = Applicant::factory()->create();
    $otherApplicant = Applicant::factory()->create();
    $override = keringananOverride(['student_id' => null, 'applicant_id' => $applicant->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-01-01']);
    keringananOverride(['student_id' => $f->a->id, 'applicant_id' => null, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-01-01']);

    $resolver = app(ResolveFeeOverride::class);

    expect($resolver->forApplicant($applicant->id, $f->spp, CarbonImmutable::parse('2026-10-01'))?->id)->toBe($override->id)
        ->and($resolver->forApplicant($otherApplicant->id, $f->spp, CarbonImmutable::parse('2026-10-01')))->toBeNull();
});

it('AC-10: picks the latest override within the same month', function () {
    $f = keringananFixture();
    $old = keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-01-01', 'valid_until' => '2026-10-15']);
    $new = keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-10-16', 'valid_until' => null]);
    $resolver = app(ResolveFeeOverride::class);

    expect($resolver->forStudent($f->a, $f->spp, CarbonImmutable::parse('2026-10-01'))->id)->toBe($new->id)
        ->and($resolver->forStudent($f->a, $f->spp, CarbonImmutable::parse('2026-09-01'))->id)->toBe($old->id)
        ->and($resolver->forStudent($f->a, $f->spp, CarbonImmutable::parse('2026-11-01'))->id)->toBe($new->id);
});

it('AC-11: resolves regardless of the signed-in user scope or scholarship status', function () {
    $this->seed(RolePermissionSeeder::class);
    $f = keringananFixture();
    $scholarship = Scholarship::factory()->create();
    $override = keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'scholarship_id' => $scholarship->id, 'valid_from' => '2026-01-01']);
    $scholarship->update(['is_active' => false]);
    $period = CarbonImmutable::parse('2026-10-01');

    $guest = app(ResolveFeeOverride::class)->forStudent($f->a, $f->spp, $period);
    $this->actingAs(keuanganUser('bendahara', ['SD']));
    $scoped = app(ResolveFeeOverride::class)->forStudent($f->a, $f->spp, $period);

    expect($guest?->id)->toBe($override->id)->and($scoped?->id)->toBe($override->id);
});
