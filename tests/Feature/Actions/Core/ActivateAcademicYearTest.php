<?php

use App\Actions\Core\ActivateAcademicYear;
use App\Models\AcademicYear;

it('keeps exactly one academic year active', function () {
    $old = AcademicYear::factory()->create(['is_active' => true]);
    $new = AcademicYear::factory()->create();

    app(ActivateAcademicYear::class)->handle($new);

    expect($new->fresh()->is_active)->toBeTrue()
        ->and($old->fresh()->is_active)->toBeFalse()
        ->and(AcademicYear::query()->where('is_active', true)->count())->toBe(1);
});
