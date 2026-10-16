<?php

use App\Actions\Prestasi\PublishAchievementAsPost;
use App\Filament\Resources\Achievements\Pages\ListAchievements;
use App\Models\Document;
use App\Models\Post;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = prestasiFixture();
});

it('VERIFY: hides verify, delete, and edit from a unit-scoped super-admin on a cross-unit team achievement', function () {
    $f = $this->f;
    $team = prestasiCreate($f, [$f->s1, $f->s2]);
    $admin = keuanganUser('super-admin', ['SLTP']);
    $this->actingAs($admin);

    Livewire::test(ListAchievements::class)
        ->assertCanSeeTableRecords([$team])
        ->assertActionHidden(TestAction::make('verify')->table($team))
        ->assertActionHidden(TestAction::make('delete')->table($team))
        ->assertActionHidden(TestAction::make('edit')->table($team));
});

it('VERIFY: does not run extra policy queries per row for a unit-scoped user', function (string $role, string $unit) {
    $f = $this->f;
    $user = keuanganUser($role, [$unit]);
    $this->actingAs($user);
    prestasiCreate($f, [$f->s2, $f->s3]);
    prestasiCreate($f, [$f->s2]);
    $user->scopedUnitIds();

    $few = prestasiQueryCount(fn () => Livewire::test(ListAchievements::class)->assertSuccessful());

    foreach (range(1, 10) as $i) {
        prestasiCreate($f, [$f->s2, $f->s3]);
    }

    $many = prestasiQueryCount(fn () => Livewire::test(ListAchievements::class)->assertSuccessful());

    expect($many - $few)->toBeLessThanOrEqual(2);
})->with([['admin-sekolah', 'SLTP'], ['kesantrian', 'SLTP']]);

it('VERIFY: escapes script tags from the title in the draft post body and excerpt', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2], ['verified' => true, 'public' => true, 'title' => '<script>alert(1)</script>Juara']);
    $this->actingAs($f->admin);

    $post = app(PublishAchievementAsPost::class)->handle($achievement, $f->admin);

    expect($post->body)->not->toContain('<script')
        ->and((string) $post->excerpt)->not->toContain('<script')
        ->and(Post::query()->count())->toBe(1);
});

it('VERIFY: does not leak NIS or other personal data into the public post body', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2, $f->s3], ['verified' => true, 'public' => true]);
    $this->actingAs($f->admin);

    $post = app(PublishAchievementAsPost::class)->handle($achievement, $f->admin);

    expect($post->body)->toContain('Budi Dua')->not->toContain('2002')->not->toContain($f->s2->birth_date->format('Y'));
});

it('VERIFY: gives a role with document permissions but no achievement access no view of achievement documents', function () {
    $f = $this->f;
    $document = prestasiDocument($f, prestasiCreate($f, [$f->s2]));
    $user = dashboardUserWith(['view-any:document', 'view:document']);
    $this->actingAs($user);

    expect(Gate::forUser($user)->allows('view', $document))->toBeFalse()
        ->and(Gate::forUser($user)->allows('download', $document))->toBeFalse()
        ->and(Document::query()->whereKey($document->id)->exists())->toBeTrue();
});
