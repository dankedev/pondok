<?php

use App\Actions\Asrama\VacateRoom;
use App\Models\RoomAssignment;
use Carbon\Carbon;

it('AC-12: ends the active assignment and makes the student nonresident', function () {
    $student = asramaStudent('L');
    $assignment = asramaResident($student, asramaRoom(), ['start_on' => '2026-07-15']);

    $result = app(VacateRoom::class)->handle($student, Carbon::parse('2026-10-01'), 'Lulus');

    expect($result->id)->toBe($assignment->id)
        ->and($assignment->fresh()->end_on->toDateString())->toBe('2026-10-01')
        ->and($assignment->fresh()->note)->toBe('Lulus')
        ->and(RoomAssignment::query()->count())->toBe(1)
        ->and($student->fresh()->isResident())->toBeFalse();
});

it('AC-13: rejects vacating', function (bool $isResident, ?string $endOn, string $reason) {
    $student = asramaStudent('L');
    $assignment = $isResident ? asramaResident($student, asramaRoom(), ['start_on' => '2026-07-15']) : null;

    asramaAssertRejected(fn () => app(VacateRoom::class)->handle($student, $endOn ? Carbon::parse($endOn) : null), $reason);

    if ($assignment) {
        expect($assignment->fresh()->end_on)->toBeNull();
    }
})->with([
    'not resident' => [false, null, 'not_resident'],
    'end before start' => [true, '2026-07-01', 'end_before_start'],
]);

it('keeps the existing note when no note is given', function () {
    $student = asramaStudent('L');
    $assignment = asramaResident($student, asramaRoom(), ['start_on' => '2026-07-15', 'note' => 'Catatan awal']);

    app(VacateRoom::class)->handle($student, Carbon::parse('2026-10-01'));

    expect($assignment->fresh()->note)->toBe('Catatan awal');
});
