<?php

use App\Actions\Keuangan\EndFeeOverride;
use App\Actions\Keuangan\ResolveFeeOverride;
use App\Actions\Keuangan\SaveFeeOverride;
use App\Filament\Resources\FeeOverrides\Pages\ManageFeeOverrides;
use App\Models\FeeOverride;
use App\Models\Scholarship;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
});

it('VERIFY: rejects impossible calendar dates and padded dates', function (string $value) {
    $f = keringananFixture();

    keuanganAssertRejected(
        fn () => app(SaveFeeOverride::class)->handle(keringananOverrideData($f, ['valid_from' => $value]), keuanganUser('bendahara')),
        'date_invalid',
        'valid_from',
    );
})->with(['2026-02-30', '2026-13-01', ' 2026-07-01', '2026-7-1', '2026-07-01 10:00:00']);

it('VERIFY: rejects values that overflow, are floats, or booleans', function (mixed $value) {
    $f = keringananFixture();

    keuanganAssertRejected(
        fn () => app(SaveFeeOverride::class)->handle(keringananOverrideData($f, ['value' => $value]), keuanganUser('bendahara')),
        'value_invalid',
        'value',
    );

    expect(FeeOverride::query()->count())->toBe(0);
})->with(['overflow' => ['99999999999999999999'], 'float' => [12.5], 'bool' => [true], 'negative string' => ['-5'], 'empty' => ['']]);

it('VERIFY: enforces the percent range and mode validity on edit', function () {
    $f = keringananFixture();
    $override = keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-07-01', 'valid_until' => null]);
    $user = keuanganUser('bendahara');

    keuanganAssertRejected(fn () => app(SaveFeeOverride::class)->handle(keringananOverrideData($f, ['mode' => 'persen', 'value' => 101]), $user, $override), 'percent_range', 'value');
    keuanganAssertRejected(fn () => app(SaveFeeOverride::class)->handle(keringananOverrideData($f, ['scholarship_id' => 999999]), $user, $override), 'scholarship_not_found', 'scholarship_id');
    keuanganAssertRejected(fn () => app(SaveFeeOverride::class)->handle(keringananOverrideData($f, ['fee_type_id' => 999999]), $user, $override), 'fee_type_not_found', 'fee_type_id');
});

it('VERIFY: treats a blank string student id as no owner', function () {
    $f = keringananFixture();

    keuanganAssertRejected(
        fn () => app(SaveFeeOverride::class)->handle(keringananOverrideData($f, ['student_id' => '', 'applicant_id' => '']), keuanganUser('bendahara')),
        'owner_required',
        'student_id',
    );
});

it('VERIFY: resolves across the year boundary and ignores time of day', function () {
    $f = keringananFixture();
    $override = keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-12-31', 'valid_until' => '2027-01-01']);
    $resolver = app(ResolveFeeOverride::class);

    expect($resolver->forStudent($f->a, $f->spp, CarbonImmutable::parse('2026-12-01 23:59:59'))?->id)->toBe($override->id)
        ->and($resolver->forStudent($f->a, $f->spp, CarbonImmutable::parse('2027-01-31 23:59:59'))?->id)->toBe($override->id)
        ->and($resolver->forStudent($f->a, $f->spp, CarbonImmutable::parse('2027-02-01 00:00:00')))->toBeNull()
        ->and($resolver->forStudent($f->a, $f->spp, CarbonImmutable::parse('2026-11-30 23:59:59')))->toBeNull();
});

it('VERIFY: ends with a time component and a leap day', function () {
    $f = keringananFixture();
    $override = keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2028-01-01', 'valid_until' => null]);

    app(EndFeeOverride::class)->handle($override, CarbonImmutable::parse('2028-02-29 18:30:00'));

    expect($override->fresh()->valid_until->toDateString())->toBe('2028-02-29');
});

it('VERIFY: ending the first day of an override is allowed', function () {
    $f = keringananFixture();
    $override = keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-07-01', 'valid_until' => null]);

    app(EndFeeOverride::class)->handle($override, CarbonImmutable::parse('2026-07-01'));

    expect($override->fresh()->valid_until->toDateString())->toBe('2026-07-01');
});

it('VERIFY: bendahara cannot delete overrides or scholarships by policy', function () {
    $bendahara = keuanganUser('bendahara');
    $override = keringananOverride();
    $scholarship = Scholarship::factory()->create();

    expect($bendahara->can('delete', $override))->toBeFalse()
        ->and($bendahara->can('deleteAny', FeeOverride::class))->toBeFalse()
        ->and($bendahara->can('delete', $scholarship))->toBeFalse()
        ->and($bendahara->can('deleteAny', Scholarship::class))->toBeFalse();
});

it('VERIFY: override table does not issue queries per row', function () {
    Filament::setCurrentPanel('admin');
    $f = keringananFixture();
    $this->actingAs(keuanganUser('bendahara'));
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ManageFeeOverrides::class)->assertSuccessful();

        return count(DB::getQueryLog());
    };

    keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id]);
    $few = $count();

    foreach (range(1, 12) as $i) {
        keringananOverride(['student_id' => Student::factory()->forCohort($f->a->cohort)->create()->id]);
    }
    $many = $count();

    expect($many)->toBeLessThanOrEqual($few + 3);
});

it('VERIFY: filter validity respects month-edge dates', function () {
    Filament::setCurrentPanel('admin');
    $this->travelTo('2026-10-31 23:30:00');
    $f = keringananFixture();
    $this->actingAs(keuanganUser('bendahara'));
    $endsToday = keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-01-01', 'valid_until' => '2026-10-31']);
    $startsTomorrow = keringananOverride(['student_id' => $f->b->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-11-01', 'valid_until' => null]);
    $endedYesterday = keringananOverride(['student_id' => $f->c->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-01-01', 'valid_until' => '2026-10-30']);

    Livewire::test(ManageFeeOverrides::class)
        ->filterTable('validity', 'aktif')
        ->assertCanSeeTableRecords([$endsToday])
        ->assertCanNotSeeTableRecords([$startsTomorrow, $endedYesterday])
        ->filterTable('validity', 'berakhir')
        ->assertCanSeeTableRecords([$endedYesterday])
        ->assertCanNotSeeTableRecords([$endsToday, $startsTomorrow]);
});
