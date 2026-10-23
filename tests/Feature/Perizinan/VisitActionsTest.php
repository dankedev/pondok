<?php

use App\Actions\Perizinan\RecordVisit;
use App\Actions\Perizinan\UpdateVisit;
use App\Models\Guardian;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    $this->f = perizinanFixture();
});

function perizinanRecordVisit(object $f, User $actor, array $overrides = []): Visit
{
    test()->actingAs($actor);

    return app(RecordVisit::class)->handle($actor, perizinanVisitData($f, $overrides));
}

it('AC-11: records a visit with the recorder and an activity, for petugas pos and kesantrian', function () {
    $f = $this->f;
    $guardian = Guardian::factory()->create();
    $f->s1->guardians()->attach($guardian->id, ['relation' => 'ayah']);

    $byPos = perizinanRecordVisit($f, $f->pos, ['guardian_id' => $guardian->id]);
    $byKes = perizinanRecordVisit($f, $f->kes, ['student_id' => $f->s4->id, 'visitor_name' => 'Ibu Siti', 'relation' => 'ibu']);

    expect(Visit::withoutGlobalScopes()->count())->toBe(2)
        ->and($byPos->fresh()->recorded_by)->toBe($f->pos->id)
        ->and($byPos->fresh()->guardian_id)->toBe($guardian->id)
        ->and($byPos->fresh()->visitor_name)->toBe('Bapak Ahmad')
        ->and($byPos->fresh()->started_at->format('Y-m-d H:i'))->toBe('2026-10-15 09:00')
        ->and($byPos->fresh()->ended_at)->toBeNull()
        ->and($byKes->fresh()->recorded_by)->toBe($f->kes->id)
        ->and($byKes->fresh()->guardian_id)->toBeNull();

    $created = perizinanActivities('created');

    expect($created)->toHaveCount(2)
        ->and($created->pluck('subject_id')->all())->toBe([$byPos->id, $byKes->id])
        ->and($created->pluck('causer_id')->all())->toBe([$f->pos->id, $f->kes->id]);
});

it('AC-11: ends a visit on update and never changes the student', function () {
    $f = $this->f;
    $visit = perizinanRecordVisit($f, $f->pos);

    $this->actingAs($f->pos);
    app(UpdateVisit::class)->handle($visit, $f->pos, perizinanVisitData($f, [
        'student_id' => $f->s2->id,
        'ended_at' => '2026-10-15 09:45',
        'items_note' => 'Buku dan sabun',
    ]));

    $fresh = Visit::withoutGlobalScopes()->findOrFail($visit->id);
    $updated = perizinanActivities('updated');

    expect($fresh->student_id)->toBe($f->s1->id)
        ->and($fresh->ended_at->format('Y-m-d H:i'))->toBe('2026-10-15 09:45')
        ->and($fresh->items_note)->toBe('Buku dan sabun')
        ->and($fresh->recorded_by)->toBe($f->pos->id)
        ->and($updated)->toHaveCount(1)
        ->and($updated->first()->subject_id)->toBe($visit->id)
        ->and($updated->first()->causer_id)->toBe($f->pos->id)
        ->and($updated->first()->properties['fields'])->toContain('ended_at', 'items_note');
});

it('AC-11: rejects invalid visits without creating a row', function () {
    $f = $this->f;
    $strangerGuardian = Guardian::factory()->create();
    $f->s2->guardians()->attach($strangerGuardian->id, ['relation' => 'ayah']);
    $lulus = tahfidzStudent($f, 'L', null, ['status' => 'lulus']);

    $cases = [
        'wali milik santri lain' => [$f->pos, ['guardian_id' => $strangerGuardian->id], 'guardian_id'],
        'wali tidak ada' => [$f->pos, ['guardian_id' => 999999], 'guardian_id'],
        'selesai sebelum mulai' => [$f->pos, ['ended_at' => '2026-10-15 08:00'], 'ended_at'],
        'mulai di masa depan' => [$f->pos, ['started_at' => '2026-10-15 11:00'], 'started_at'],
        'santri di luar jenjang' => [$f->kesSltp, ['student_id' => $f->s4->id], 'student_id'],
        'santri lulus' => [$f->pos, ['student_id' => $lulus->id], 'student_id'],
        'santri tidak ada' => [$f->pos, ['student_id' => 999999], 'student_id'],
    ];

    foreach ($cases as $label => [$actor, $overrides, $field]) {
        $this->actingAs($actor);

        try {
            tahfidzRejected(fn () => app(RecordVisit::class)->handle($actor, perizinanVisitData($f, $overrides)), $field);
        } catch (Throwable $exception) {
            throw new RuntimeException("Kasus '{$label}': ".$exception->getMessage(), previous: $exception);
        }
    }

    expect(Visit::withoutGlobalScopes()->count())->toBe(0)->and(perizinanActivities())->toBeEmpty();
});

it('AC-11: validates an update like a new record', function () {
    $f = $this->f;
    $visit = perizinanRecordVisit($f, $f->pos);
    $this->actingAs($f->pos);

    tahfidzRejected(fn () => app(UpdateVisit::class)->handle($visit, $f->pos, perizinanVisitData($f, ['ended_at' => '2026-10-15 08:00'])), 'ended_at');
    tahfidzRejected(fn () => app(UpdateVisit::class)->handle($visit, $f->pos, perizinanVisitData($f, ['started_at' => '2026-10-15 12:00'])), 'started_at');

    expect(Visit::withoutGlobalScopes()->findOrFail($visit->id)->ended_at)->toBeNull();
});

it('AC-11: refuses musyrif at the gate for recording and updating', function () {
    $f = $this->f;
    $visit = perizinanRawVisit($f->s1);

    foreach ([$f->mA, $f->mDorm] as $actor) {
        $this->actingAs($actor);

        expect(fn () => app(RecordVisit::class)->handle($actor, perizinanVisitData($f)))->toThrow(AuthorizationException::class)
            ->and(fn () => app(UpdateVisit::class)->handle($visit, $actor, perizinanVisitData($f, ['ended_at' => '2026-10-15 09:30'])))->toThrow(AuthorizationException::class);
    }

    expect(Visit::withoutGlobalScopes()->count())->toBe(1)
        ->and(Visit::withoutGlobalScopes()->findOrFail($visit->id)->ended_at)->toBeNull()
        ->and(perizinanActivities())->toBeEmpty();
});
