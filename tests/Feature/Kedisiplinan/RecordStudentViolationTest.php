<?php

use App\Actions\Kedisiplinan\RecordStudentViolation;
use App\Filament\Resources\StudentViolations\Pages\CreateStudentViolation;
use App\Filament\Resources\StudentViolations\Pages\ListStudentViolations;
use App\Models\AcademicYear;
use App\Models\RoomAssignment;
use App\Models\StudentViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = disiplinFixture();
});

it('AC-3: records a violation with the type points, the year of the date, the recorder and one created log without the description', function () {
    $f = $this->f;

    $violation = disiplinRecord($f, $f->kes);

    expect(disiplinCount())->toBe(1)
        ->and($violation->student_id)->toBe($f->s1->id)
        ->and($violation->violation_type_id)->toBe($f->ringan->id)
        ->and($violation->points)->toBe(5)
        ->and($violation->academic_year_id)->toBe($f->year->id)
        ->and($violation->occurred_on->toDateString())->toBe('2026-10-14')
        ->and($violation->recorded_by)->toBe($f->kes->id)
        ->and($violation->cancelled_at)->toBeNull()
        ->and($violation->description)->toBe('Terlambat ke masjid saat shalat subuh');

    $logs = disiplinActivities();

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->event)->toBe('created')
        ->and($logs->first()->properties['student_id'])->toBe($f->s1->id)
        ->and($logs->first()->properties['violation_type_id'])->toBe($f->ringan->id)
        ->and($logs->first()->properties['points'])->toBe(5)
        ->and(json_encode($logs->first()->properties))->not->toContain('shalat subuh');
});

it('AC-3: picks the academic year that starts latest when two years contain the date', function () {
    $f = $this->f;
    $overlap = AcademicYear::factory()->create(['name' => '2026/2027 B', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);

    $violation = disiplinRecord($f, $f->kes, ['occurred_on' => '2026-10-14']);
    $old = disiplinRecord($f, $f->kes, ['occurred_on' => '2026-08-15']);

    expect($violation->academic_year_id)->toBe($overlap->id)
        ->and($old->academic_year_id)->toBe($f->year->id);
});

it('AC-3: accepts today and a description of exactly 1000 characters', function () {
    $f = $this->f;

    $violation = disiplinRecord($f, $f->kes, ['occurred_on' => '2026-10-15', 'description' => str_repeat('a', 1000)]);

    expect($violation->occurred_on->toDateString())->toBe('2026-10-15')
        ->and(mb_strlen($violation->description))->toBe(1000);
});

it('AC-3: writes an updated log without the description when a violation is changed', function () {
    $f = $this->f;
    $violation = disiplinRecord($f, $f->kes);

    app(RecordStudentViolation::class)->handle($f->kes, disiplinData($f, ['description' => 'Catatan rahasia baru', 'violation_type_id' => $f->sedang->id]), $violation);

    $logs = disiplinActivities('updated');

    expect($logs)->toHaveCount(1)
        ->and(disiplinActivities())->toHaveCount(2)
        ->and(json_encode($logs->first()->properties))->not->toContain('rahasia');
});

it('AC-4: keeps the old snapshot when the catalog points change, and recopies points when the type is replaced', function () {
    $f = $this->f;
    $violation = disiplinRecord($f, $f->kes);

    $f->ringan->forceFill(['points' => 99])->save();

    expect($violation->fresh()->points)->toBe(5);

    $updated = app(RecordStudentViolation::class)->handle($f->kes, disiplinData($f, ['violation_type_id' => $f->sedang->id]), $violation);

    expect($updated->points)->toBe(25)->and($violation->fresh()->points)->toBe(25);
});

it('AC-4: follows the academic year when the date changes and never changes the student or the recorder', function () {
    $f = $this->f;
    $violation = disiplinRecord($f, $f->kes);

    app(RecordStudentViolation::class)->handle($f->kes, disiplinData($f, [
        'occurred_on' => '2026-03-10', 'student_id' => $f->s2->id, 'recorded_by' => $f->admin->id,
    ]), $violation);

    $fresh = $violation->fresh();

    expect($fresh->academic_year_id)->toBe($f->prevYear->id)
        ->and($fresh->occurred_on->toDateString())->toBe('2026-03-10')
        ->and($fresh->student_id)->toBe($f->s1->id)
        ->and($fresh->recorded_by)->toBe($f->kes->id)
        ->and(disiplinCount())->toBe(1);
});

it('AC-5: rejects invalid input without writing a row', function () {
    $f = $this->f;
    $inactive = disiplinType('Jenis nonaktif', 'ringan', 5, ['is_active' => false]);
    $graduated = tahfidzStudent($f, 'L', null, ['status' => 'lulus']);

    $cases = [
        'tanggal besok' => [['occurred_on' => '2026-10-16'], 'occurred_on'],
        'tanggal di luar tahun ajaran' => [['occurred_on' => '2020-01-01'], 'occurred_on'],
        'jenis nonaktif' => [['violation_type_id' => $inactive->id], 'violation_type_id'],
        'jenis tidak ada' => [['violation_type_id' => 999999], 'violation_type_id'],
        'santri bukan aktif' => [['student_id' => $graduated->id], 'student_id'],
        'santri tidak ada' => [['student_id' => 999999], 'student_id'],
        'deskripsi 1001 karakter' => [['description' => str_repeat('a', 1001)], 'description'],
    ];

    $this->actingAs($f->kes);

    foreach ($cases as $label => [$overrides, $field]) {
        try {
            app(RecordStudentViolation::class)->handle($f->kes, disiplinData($f, $overrides));
            $this->fail("{$label}: ValidationException diharapkan");
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($field, $label);
        }
    }

    expect(disiplinCount())->toBe(0)->and(disiplinActivities())->toHaveCount(0);
});

it('AC-2: refuses to record or change for a user without the permission', function () {
    $f = $this->f;
    $violation = disiplinRaw($f, $f->s1, $f->ringan);

    foreach ([$f->adminSekolah, $f->waliKelas, $f->bendahara] as $user) {
        $this->actingAs($user);

        expect(fn () => app(RecordStudentViolation::class)->handle($user, disiplinData($f)))->toThrow(AuthorizationException::class);
    }

    $this->actingAs($f->musyrif);

    expect(fn () => app(RecordStudentViolation::class)->handle($f->musyrif, disiplinData($f, ['description' => 'diubah']), $violation))
        ->toThrow(AuthorizationException::class)
        ->and(disiplinCount())->toBe(1)
        ->and($violation->fresh()->description)->toBeNull();
});

it('AC-7: lets a musyrif record only for residents of the supervised dormitory', function () {
    $f = $this->f;

    $own = disiplinRecord($f, $f->musyrif);

    expect($own->recorded_by)->toBe($f->musyrif->id)->and(disiplinCount())->toBe(1);

    foreach (['kamar lain' => $f->s2, 'bukan penghuni' => $f->s3, 'jenjang lain' => $f->s4] as $label => $student) {
        tahfidzRejected(fn () => app(RecordStudentViolation::class)->handle($f->musyrif, disiplinData($f, ['student_id' => $student->id])), 'student_id');
    }

    $this->actingAs($f->idle);
    tahfidzRejected(fn () => app(RecordStudentViolation::class)->handle($f->idle, disiplinData($f)), 'student_id');

    expect(disiplinCount())->toBe(1);
});

it('AC-7: shows a musyrif only the violations of own residents, and offers only them and active types in the form', function () {
    $f = $this->f;
    $own = disiplinRaw($f, $f->s1, $f->ringan);
    $other = disiplinRaw($f, $f->s2, $f->ringan);
    $nonResident = disiplinRaw($f, $f->s3, $f->ringan);
    $otherUnit = disiplinRaw($f, $f->s4, $f->ringan);
    $inactive = disiplinType('Jenis nonaktif', 'ringan', 5, ['is_active' => false]);

    $this->actingAs($f->musyrif);

    Livewire::test(ListStudentViolations::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$other, $nonResident, $otherUnit]);

    expect(StudentViolation::query()->pluck('id')->all())->toBe([$own->id]);

    $form = Livewire::test(CreateStudentViolation::class);

    expect(disiplinOptions($form, 'student_id', 'Santri'))->toBe([$f->s1->id])
        ->and(disiplinOptions($form, 'violation_type_id'))->toContain($f->ringan->id, $f->sedang->id, $f->berat->id)->not->toContain($inactive->id);
});

