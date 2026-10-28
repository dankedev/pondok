<?php

use App\Models\AttendanceSession;
use App\Models\Classroom;
use App\Models\DiniyahGrade;
use App\Models\DiniyahReportNote;
use App\Models\DiniyahSubject;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Storage;

it('F097: seeds diniyah subjects with optional KKM, grades with some below KKM, and attendance for every classroom, idempotently', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00', 'Asia/Jakarta'));
    Storage::fake('private');
    Storage::fake('public');

    $this->seed(DemoSeeder::class);

    $subjects = DiniyahSubject::withoutUnitScope()->get();
    $grades = DiniyahGrade::query()->with('subject')->get();
    $classrooms = Classroom::query()->whereHas('academicYear', fn ($query) => $query->where('is_active', true))->get();

    expect($subjects->pluck('name')->unique()->sort()->values()->all())
        ->toBe(['Aqidah', 'Bahasa Arab', 'Fiqih', 'Hadits', 'Sirah Nabawiyah', 'Tahsin'])
        ->and($subjects)->toHaveCount(12)
        ->and($subjects->whereNull('passing_score')->count())->toBeGreaterThan(0)
        ->and($subjects->whereNotNull('passing_score')->count())->toBeGreaterThan(0)
        ->and($grades->count())->toBeGreaterThan(0)
        ->and($grades->filter(fn (DiniyahGrade $grade): bool => $grade->subject->isBelowPassing($grade->score))->count())->toBeGreaterThan(0)
        ->and(DiniyahReportNote::query()->count())->toBeGreaterThan(0);

    foreach ($classrooms as $classroom) {
        if ($classroom->rosterStudents()->exists()) {
            expect(AttendanceSession::withoutGlobalScopes()->where('classroom_id', $classroom->id)->count())
                ->toBeGreaterThanOrEqual(5, "rombel {$classroom->name} harus punya absensi");
        }
    }

    $counts = [$subjects->count(), $grades->count(), DiniyahReportNote::query()->count(), AttendanceSession::withoutGlobalScopes()->count()];

    $this->seed(DemoSeeder::class);

    expect([DiniyahSubject::withoutUnitScope()->count(), DiniyahGrade::query()->count(), DiniyahReportNote::query()->count(), AttendanceSession::withoutGlobalScopes()->count()])
        ->toBe($counts);
})->group('seeder-demo');
