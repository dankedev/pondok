<?php

use App\Actions\Psb\UpdateReRegistrationMinimums;
use App\Models\Unit;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed([UnitSeeder::class, RolePermissionSeeder::class]);
    $this->units = Unit::query()->pluck('id', 'code');
    $this->admin = keuanganUser('admin-psb');
});

function minimumOf(string $code): ?int
{
    return Unit::query()->where('code', $code)->value('min_reregistration_amount');
}

it('AC-8: stores null, zero and an amount exactly per unit and logs each change', function () {
    Unit::query()->where('code', 'SLTA')->update(['min_reregistration_amount' => 5000000]);
    Activity::query()->delete();

    app(UpdateReRegistrationMinimums::class)->handle([
        $this->units['SLTP'] => 3000000,
        $this->units['SLTA'] => null,
        $this->units['SD'] => 0,
    ], $this->admin);

    expect(minimumOf('SLTP'))->toBe(3000000)
        ->and(minimumOf('SLTA'))->toBeNull()
        ->and(minimumOf('SD'))->toBe(0);

    $logs = Activity::query()->where('event', 're_registration_minimum_updated')->get();
    $slta = $logs->firstWhere('subject_id', $this->units['SLTA']);

    expect($logs)->toHaveCount(3)
        ->and($slta->properties['old'])->toEqual(5000000)
        ->and($slta->properties['new'])->toBeNull();
});

it('AC-8: logs nothing for a unit whose value stays the same', function () {
    Unit::query()->where('code', 'SLTP')->update(['min_reregistration_amount' => 3000000]);
    Activity::query()->delete();

    app(UpdateReRegistrationMinimums::class)->handle([$this->units['SLTP'] => 3000000, $this->units['SD'] => 100], $this->admin);

    expect(Activity::query()->where('event', 're_registration_minimum_updated')->count())->toBe(1)
        ->and(minimumOf('SD'))->toBe(100);
});

it('AC-8: rejects an invalid value and writes nothing', function (mixed $invalid) {
    Unit::query()->where('code', 'SLTP')->update(['min_reregistration_amount' => 1000000]);
    Activity::query()->delete();
    $unit = $this->units['SLTA'];

    try {
        app(UpdateReRegistrationMinimums::class)->handle([$this->units['SLTP'] => 2000000, $unit => $invalid], $this->admin);
        $this->fail('ValidationException diharapkan.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey("amounts.{$unit}");
    }

    expect(minimumOf('SLTP'))->toBe(1000000)
        ->and(minimumOf('SLTA'))->toBeNull()
        ->and(Activity::query()->where('event', 're_registration_minimum_updated')->count())->toBe(0);
})->with([
    'negative' => [-1],
    'decimal' => [1.5],
    'text' => ['abc'],
    'above one trillion' => [1000000000001],
]);

it('AC-9: refuses a unit outside the scope of the given user without relying on the login session', function () {
    $scoped = keuanganUser('admin-psb', ['SLTP']);

    expect(fn () => app(UpdateReRegistrationMinimums::class)->handle([$this->units['SLTP'] => 1000000, $this->units['SLTA'] => 0], $scoped))
        ->toThrow(ValidationException::class);

    expect(minimumOf('SLTP'))->toBeNull()->and(minimumOf('SLTA'))->toBeNull();

    app(UpdateReRegistrationMinimums::class)->handle([$this->units['SLTP'] => 1000000], $scoped);

    expect(minimumOf('SLTP'))->toBe(1000000);
});

it('AC-9: refuses an unknown unit id and lets a user without unit scope save every unit', function () {
    expect(fn () => app(UpdateReRegistrationMinimums::class)->handle([999999 => 1000], $this->admin))
        ->toThrow(ValidationException::class);

    app(UpdateReRegistrationMinimums::class)->handle([$this->units['SD'] => 1, $this->units['SLTP'] => 2, $this->units['SLTA'] => 3], $this->admin);

    expect(minimumOf('SD'))->toBe(1)->and(minimumOf('SLTP'))->toBe(2)->and(minimumOf('SLTA'))->toBe(3);
});
