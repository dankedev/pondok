<?php

use App\Actions\Kesehatan\DeleteHealthVisit;
use App\Actions\Kesehatan\RecordHealthVisit;
use App\Actions\Kesehatan\UpdateHealthVisit;
use App\Models\HealthVisit;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->f = kesehatanFixture();
});

it('AC-3: stores complaint, treatment and note encrypted and reads them back whole', function () {
    $visit = kesehatanRaw($this->f->s1, [
        'complaint' => 'Nyeri perut hebat',
        'treatment' => 'Kompres hangat',
        'note' => 'Wali sudah dihubungi',
    ]);

    $raw = DB::table('health_visits')->where('id', $visit->id)->first();

    foreach (['complaint' => 'Nyeri perut hebat', 'treatment' => 'Kompres hangat', 'note' => 'Wali sudah dihubungi'] as $column => $plain) {
        expect($raw->{$column})->not->toBe($plain)->and($raw->{$column})->not->toContain($plain, "kolom {$column}");
    }

    $fresh = HealthVisit::query()->findOrFail($visit->id);

    expect($fresh->complaint)->toBe('Nyeri perut hebat')
        ->and($fresh->treatment)->toBe('Kompres hangat')
        ->and($fresh->note)->toBe('Wali sudah dihubungi');
});

it('AC-4: records a visit as kesantrian with a text-free activity log', function () {
    $f = $this->f;

    $visit = kesehatanRecord($f, $f->kes, ['needs_referral' => true]);

    $row = HealthVisit::query()->sole();
    $activity = kesehatanActivities()->sole();

    expect($row->is($visit))->toBeTrue()
        ->and($row->student_id)->toBe($f->s1->id)
        ->and($row->recorded_by)->toBe($f->kes->id)
        ->and($row->needs_referral)->toBeTrue()
        ->and($row->needs_leave)->toBeFalse()
        ->and($row->complaint)->toBe('Demam dan sakit kepala sejak semalam')
        ->and($activity->event)->toBe('created')
        ->and($activity->subject_id)->toBe($row->id)
        ->and($activity->causer_id)->toBe($f->kes->id);

    $json = json_encode($activity->properties);

    expect($json)->not->toContain('Demam')->not->toContain('parasetamol')->not->toContain('Dipantau');
});

it('AC-4: defaults both flags to false when they are not sent', function () {
    $f = $this->f;
    $data = kesehatanData($f);
    unset($data['needs_referral'], $data['needs_leave']);
    $this->actingAs($f->kes);

    $visit = app(RecordHealthVisit::class)->handle($f->kes, $data);

    expect($visit->needs_referral)->toBeFalse()->and($visit->needs_leave)->toBeFalse();
});

it('AC-5: rejects invalid visits without creating a row', function () {
    $f = $this->f;
    $lulus = tahfidzStudent($f, 'L', null, ['status' => 'lulus']);
    $keluar = tahfidzStudent($f, 'L', null, ['status' => 'keluar']);

    $cases = [
        'keluhan kosong' => [$f->kes, ['complaint' => ''], 'complaint'],
        'keluhan tidak dikirim' => [$f->kes, ['complaint' => null], 'complaint'],
        'keluhan 1001 karakter' => [$f->kes, ['complaint' => str_repeat('a', 1001)], 'complaint'],
        'tindakan 1001 karakter' => [$f->kes, ['treatment' => str_repeat('a', 1001)], 'treatment'],
        'catatan 1001 karakter' => [$f->kes, ['note' => str_repeat('a', 1001)], 'note'],
        'waktu kosong' => [$f->kes, ['visited_at' => null], 'visited_at'],
        'waktu di masa depan' => [$f->kes, ['visited_at' => '2026-10-15 10:30'], 'visited_at'],
        'santri lulus' => [$f->kes, ['student_id' => $lulus->id], 'student_id'],
        'santri keluar' => [$f->kes, ['student_id' => $keluar->id], 'student_id'],
        'santri tidak ada' => [$f->kes, ['student_id' => 999999], 'student_id'],
        'santri di luar jenjang' => [$f->kesSltp, ['student_id' => $f->s4->id], 'student_id'],
        'santri di luar binaan musyrif (kamar lain)' => [$f->musyrif, ['student_id' => $f->s2->id], 'student_id'],
        'santri nonmukim untuk musyrif' => [$f->musyrif, ['student_id' => $f->s3->id], 'student_id'],
        'musyrif tanpa penugasan aktif' => [$f->idle, ['student_id' => $f->s1->id], 'student_id'],
    ];

    foreach ($cases as $label => [$actor, $overrides, $field]) {
        $this->actingAs($actor);

        try {
            tahfidzRejected(fn () => app(RecordHealthVisit::class)->handle($actor, kesehatanData($f, $overrides)), $field);
        } catch (Throwable $exception) {
            throw new RuntimeException("Kasus '{$label}': ".$exception->getMessage(), previous: $exception);
        }
    }

    expect(HealthVisit::query()->count())->toBe(0)->and(kesehatanActivities())->toBeEmpty();
});

