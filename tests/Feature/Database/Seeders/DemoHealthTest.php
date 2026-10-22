<?php

use App\Models\HealthVisit;
use App\Models\RoomAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Storage;

it('AC-14: seeds four to six demo health visits through the Action and stays idempotent', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00', 'Asia/Jakarta'));
    Storage::fake('private');
    Storage::fake('public');

    $this->seed(DemoSeeder::class);

    $visits = HealthVisit::withoutUnitScope()->get();

    expect($visits->count())->toBeGreaterThanOrEqual(4)->toBeLessThanOrEqual(6)
        ->and($visits->where('needs_referral', true)->count())->toBeGreaterThanOrEqual(1)
        ->and($visits->where('needs_leave', true)->count())->toBeGreaterThanOrEqual(1)
        ->and($visits->whereNull('recorded_by')->count())->toBe(0)
        ->and(kesehatanActivities('created'))->toHaveCount($visits->count());

    $musyrif = User::query()->where('email', 'musyrif@demo.test')->firstOrFail();
    $byMusyrif = $visits->where('recorded_by', $musyrif->id);

    expect($byMusyrif->count())->toBeGreaterThanOrEqual(1);

    foreach ($byMusyrif as $visit) {
        expect(RoomAssignment::withoutUnitScope()->where('student_id', $visit->student_id)->whereNull('end_on')->exists())
            ->toBeTrue('santri catatan musyrif harus penghuni aktif');
    }

    $this->seed(DemoSeeder::class);

    expect(HealthVisit::withoutUnitScope()->count())->toBe($visits->count())
        ->and(kesehatanActivities('created'))->toHaveCount($visits->count());
})->group('seeder-demo');
