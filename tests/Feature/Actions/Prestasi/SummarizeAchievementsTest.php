<?php

use App\Actions\Prestasi\ListRecentAchievements;
use App\Actions\Prestasi\SummarizeAchievements;
use App\Actions\Prestasi\SummarizeStudentAchievements;
use App\Models\Achievement;

beforeEach(function () {
    $this->f = prestasiFixture();
});

it('AC-19: summarizes achievements per year', function () {
    $f = $this->f;
    prestasiRecapData($f);
    $this->actingAs($f->a);

    $summary = app(SummarizeAchievements::class)->handle($f->y2->id);

    expect($summary['total'])->toBe(3)
        ->and($summary['participations'])->toBe(4)
        ->and($summary['by_category']['tahfidz'])->toBe(2)
        ->and($summary['by_category']['olahraga'])->toBe(1)
        ->and($summary['by_category']['akademik'])->toBe(0)
        ->and($summary['by_category']['seni'])->toBe(0)
        ->and($summary['by_level']['provinsi'])->toBe(1)
        ->and($summary['by_level']['nasional'])->toBe(0)
        ->and($summary['matrix']['tahfidz']['pondok'])->toBe(1)
        ->and($summary['matrix']['tahfidz']['kabupaten'])->toBe(1)
        ->and($summary['matrix']['seni']['nasional'])->toBe(0)
        ->and(array_keys($summary['by_category']))->toBe(Achievement::CATEGORIES)
        ->and(array_keys($summary['by_level']))->toBe(Achievement::LEVELS)
        ->and(array_keys($summary['matrix']))->toBe(Achievement::CATEGORIES)
        ->and(array_keys($summary['matrix']['tahfidz']))->toBe(Achievement::LEVELS)
        ->and(app(SummarizeAchievements::class)->handle(null)['total'])->toBe(4);
});

it('AC-19: returns zeros for a year without achievements', function () {
    $f = $this->f;
    $this->actingAs($f->a);

    $summary = app(SummarizeAchievements::class)->handle($f->y1->id);

    expect($summary['total'])->toBe(0)->and($summary['participations'])->toBe(0)
        ->and(array_sum($summary['by_category']))->toBe(0)
        ->and(array_sum($summary['by_level']))->toBe(0)
        ->and(array_keys($summary['matrix']))->toBe(Achievement::CATEGORIES);
});

it('AC-20: counts unverified achievements only on request', function () {
    $f = $this->f;
    prestasiRecapData($f);
    $this->actingAs($f->a);

    $all = app(SummarizeAchievements::class)->handle($f->y2->id, verifiedOnly: false);

    expect($all['total'])->toBe(4)
        ->and($all['participations'])->toBe(5)
        ->and($all['by_category']['akademik'])->toBe(1)
        ->and($all['by_level']['nasional'])->toBe(1)
        ->and(app(SummarizeAchievements::class)->handle($f->y2->id, verifiedOnly: true)['total'])->toBe(3);
});

it('AC-20: counts participations per unit and scope', function () {
    $f = $this->f;
    prestasiRecapData($f);
    $this->actingAs($f->a);
    $summarize = fn (?int $unit, bool $verifiedOnly = true): array => app(SummarizeAchievements::class)->handle($f->y2->id, $unit, $verifiedOnly);

    expect($summarize($f->sltp->id)['total'])->toBe(2)
        ->and($summarize($f->sltp->id)['participations'])->toBe(3)
        ->and($summarize($f->sd->id)['total'])->toBe(1)
        ->and($summarize($f->sd->id)['participations'])->toBe(1);

    prestasiCreate($f, [$f->s1, $f->s2], ['verified' => true]);

    expect($summarize($f->sltp->id)['total'])->toBe(3)
        ->and($summarize($f->sltp->id)['participations'])->toBe(4)
        ->and($summarize($f->sd->id)['total'])->toBe(2)
        ->and($summarize($f->sd->id)['participations'])->toBe(2)
        ->and($summarize(null)['participations'])->toBe(6);
});

it('AC-20: counts only visible achievements and students for a scoped user', function () {
    $f = $this->f;
    prestasiRecapData($f);
    $this->actingAs($f->a2);

    $summary = app(SummarizeAchievements::class)->handle($f->y2->id);

    expect($summary['total'])->toBe(2)->and($summary['participations'])->toBe(3);

    prestasiCreate($f, [$f->s1, $f->s2], ['verified' => true]);

    $mixed = app(SummarizeAchievements::class)->handle($f->y2->id);

    expect($mixed['total'])->toBe(3)->and($mixed['participations'])->toBe(4);
});

