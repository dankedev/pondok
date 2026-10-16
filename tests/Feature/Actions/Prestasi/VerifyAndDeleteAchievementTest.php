<?php

use App\Actions\Prestasi\DeleteAchievement;
use App\Actions\Prestasi\VerifyAchievement;
use App\Models\Achievement;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->f = prestasiFixture();
});

it('AC-14: verifies an achievement once', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2]);
    $this->actingAs($f->a);

    $verified = app(VerifyAchievement::class)->handle($achievement, $f->a);

    expect($verified->verified_by)->toBe($f->a->id)
        ->and($achievement->fresh()->verified_by)->toBe($f->a->id);

    prestasiAssertRejected(fn () => app(VerifyAchievement::class)->handle($achievement->fresh(), $f->admin), 'already_verified', 'achievement');

    expect($achievement->fresh()->verified_by)->toBe($f->a->id);
});

it('AC-14: refuses to verify an achievement that is not fully in scope', function () {
    $f = $this->f;
    $team = prestasiCreate($f, [$f->s1, $f->s2]);
    $this->actingAs($f->a2);

    prestasiAssertRejected(fn () => app(VerifyAchievement::class)->handle($team, $f->a2), 'outside_scope', 'achievement');

    expect($team->fresh()->verified_by)->toBeNull();
});

it('AC-14: lets the recorder verify through the action (permissions limit the UI)', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2]);
    $this->actingAs($f->k);

    app(VerifyAchievement::class)->handle($achievement, $f->k);

    expect($achievement->fresh()->verified_by)->toBe($f->k->id);
});

it('AC-15: guards achievement deletion (:key)', function (string $key, string $reason) {
    $f = $this->f;
    $actor = $f->k;

    $achievement = match ($key) {
        'verified' => prestasiCreate($f, [$f->s2], ['verified' => true]),
        'verified with post' => (function () use ($f): Achievement {
            $achievement = prestasiCreate($f, [$f->s2], ['verified' => true, 'public' => true]);
            kontenPost(['achievement_id' => $achievement->id]);

            return $achievement;
        })(),
        'deleted post' => (function () use ($f): Achievement {
            $achievement = prestasiCreate($f, [$f->s2]);
            kontenPost(['achievement_id' => $achievement->id])->delete();

            return $achievement;
        })(),
        'document' => (function () use ($f): Achievement {
            $achievement = prestasiCreate($f, [$f->s2]);
            prestasiDocument($f, $achievement);

            return $achievement;
        })(),
        'outside scope' => (function () use ($f, &$actor): Achievement {
            $actor = $f->k2;

            return prestasiCreate($f, [$f->s1, $f->s2]);
        })(),
    };

    $this->actingAs($actor);

    prestasiAssertRejected(fn () => app(DeleteAchievement::class)->handle($achievement, $actor), $reason, 'achievement');

    expect(Achievement::withoutUnitScope()->whereKey($achievement->id)->exists())->toBeTrue()
        ->and(DB::table('achievement_student')->where('achievement_id', $achievement->id)->exists())->toBeTrue();
})->with([
    'verified' => ['verified', 'verified_locked'],
    'verified with post' => ['verified with post', 'verified_locked'],
    'deleted post' => ['deleted post', 'has_post'],
    'document' => ['document', 'has_documents'],
    'outside scope' => ['outside scope', 'outside_scope'],
]);

it('AC-15: counts documents that a scoped user cannot see', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2]);
    prestasiDocument($f, $achievement);
    $this->actingAs($f->k2);

    prestasiAssertRejected(fn () => app(DeleteAchievement::class)->handle($achievement, $f->k2), 'has_documents', 'achievement');
});

it('AC-15: deletes an unverified achievement', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2, $f->s3]);
    $other = prestasiCreate($f, [$f->s2]);
    $this->actingAs($f->k);

    app(DeleteAchievement::class)->handle($achievement, $f->k);

    expect(Achievement::withoutUnitScope()->whereKey($achievement->id)->exists())->toBeFalse()
        ->and(DB::table('achievement_student')->where('achievement_id', $achievement->id)->count())->toBe(0)
        ->and(Achievement::withoutUnitScope()->whereKey($other->id)->exists())->toBeTrue()
        ->and(DB::table('achievement_student')->where('achievement_id', $other->id)->count())->toBe(1);
});

it('AC-15: deletes an achievement after verification was reset by an edit', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2], ['verified' => true, 'title' => 'Lama']);
    $this->actingAs($f->k);

    prestasiSave(prestasiData($f, ['title' => 'Baru']), $f->k, $achievement);
    app(DeleteAchievement::class)->handle($achievement->fresh(), $f->k);

    expect(Achievement::withoutUnitScope()->whereKey($achievement->id)->exists())->toBeFalse();
});
