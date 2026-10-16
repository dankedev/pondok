<?php

use App\Models\Achievement;
use App\Models\Post;

beforeEach(function () {
    $this->f = prestasiFixture();
});

it('AC-5: relates achievements, students and posts', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [[$f->s2, 'Juara 1', 'Ketua tim'], [$f->s3, 'Harapan 2']]);
    $f->s3->delete();

    expect($f->s2->achievements)->toHaveCount(1)
        ->and($f->s2->achievements->first()->pivot->rank)->toBe('Juara 1')
        ->and($f->s2->achievements->first()->pivot->role)->toBe('Ketua tim');

    $students = Achievement::withoutUnitScope()->findOrFail($achievement->id)->students;

    expect($students->pluck('id')->sort()->values()->all())->toBe([$f->s2->id, $f->s3->id])
        ->and($students->firstWhere('id', $f->s3->id)->trashed())->toBeTrue()
        ->and($students->firstWhere('id', $f->s3->id)->pivot->rank)->toBe('Harapan 2');

    $post = kontenPost(['achievement_id' => $achievement->id]);

    expect($post->achievement->is($achievement))->toBeTrue()
        ->and($achievement->post->is($post))->toBeTrue();

    $post->delete();

    expect(Achievement::withoutUnitScope()->findOrFail($achievement->id)->post?->id)->toBe($post->id)
        ->and(Post::query()->count())->toBe(0);
});

it('AC-5: names the morph alias achievement and relates users and years', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2], ['verified' => true]);

    expect((new Achievement)->getMorphClass())->toBe('achievement')
        ->and($achievement->academicYear->is($f->y2))->toBeTrue()
        ->and($achievement->recorder->is($f->k))->toBeTrue()
        ->and($achievement->verifier->is($f->a))->toBeTrue()
        ->and($f->k->recordedAchievements)->toHaveCount(1)
        ->and($f->a->verifiedAchievements)->toHaveCount(1)
        ->and($f->y2->achievements)->toHaveCount(1)
        ->and($achievement->is_verified)->toBeTrue()
        ->and($achievement->verification_status)->toBe('terverifikasi')
        ->and(prestasiCreate($f, [$f->s2])->verification_status)->toBe('belum');
});

it('AC-5: exposes the constants from the plan', function () {
    expect(Achievement::CATEGORIES)->toBe(['tahfidz', 'akademik', 'keagamaan', 'olahraga', 'seni', 'organisasi', 'lainnya'])
        ->and(Achievement::LEVELS)->toBe(['pondok', 'kecamatan', 'kabupaten', 'provinsi', 'nasional', 'internasional'])
        ->and(Achievement::KINDS)->toBe(['individu', 'beregu'])
        ->and(Achievement::MAX_PARTICIPANTS)->toBe(50)
        ->and(Achievement::VERIFICATION_COLORS)->toBe(['terverifikasi' => 'success', 'belum' => 'warning']);
});

it('AC-5: knows when an achievement can be published', function () {
    $f = $this->f;
    $ready = prestasiCreate($f, [$f->s2], ['verified' => true, 'public' => true]);
    $private = prestasiCreate($f, [$f->s2], ['verified' => true]);
    $unverified = prestasiCreate($f, [$f->s2], ['public' => true]);
    $published = prestasiCreate($f, [$f->s2], ['verified' => true, 'public' => true]);
    kontenPost(['achievement_id' => $published->id])->delete();

    expect($ready->isPublishable())->toBeTrue()
        ->and($private->isPublishable())->toBeFalse()
        ->and($unverified->isPublishable())->toBeFalse()
        ->and($published->isPublishable())->toBeFalse();
});

it('AC-22: scopes achievements by participant units', function () {
    $f = $this->f;
    $p1 = prestasiCreate($f, [$f->s2]);
    $p3 = prestasiCreate($f, [$f->s1]);
    $p5 = prestasiCreate($f, [$f->s1, $f->s2]);
    $archived = prestasiStudent($f, 'SD');
    $p6 = prestasiCreate($f, [$archived]);
    $archived->delete();
    $sdAdmin = keuanganUser('admin-sekolah', ['SD']);

    $ids = fn () => Achievement::query()->pluck('id')->sort()->values()->all();

    $this->actingAs($f->a2);
    expect($ids())->toBe(collect([$p1, $p5])->pluck('id')->sort()->values()->all())
        ->and($p5->isFullyVisibleTo($f->a2))->toBeFalse()
        ->and($p1->isFullyVisibleTo($f->a2))->toBeTrue()
        ->and(Achievement::query()->findOrFail($p5->id)->students->pluck('id')->all())->toBe([$f->s2->id]);

    $this->actingAs($sdAdmin);
    expect($ids())->toBe(collect([$p3, $p5, $p6])->pluck('id')->sort()->values()->all());

    $this->actingAs($f->a);
    expect($ids())->toBe(collect([$p1, $p3, $p5, $p6])->pluck('id')->sort()->values()->all())
        ->and($p5->isFullyVisibleTo($f->a))->toBeTrue();
});

it('AC-22: hides an achievement without participants from scoped users only', function () {
    $f = $this->f;
    $empty = prestasiCreate($f, []);

    $this->actingAs($f->a2);
    expect(Achievement::query()->find($empty->id))->toBeNull();

    $this->actingAs($f->a);
    expect(Achievement::query()->find($empty->id))->not->toBeNull();
});

it('AC-22: counts a fully archived team as outside scope only for other units', function () {
    $f = $this->f;
    $own = prestasiStudent($f, 'SLTP');
    $achievement = prestasiCreate($f, [$own, $f->s1]);
    $own->delete();

    expect($achievement->isFullyVisibleTo($f->a2))->toBeFalse()
        ->and($achievement->isFullyVisibleTo(keuanganUser('admin-sekolah', ['SD', 'SLTP'])))->toBeTrue();
});

it('AC-22: filters by academic year and unit through scopes', function () {
    $f = $this->f;
    $y1 = prestasiCreate($f, [$f->s2], ['academic_year_id' => $f->y1->id]);
    $y2 = prestasiCreate($f, [$f->s1]);

    expect(Achievement::query()->forAcademicYear($f->y1->id)->pluck('id')->all())->toBe([$y1->id])
        ->and(Achievement::query()->forUnit($f->sd->id)->pluck('id')->all())->toBe([$y2->id])
        ->and(Achievement::query()->forUnit($f->sltp->id)->pluck('id')->all())->toBe([$y1->id]);
});