it('AC-5: accepts texts at exactly 1000 characters and optional treatment and note', function () {
    $f = $this->f;

    $visit = kesehatanRecord($f, $f->kes, ['complaint' => str_repeat('a', 1000), 'treatment' => null, 'note' => '']);

    expect(HealthVisit::query()->count())->toBe(1)->and($visit->complaint)->toHaveLength(1000);
});

it('AC-6: updates text, time and flags and keeps student and recorder', function () {
    $f = $this->f;
    $visit = kesehatanRecord($f, $f->kes);
    $this->actingAs($f->kesSltp);

    $updated = app(UpdateHealthVisit::class)->handle($visit, $f->kesSltp, kesehatanData($f, [
        'student_id' => $f->s2->id,
        'recorded_by' => $f->admin->id,
        'visited_at' => '2026-10-14 17:00',
        'complaint' => 'Demam tinggi',
        'treatment' => 'Dirujuk ke puskesmas',
        'note' => null,
        'needs_referral' => true,
        'needs_leave' => true,
    ]));

    $fresh = HealthVisit::query()->findOrFail($visit->id);

    expect($updated->is($fresh))->toBeTrue()
        ->and($fresh->student_id)->toBe($f->s1->id)
        ->and($fresh->recorded_by)->toBe($f->kes->id)
        ->and($fresh->complaint)->toBe('Demam tinggi')
        ->and($fresh->treatment)->toBe('Dirujuk ke puskesmas')
        ->and($fresh->note)->toBeNull()
        ->and($fresh->visited_at->format('H:i'))->toBe('17:00')
        ->and($fresh->needs_referral)->toBeTrue()
        ->and($fresh->needs_leave)->toBeTrue();
});

it('AC-6: logs only the names of changed columns on update, and nothing when unchanged', function () {
    $f = $this->f;
    $visit = kesehatanRecord($f, $f->kes);

    app(UpdateHealthVisit::class)->handle($visit, $f->kes, kesehatanData($f, ['complaint' => 'Pusing berat', 'needs_referral' => true]));

    $updates = kesehatanActivities('updated');

    expect($updates)->toHaveCount(1)
        ->and($updates->first()->causer_id)->toBe($f->kes->id)
        ->and($updates->first()->subject_id)->toBe($visit->id)
        ->and($updates->first()->properties['fields'])->toEqualCanonicalizing(['complaint', 'needs_referral'])
        ->and(json_encode($updates->first()->properties))->not->toContain('Pusing')->not->toContain('Demam');

    $before = kesehatanActivities()->count();

    app(UpdateHealthVisit::class)->handle($visit->fresh(), $f->kes, kesehatanData($f, ['complaint' => 'Pusing berat', 'needs_referral' => true]));

    expect(kesehatanActivities())->toHaveCount($before);
});

it('AC-6: still lets kesantrian edit a visit of a student who has graduated', function () {
    $f = $this->f;
    $visit = kesehatanRecord($f, $f->kes);
    Student::query()->whereKey($f->s1->id)->update(['status' => 'lulus']);
    $this->actingAs($f->kes);

    app(UpdateHealthVisit::class)->handle($visit, $f->kes, kesehatanData($f, ['complaint' => 'Koreksi keluhan']));

    expect(HealthVisit::query()->findOrFail($visit->id)->complaint)->toBe('Koreksi keluhan');
});

it('AC-6: validates an update like a new record and refuses visits outside the actor scope', function () {
    $f = $this->f;
    $visit = kesehatanRecord($f, $f->kes);
    $outside = kesehatanRaw($f->s4);
    $this->actingAs($f->kes);

    tahfidzRejected(fn () => app(UpdateHealthVisit::class)->handle($visit, $f->kes, kesehatanData($f, ['complaint' => ''])), 'complaint');
    tahfidzRejected(fn () => app(UpdateHealthVisit::class)->handle($visit, $f->kes, kesehatanData($f, ['visited_at' => '2026-10-16 08:00'])), 'visited_at');

    $this->actingAs($f->kesSltp);

    expect(fn () => app(UpdateHealthVisit::class)->handle($outside, $f->kesSltp, kesehatanData($f, ['complaint' => 'Diubah diam-diam'])))
        ->toThrow(Throwable::class);

    expect($visit->fresh()->complaint)->toBe('Demam dan sakit kepala sejak semalam')
        ->and($outside->fresh()->complaint)->toBe('Batuk pilek');
});

it('AC-7: lets only super-admin delete a visit and logs it without text', function () {
    $f = $this->f;
    $visit = kesehatanRecord($f, $f->kes);

    foreach ([$f->kes, $f->musyrif, $f->kesSltp] as $actor) {
        $this->actingAs($actor);

        expect(fn () => app(DeleteHealthVisit::class)->handle($visit->fresh(), $actor))->toThrow(Throwable::class);
    }

    expect(HealthVisit::query()->count())->toBe(1);

    $this->actingAs($f->admin);
    app(DeleteHealthVisit::class)->handle($visit->fresh(), $f->admin);

    $activity = kesehatanActivities('deleted')->sole();

    expect(HealthVisit::query()->count())->toBe(0)
        ->and($activity->causer_id)->toBe($f->admin->id)
        ->and(json_encode($activity->properties))->not->toContain('Demam');
});
