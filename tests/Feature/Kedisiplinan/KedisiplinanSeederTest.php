<?php

use App\Enums\ViolationCategory;
use App\Models\AcademicYear;
use App\Models\DisciplineLevel;
use App\Models\StudentViolation;
use App\Models\ViolationType;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Database\Seeders\InstallSeeder;
use Illuminate\Support\Facades\Storage;

it('AC-12: installs eight violation types in three categories and three thresholds, idempotently', function () {
    $this->seed(InstallSeeder::class);

    $byCategory = ViolationType::query()->get()->groupBy(fn (ViolationType $type): string => $type->category->value)->map->count()->all();

    expect(ViolationType::query()->count())->toBe(8)
        ->and($byCategory)->toBe(['ringan' => 3, 'sedang' => 3, 'berat' => 2])
        ->and(ViolationType::query()->where('is_active', false)->count())->toBe(0)
        ->and(DisciplineLevel::query()->orderBy('min_points')->pluck('name', 'min_points')->all())->toBe([25 => 'Peringatan 1', 50 => 'Peringatan 2', 75 => 'Peringatan 3'])
        ->and(array_map(fn (ViolationCategory $category): string => $category->value, ViolationCategory::cases()))->toBe(['ringan', 'sedang', 'berat']);

    $type = ViolationType::query()->where('name', 'Berkelahi')->firstOrFail();
    $type->forceFill(['points' => 60])->save();
    DisciplineLevel::query()->where('min_points', 25)->update(['name' => 'Teguran']);

    $this->seed(InstallSeeder::class);

    expect(ViolationType::query()->count())->toBe(8)
        ->and($type->fresh()->points)->toBe(60)
        ->and(DisciplineLevel::query()->count())->toBe(3)
        ->and(DisciplineLevel::query()->where('min_points', 25)->value('name'))->toBe('Teguran');
});

it('AC-12: seeds demo violations through the Action including a cancelled one, idempotently', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00', 'Asia/Jakarta'));
    Storage::fake('private');
    Storage::fake('public');

    $this->seed(DemoSeeder::class);

    $violations = StudentViolation::withoutGlobalScopes()->get();

    expect($violations->count())->toBeGreaterThanOrEqual(3)
        ->and($violations->whereNotNull('cancelled_at')->count())->toBeGreaterThanOrEqual(1)
        ->and($violations->whereNull('cancelled_at')->count())->toBeGreaterThanOrEqual(2)
        ->and($violations->whereNotNull('cancelled_at')->every(fn (StudentViolation $violation): bool => filled($violation->cancel_reason)))->toBeTrue()
        ->and($violations->every(fn (StudentViolation $violation): bool => $violation->recorded_by !== null && $violation->academic_year_id === AcademicYear::query()
            ->whereDate('starts_on', '<=', $violation->occurred_on)->whereDate('ends_on', '>=', $violation->occurred_on)->orderByDesc('starts_on')->value('id')))->toBeTrue();

    $this->seed(DemoSeeder::class);

    expect(StudentViolation::withoutGlobalScopes()->count())->toBe($violations->count());
})->group('seeder-demo');
