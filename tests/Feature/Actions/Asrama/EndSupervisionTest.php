<?php

use App\Actions\Asrama\EndSupervision;
use App\Models\AcademicYear;
use App\Models\DormitorySupervisor;
use Carbon\Carbon;

it('AC-22: ends the supervision', function () {
    $supervisor = DormitorySupervisor::factory()->create(['start_on' => '2026-07-15', 'end_on' => null]);

    $result = app(EndSupervision::class)->handle($supervisor, Carbon::parse('2026-10-01'));

    expect($result->id)->toBe($supervisor->id)
        ->and($supervisor->fresh()->end_on->toDateString())->toBe('2026-10-01')
        ->and(DormitorySupervisor::query()->count())->toBe(1);
});

it('AC-22: rejects ending', function (bool $alreadyEnded, string $endOn, string $reason) {
    $supervisor = DormitorySupervisor::factory()->create([
        'start_on' => '2026-07-15',
        'end_on' => $alreadyEnded ? '2026-09-01' : null,
    ]);

    asramaAssertRejected(fn () => app(EndSupervision::class)->handle($supervisor, Carbon::parse($endOn)), $reason);

    expect($supervisor->fresh()->end_on?->toDateString())->toBe($alreadyEnded ? '2026-09-01' : null);
})->with([
    'already ended' => [true, '2026-10-01', 'supervision_already_ended'],
    'before start' => [false, '2026-07-01', 'end_before_start'],
]);

it('AC-23: scopes active supervisors to the active academic year', function () {
    $active = AcademicYear::factory()->active()->create();
    $past = AcademicYear::factory()->create();
    $current = DormitorySupervisor::factory()->create(['academic_year_id' => $active->id, 'end_on' => null]);
    DormitorySupervisor::factory()->create(['academic_year_id' => $past->id, 'end_on' => null]);
    DormitorySupervisor::factory()->ended()->create(['academic_year_id' => $active->id]);

    expect(DormitorySupervisor::query()->active()->pluck('id')->all())->toBe([$current->id]);
});