it('AC-7: hides the old records from a musyrif once the student moved to another room', function () {
    $f = $this->f;
    $violation = disiplinRaw($f, $f->s1, $f->ringan);

    $this->actingAs($f->musyrif);
    expect(StudentViolation::query()->pluck('id')->all())->toBe([$violation->id]);

    RoomAssignment::query()->where('student_id', $f->s1->id)->update(['end_on' => '2026-10-14']);
    asramaResident($f->s1, $f->roomB, ['start_on' => '2026-10-15']);

    expect(StudentViolation::query()->count())->toBe(0);
    Livewire::test(ListStudentViolations::class)->assertCanNotSeeTableRecords([$violation]);
});

it('AC-8: keeps a unit scoped kesantrian away from violations of another unit and refuses to record for it', function () {
    $f = $this->f;
    $sltp = disiplinRaw($f, $f->s1, $f->ringan);
    $slta = disiplinRaw($f, $f->s4, $f->ringan);

    $this->actingAs($f->kesSltp);

    Livewire::test(ListStudentViolations::class)->assertCanSeeTableRecords([$sltp])->assertCanNotSeeTableRecords([$slta]);
    expect(StudentViolation::query()->pluck('id')->all())->toBe([$sltp->id]);

    $this->get("/admin/student-violations/{$slta->id}/edit")->assertNotFound();
    tahfidzRejected(fn () => app(RecordStudentViolation::class)->handle($f->kesSltp, disiplinData($f, ['student_id' => $f->s4->id])), 'student_id');

    $options = disiplinOptions(Livewire::test(CreateStudentViolation::class), 'student_id', 'Santri');

    expect($options)->toContain($f->s1->id)->not->toContain($f->s4->id)
        ->and(disiplinCount())->toBe(2);

    $this->actingAs($f->kes);
    Livewire::test(ListStudentViolations::class)->assertCanSeeTableRecords([$sltp, $slta]);
});
