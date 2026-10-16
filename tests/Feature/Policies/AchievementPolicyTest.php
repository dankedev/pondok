<?php

use App\Models\Achievement;
use App\Models\User;
use App\Policies\AchievementPolicy;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->f = prestasiFixture();
});

/**
 * Apakah user boleh melakukan `$ability` (model atau kelas) menurut policy.
 */
function prestasiCan(User $user, string $ability, Achievement|string $subject): bool
{
    return Gate::forUser($user)->allows($ability, $subject);
}

it('AC-23: registers the achievement policy', function () {
    expect(Gate::getPolicyFor(Achievement::class))->toBeInstanceOf(AchievementPolicy::class);
});

it('AC-23: authorizes achievement actions per role (:role)', function (string $role, array $allowed) {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2]);
    $user = keuanganUser($role);

    $abilities = ['viewAny', 'view', 'create', 'update', 'delete', 'verify', 'publish'];
    $actual = collect($abilities)->mapWithKeys(fn (string $ability): array => [$ability => prestasiCan(
        $user,
        $ability,
        in_array($ability, ['viewAny', 'create'], true) ? Achievement::class : $achievement,
    )])->filter()->keys()->all();

    expect($actual)->toBe($allowed);
})->with([
    'kesantrian' => ['kesantrian', ['viewAny', 'view', 'create', 'update', 'delete']],
    'admin-sekolah' => ['admin-sekolah', ['viewAny', 'view', 'verify']],
    'admin-konten' => ['admin-konten', []],
    'bendahara' => ['bendahara', []],
    'admin-psb' => ['admin-psb', []],
    'musyrif' => ['musyrif', []],
]);

it('AC-23: lets content admins publish only public verified achievements without a post', function () {
    $f = $this->f;
    $ready = prestasiCreate($f, [$f->s2], ['verified' => true, 'public' => true]);
    $private = prestasiCreate($f, [$f->s2], ['verified' => true]);
    $unverified = prestasiCreate($f, [$f->s2], ['public' => true]);
    $published = prestasiCreate($f, [$f->s2], ['verified' => true, 'public' => true]);
    kontenPost(['achievement_id' => $published->id]);

    expect(prestasiCan($f->c, 'publish', $ready))->toBeTrue()
        ->and(prestasiCan($f->c, 'publish', $private))->toBeFalse()
        ->and(prestasiCan($f->c, 'publish', $unverified))->toBeFalse()
        ->and(prestasiCan($f->c, 'publish', $published))->toBeFalse()
        ->and(prestasiCan($f->c, 'viewAny', Achievement::class))->toBeFalse()
        ->and(prestasiCan($f->c, 'view', $ready))->toBeFalse();
});

it('AC-23: requires create:post to publish', function () {
    $f = $this->f;
    $ready = prestasiCreate($f, [$f->s2], ['verified' => true, 'public' => true]);
    $publisherOnly = dashboardUserWith(['publish:achievement']);
    $publisherWithPosts = dashboardUserWith(['publish:achievement', 'create:post']);

    expect(prestasiCan($publisherOnly, 'publish', $ready))->toBeFalse()
        ->and(prestasiCan($publisherWithPosts, 'publish', $ready))->toBeTrue()
        ->and(prestasiCan($f->c, 'publish', $ready))->toBeTrue();
});

it('AC-23: does not let a user without publish:achievement publish', function () {
    $f = $this->f;
    $ready = prestasiCreate($f, [$f->s2], ['verified' => true, 'public' => true]);

    expect(prestasiCan(dashboardUserWith(['create:post', 'view-any:achievement']), 'publish', $ready))->toBeFalse();
});

it('AC-23: locks delete for verified, published and documented achievements', function () {
    $f = $this->f;
    $plain = prestasiCreate($f, [$f->s2]);
    $verified = prestasiCreate($f, [$f->s2], ['verified' => true]);
    $withPost = prestasiCreate($f, [$f->s2]);
    kontenPost(['achievement_id' => $withPost->id]);
    $withDocument = prestasiCreate($f, [$f->s2]);
    prestasiDocument($f, $withDocument);

    expect(prestasiCan($f->k, 'delete', $plain))->toBeTrue()
        ->and(prestasiCan($f->k, 'delete', $verified))->toBeFalse()
        ->and(prestasiCan($f->k, 'delete', $withPost))->toBeFalse()
        ->and(prestasiCan($f->k, 'delete', $withDocument))->toBeFalse()
        ->and(prestasiCan($f->k, 'update', $verified))->toBeTrue();
});

it('AC-23: locks verify for verified achievements', function () {
    $f = $this->f;
    $plain = prestasiCreate($f, [$f->s2]);
    $verified = prestasiCreate($f, [$f->s2], ['verified' => true]);

    expect(prestasiCan($f->a, 'verify', $plain))->toBeTrue()
        ->and(prestasiCan($f->a, 'verify', $verified))->toBeFalse();
});

it('AC-23: requires full scope to manage team achievements', function () {
    $f = $this->f;
    $team = prestasiCreate($f, [$f->s1, $f->s2]);
    $own = prestasiCreate($f, [$f->s2]);
    $sdAndSltp = keuanganUser('kesantrian', ['SD', 'SLTP']);

    expect(prestasiCan($f->k2, 'view', $own))->toBeTrue()
        ->and(prestasiCan($f->k2, 'update', $own))->toBeTrue()
        ->and(prestasiCan($f->k2, 'view', $team))->toBeTrue()
        ->and(prestasiCan($f->k2, 'update', $team))->toBeFalse()
        ->and(prestasiCan($f->k2, 'delete', $team))->toBeFalse()
        ->and(prestasiCan($f->a2, 'verify', $team))->toBeFalse()
        ->and(prestasiCan($f->a2, 'verify', $own))->toBeTrue()
        ->and(prestasiCan($f->k, 'update', $team))->toBeTrue()
        ->and(prestasiCan($sdAndSltp, 'update', $team))->toBeTrue();
});
