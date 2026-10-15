<?php

use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\CustomColumnScopedRecord;
use Tests\Fixtures\ScopedRecord;

beforeEach(function () {
    $this->seed(UnitSeeder::class);

    Schema::create('scoped_records', function (Blueprint $table) {
        $table->id();
        $table->foreignId('unit_id')->constrained();
        $table->foreignId('target_unit_id')->nullable()->constrained('units');
        $table->string('label');
        $table->timestamps();
    });

    $this->units = Unit::query()->pluck('id', 'code');

    foreach (['SD', 'SLTP', 'SLTA'] as $code) {
        ScopedRecord::query()->create([
            'unit_id' => $this->units[$code],
            'target_unit_id' => $this->units[$code === 'SD' ? 'SLTA' : 'SD'],
            'label' => $code,
        ]);
    }
});

it('returns every unit to a guest such as the scheduler or queue', function () {
    expect(ScopedRecord::query()->pluck('label')->sort()->values()->all())
        ->toBe(['SD', 'SLTA', 'SLTP']);
});

it('returns every unit to a user without unit_user rows', function () {
    $this->actingAs(User::factory()->create());

    expect(ScopedRecord::query()->count())->toBe(3);
});

it('returns only the assigned unit to a user scoped to one unit', function () {
    $user = User::factory()->create();
    $user->units()->attach($this->units['SLTP']);

    $this->actingAs($user);

    expect(ScopedRecord::query()->pluck('label')->all())->toBe(['SLTP'])
        ->and(ScopedRecord::query()->find(ScopedRecord::withoutUnitScope()->where('label', 'SD')->value('id')))->toBeNull();
});

it('returns all assigned units to a user scoped to several units', function () {
    $user = User::factory()->create();
    $user->units()->attach([$this->units['SD'], $this->units['SLTA']]);

    $this->actingAs($user);

    expect(ScopedRecord::query()->pluck('label')->sort()->values()->all())->toBe(['SD', 'SLTA']);
});

it('does not exempt super-admin when they have unit_user rows', function () {
    $this->seed(RolePermissionSeeder::class);
    $user = User::factory()->create()->assignRole('super-admin');
    $user->units()->attach($this->units['SD']);

    $this->actingAs($user);

    expect(ScopedRecord::query()->pluck('label')->all())->toBe(['SD']);
});

it('returns every unit when the scope is removed explicitly', function () {
    $user = User::factory()->create();
    $user->units()->attach($this->units['SLTP']);

    $this->actingAs($user);

    expect(ScopedRecord::withoutUnitScope()->count())->toBe(3);
});

it('filters on the column configured by the model', function () {
    $user = User::factory()->create();
    $user->units()->attach($this->units['SD']);

    $this->actingAs($user);

    // target_unit_id = SD untuk baris SLTP dan SLTA.
    expect(CustomColumnScopedRecord::query()->pluck('label')->sort()->values()->all())->toBe(['SLTA', 'SLTP']);
});
