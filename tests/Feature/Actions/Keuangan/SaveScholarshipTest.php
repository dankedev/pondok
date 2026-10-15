<?php

use App\Actions\Keuangan\SaveScholarship;
use App\Models\Scholarship;

function keringananSaveScholarship(array $data = [], ?Scholarship $scholarship = null): Scholarship
{
    return app(SaveScholarship::class)->handle(keringananScholarshipData($data), $scholarship);
}

it('AC-12: saves a scholarship', function () {
    $scholarship = keringananSaveScholarship(['name' => ' Beasiswa Tahfidz ', 'default_value' => '50', 'sponsor' => '']);

    expect($scholarship->fresh())
        ->name->toBe('Beasiswa Tahfidz')
        ->mode->toBe('persen')
        ->default_value->toBe(50)
        ->sponsor->toBeNull()
        ->is_active->toBeTrue();
});

it('AC-13: rejects the scholarship', function (array $data, string $reason, string $field) {
    Scholarship::factory()->create(['name' => 'Yatim']);

    keuanganAssertRejected(fn () => keringananSaveScholarship($data), $reason, $field);

    expect(Scholarship::query()->count())->toBe(1);
})->with([
    'blank name' => [['name' => '  '], 'name_required', 'name'],
    'taken name' => [['name' => 'Yatim'], 'scholarship_name_taken', 'name'],
    'invalid mode' => [['mode' => 'gratis'], 'invalid_mode', 'mode'],
    'negative value' => [['default_value' => -1], 'value_invalid', 'default_value'],
    'decimal value' => [['default_value' => '12.5'], 'value_invalid', 'default_value'],
    'null value' => [['default_value' => null], 'value_invalid', 'default_value'],
    'percent above 100' => [['mode' => 'persen', 'default_value' => 101], 'percent_range', 'default_value'],
]);

it('AC-13: updates a scholarship without clashing with itself', function () {
    $scholarship = keringananSaveScholarship(['name' => 'Yatim']);

    $updated = keringananSaveScholarship(['name' => 'Yatim', 'sponsor' => 'Yayasan'], $scholarship);

    expect($updated->id)->toBe($scholarship->id)
        ->and($scholarship->fresh()->sponsor)->toBe('Yayasan')
        ->and(Scholarship::query()->count())->toBe(1);
});

it('AC-14: does not change existing overrides when the scholarship changes', function () {
    $scholarship = keringananSaveScholarship(['mode' => 'potongan', 'default_value' => 100000]);
    $override = keringananOverride(['scholarship_id' => $scholarship->id, 'mode' => 'potongan', 'value' => 100000]);

    keringananSaveScholarship(['mode' => 'persen', 'default_value' => 25], $scholarship);

    expect($override->fresh())->mode->toBe('potongan')->value->toBe(100000)
        ->and($scholarship->fresh())->mode->toBe('persen')->default_value->toBe(25);
});