it('AC-20: runs at most three queries', function () {
    $f = $this->f;
    prestasiRecapData($f);
    $this->actingAs($f->a);
    $f->a->scopedUnitIds();

    expect(prestasiQueryCount(fn () => app(SummarizeAchievements::class)->handle($f->y2->id, $f->sltp->id)))->toBeLessThanOrEqual(3);
});

it('AC-21: summarizes a student', function () {
    $f = $this->f;
    prestasiRecapData($f);
    $this->actingAs($f->a);
    $summarize = app(SummarizeStudentAchievements::class);

    $s2 = $summarize->handle($f->s2);

    expect($s2['total'])->toBe(3)
        ->and($s2['unverified'])->toBe(0)
        ->and($s2['highest_level'])->toBe('nasional')
        ->and($s2['by_level']['kabupaten'])->toBe(1)
        ->and($s2['by_category']['tahfidz'])->toBe(2);

    $s2Year = $summarize->handle($f->s2, $f->y2->id);

    expect($s2Year['total'])->toBe(2)->and($s2Year['highest_level'])->toBe('kabupaten');
});

it('AC-21: separates unverified achievements from the official count', function () {
    $f = $this->f;
    prestasiRecapData($f);
    $this->actingAs($f->a);
    $summarize = app(SummarizeStudentAchievements::class);

    $s3 = $summarize->handle($f->s3);

    expect($s3['total'])->toBe(1)->and($s3['unverified'])->toBe(1)->and($s3['highest_level'])->toBe('pondok');

    $s3All = $summarize->handle($f->s3, null, false);

    expect($s3All['total'])->toBe(2)->and($s3All['unverified'])->toBe(1)->and($s3All['highest_level'])->toBe('nasional');
});

it('AC-21: returns zero and no level for a student without achievements', function () {
    $f = $this->f;
    prestasiRecapData($f);
    $this->actingAs($f->a);

    $none = app(SummarizeStudentAchievements::class)->handle(prestasiStudent($f));

    expect($none['total'])->toBe(0)->and($none['unverified'])->toBe(0)->and($none['highest_level'])->toBeNull();
});

it('AC-21: ranks levels by the order of the constant and not alphabetically', function () {
    $f = $this->f;
    prestasiCreate($f, [$f->s2], ['verified' => true, 'level' => 'internasional']);
    prestasiCreate($f, [$f->s2], ['verified' => true, 'level' => 'provinsi']);
    prestasiCreate($f, [$f->s2], ['verified' => true, 'level' => 'kecamatan']);

    expect(app(SummarizeStudentAchievements::class)->handle($f->s2)['highest_level'])->toBe('internasional');
});

it('AC-38: lists recent achievements newest first and only verified by default', function () {
    $f = $this->f;
    $old = prestasiCreate($f, [$f->s2], ['verified' => true, 'achieved_on' => '2026-07-01']);
    $new = prestasiCreate($f, [$f->s2], ['verified' => true, 'achieved_on' => '2026-09-01']);
    $same = prestasiCreate($f, [$f->s3], ['verified' => true, 'achieved_on' => '2026-09-01']);
    $unverified = prestasiCreate($f, [$f->s2], ['achieved_on' => '2026-10-01']);
    $this->actingAs($f->a);

    $list = app(ListRecentAchievements::class)->handle(2);

    expect($list->pluck('id')->all())->toBe([$same->id, $new->id])
        ->and(app(ListRecentAchievements::class)->handle(10)->pluck('id')->all())->toBe([$same->id, $new->id, $old->id])
        ->and(app(ListRecentAchievements::class)->handle(10, false)->pluck('id')->all())->toBe([$unverified->id, $same->id, $new->id, $old->id]);
});

it('AC-38: lists recent achievements in at most three queries', function () {
    $f = $this->f;

    foreach (range(1, 5) as $i) {
        prestasiCreate($f, [$f->s2, $f->s3], ['verified' => true, 'achieved_on' => '2026-08-0'.$i]);
    }

    $this->actingAs($f->a);
    $f->a->scopedUnitIds();

    $count = prestasiQueryCount(function () {
        app(ListRecentAchievements::class)->handle(5)->each(fn (Achievement $achievement) => $achievement->students->pluck('name') && $achievement->academicYear?->name);
    });

    expect($count)->toBeLessThanOrEqual(3);
});

it('AC-38: hides achievements outside the unit scope from recent achievements', function () {
    $f = $this->f;
    prestasiCreate($f, [$f->s1], ['verified' => true]);
    $own = prestasiCreate($f, [$f->s2], ['verified' => true]);
    $this->actingAs($f->a2);

    expect(app(ListRecentAchievements::class)->handle()->pluck('id')->all())->toBe([$own->id]);
});
