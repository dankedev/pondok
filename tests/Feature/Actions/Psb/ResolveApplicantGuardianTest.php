<?php

use App\Actions\Psb\ResolveApplicantGuardian;
use App\Models\Cohort;
use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->f = daftarUlangFixture();
});

/**
 * Wali lama yang terhubung ke seorang kakak (ayah, kontak tagihan).
 */
function daftarUlangSiblingGuardian(object $f, string $name, string $phone): array
{
    $cohort = Cohort::query()->firstOrCreate(['unit_id' => $f->sltp->id, 'entry_year' => 2024], ['name' => 'Angkatan 2024']);
    $sibling = Student::factory()->forCohort($cohort)->create();
    $guardian = Guardian::factory()->create(['name' => $name, 'phone' => $phone, 'occupation' => 'Guru', 'address' => 'Jl. Lama 1']);
    $sibling->guardians()->attach($guardian->id, ['relation' => 'ayah', 'is_billing_contact' => true]);

    return [$guardian, $sibling];
}

function daftarUlangPivot(Student $student, Guardian $guardian): ?object
{
    return DB::table('student_guardian')->where('student_id', $student->id)->where('guardian_id', $guardian->id)->first();
}

it('AC-19: creates a guardian', function (?string $relation, string $expected) {
    $applicant = daftarUlangApplicant($this->f);

    $result = daftarUlangRun($applicant, $relation === null ? [] : ['relation' => $relation]);
    $pivot = daftarUlangPivot($result['student'], $result['guardian']);

    expect($result['guardian_reused'])->toBeFalse()
        ->and($result['guardian'])->name->toBe('Budi Santoso')->phone->toBe('081234567890')
        ->and(Guardian::query()->count())->toBe(1)
        ->and($pivot->relation)->toBe($expected)
        ->and((bool) $pivot->is_billing_contact)->toBeTrue()
        ->and(DB::table('student_guardian')->count())->toBe(1);
})->with([
    'default relation' => [null, 'wali'],
    'father' => ['ayah', 'ayah'],
    'mother' => ['ibu', 'ibu'],
]);

it('AC-19: resolves the guardian without saving anything unexpected', function () {
    $applicant = daftarUlangApplicant($this->f);

    $result = app(ResolveApplicantGuardian::class)->handle($applicant);

    expect($result)->toHaveKeys(['guardian', 'reused'])
        ->and($result['reused'])->toBeFalse()
        ->and($result['guardian'])->toBeInstanceOf(Guardian::class)
        ->and($result['guardian']->name)->toBe('Budi Santoso');
});

it('AC-20: reuses a sibling guardian', function (string $phone) {
    [$guardian, $sibling] = daftarUlangSiblingGuardian($this->f, 'budi  santoso ', $phone);
    $siblingPivot = daftarUlangPivot($sibling, $guardian);
    $applicant = daftarUlangApplicant($this->f);

    $result = daftarUlangRun($applicant, ['relation' => 'ibu']);
    $pivot = daftarUlangPivot($result['student'], $guardian);

    expect($result['guardian_reused'])->toBeTrue()
        ->and($result['guardian']->id)->toBe($guardian->id)
        ->and(Guardian::query()->count())->toBe(1)
        ->and($guardian->fresh())->name->toBe('budi  santoso ')->phone->toBe($phone)->occupation->toBe('Guru')->address->toBe('Jl. Lama 1')
        ->and($pivot->relation)->toBe('ibu')
        ->and((bool) $pivot->is_billing_contact)->toBeTrue()
        ->and(daftarUlangPivot($sibling, $guardian))->toEqual($siblingPivot)
        ->and($sibling->guardians()->count())->toBe(1);
})->with([
    'plus 62' => ['+6281234567890'],
    '62' => ['6281234567890'],
    '08' => ['081234567890'],
]);

it('AC-21: avoids merging different guardians', function (string $name, string $phone) {
    [$guardian] = daftarUlangSiblingGuardian($this->f, $name, $phone);
    $applicant = daftarUlangApplicant($this->f);

    $result = daftarUlangRun($applicant);

    expect($result['guardian_reused'])->toBeFalse()
        ->and($result['guardian']->id)->not->toBe($guardian->id)
        ->and(Guardian::query()->count())->toBe(2)
        ->and($result['guardian'])->name->toBe('Budi Santoso')->phone->toBe('081234567890');
})->with([
    'same number, other name' => ['Siti Aminah', '081234567890'],
    'same name, number in another format' => ['Budi Santoso', '0812-3456-7890'],
    'same name, other number' => ['Budi Santoso', '081200000000'],
]);

it('AC-21: picks the guardian with the smallest id when two match', function () {
    [$first] = daftarUlangSiblingGuardian($this->f, 'Budi Santoso', '081234567890');
    $second = Guardian::factory()->create(['name' => 'BUDI SANTOSO', 'phone' => '+6281234567890']);

    $result = daftarUlangRun(daftarUlangApplicant($this->f));

    expect($result['guardian_reused'])->toBeTrue()
        ->and($result['guardian']->id)->toBe(min($first->id, $second->id))
        ->and(Guardian::query()->count())->toBe(2);
});

it('AC-21: rejects an unknown relation', function () {
    $applicant = daftarUlangApplicant($this->f);
    $before = daftarUlangSnapshot();

    daftarUlangAssertRejected(fn () => daftarUlangRun($applicant, ['relation' => 'paman']), 'relation_invalid', 'relation');

    expect(daftarUlangSnapshot())->toBe($before);
});
