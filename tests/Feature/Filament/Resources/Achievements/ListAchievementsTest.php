<?php

use App\Filament\Resources\Achievements\Pages\ListAchievements;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Achievement;
use App\Models\Post;
use App\Models\Setting;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = prestasiFixture();
});

it('AC-32: lists achievements of the active year with verification badges', function () {
    $f = $this->f;
    $p1 = prestasiCreate($f, [$f->s2], ['title' => 'Belum diverifikasi 1']);
    $p2 = prestasiCreate($f, [$f->s2], ['title' => 'Sudah diverifikasi 2', 'verified' => true]);
    $p4 = prestasiCreate($f, [$f->s2], ['title' => 'Tahun lalu 4', 'academic_year_id' => $f->y1->id, 'achieved_on' => '2026-03-10', 'verified' => true]);
    $this->actingAs($f->a);

    $component = Livewire::test(ListAchievements::class)
        ->assertCanSeeTableRecords([$p1, $p2])
        ->assertCanNotSeeTableRecords([$p4]);
    $text = tagihanPlainText($component);

    expect($text)->toContain('Belum diverifikasi')->toContain('Terverifikasi')
        ->toContain('17 Agu 2026')->toContain('Budi Dua')->toContain('MTQ Tingkat Kabupaten 2026')
        ->and(__('pondok.achievement.verification_statuses.terverifikasi'))->toBe('Terverifikasi')
        ->and(__('pondok.achievement.verification_statuses.belum'))->toBe('Belum diverifikasi');
});

it('AC-32: sorts by achievement date, newest first', function () {
    $f = $this->f;
    $old = prestasiCreate($f, [$f->s2], ['achieved_on' => '2026-07-10']);
    $new = prestasiCreate($f, [$f->s2], ['achieved_on' => '2026-09-10']);
    $mid = prestasiCreate($f, [$f->s2], ['achieved_on' => '2026-08-10']);
    $this->actingAs($f->a);

    Livewire::test(ListAchievements::class)->assertCanSeeTableRecords([$new, $mid, $old], inOrder: true);
});

it('AC-32: verifies an achievement from the table', function () {
    $f = $this->f;
    $pending = prestasiCreate($f, [$f->s2]);
    $done = prestasiCreate($f, [$f->s2], ['verified' => true]);
    $this->actingAs($f->a);

    Livewire::test(ListAchievements::class)
        ->assertActionVisible(TestAction::make('verify')->table($pending))
        ->assertActionHidden(TestAction::make('verify')->table($done))
        ->callAction(TestAction::make('verify')->table($pending))
        ->assertNotified();

    expect($pending->fresh()->verified_by)->toBe($f->a->id);

    Livewire::test(ListAchievements::class)->assertActionHidden(TestAction::make('verify')->table($pending->fresh()));
});

it('AC-32: hides edit and delete from the school admin and offers no bulk action', function () {
    $f = $this->f;
    $pending = prestasiCreate($f, [$f->s2]);
    $this->actingAs($f->a);

    $component = Livewire::test(ListAchievements::class)
        ->assertActionHidden(TestAction::make('edit')->table($pending))
        ->assertActionHidden(TestAction::make('delete')->table($pending));

    expect($component->instance()->getTable()->getFlatBulkActions())->toBe([]);
});

it('AC-32: lets kesantrian delete an unverified achievement without post or documents', function () {
    $f = $this->f;
    $plain = prestasiCreate($f, [$f->s2, $f->s3]);
    $verified = prestasiCreate($f, [$f->s2], ['verified' => true]);
    $withPost = prestasiCreate($f, [$f->s2]);
    kontenPost(['achievement_id' => $withPost->id]);
    $withDocument = prestasiCreate($f, [$f->s2]);
    prestasiDocument($f, $withDocument);
    $this->actingAs($f->k);

    Livewire::test(ListAchievements::class)
        ->assertActionVisible(TestAction::make('edit')->table($plain))
        ->assertActionVisible(TestAction::make('delete')->table($plain))
        ->assertActionHidden(TestAction::make('delete')->table($verified))
        ->assertActionHidden(TestAction::make('delete')->table($withPost))
        ->assertActionHidden(TestAction::make('delete')->table($withDocument))
        ->assertActionHidden(TestAction::make('verify')->table($plain))
        ->callAction(TestAction::make('delete')->table($plain))
        ->assertNotified();

    expect(Achievement::withoutUnitScope()->whereKey($plain->id)->exists())->toBeFalse()
        ->and(DB::table('achievement_student')->where('achievement_id', $plain->id)->count())->toBe(0)
        ->and(Achievement::withoutUnitScope()->whereKey($verified->id)->exists())->toBeTrue();
});

it('AC-32: filters by student', function () {
    $f = $this->f;
    $forS3 = prestasiCreate($f, [$f->s2, $f->s3]);
    $forS2 = prestasiCreate($f, [$f->s2]);
    $this->actingAs($f->a);

    Livewire::test(ListAchievements::class)
        ->assertCanSeeTableRecords([$forS3, $forS2])
        ->filterTable('student', $f->s3->id)
        ->assertCanSeeTableRecords([$forS3])
        ->assertCanNotSeeTableRecords([$forS2]);
});

it('AC-32: shows at most three participants and counts the rest', function () {
    $f = $this->f;
    $team = [$f->s2, $f->s3, prestasiStudent($f, 'SLTP', ['name' => 'Dedi Empat']), prestasiStudent($f, 'SLTP', ['name' => 'Eko Lima']), prestasiStudent($f, 'SLTP', ['name' => 'Fajar Enam'])];
    prestasiCreate($f, $team);
    $this->actingAs($f->a);

    $text = tagihanPlainText(Livewire::test(ListAchievements::class));

    expect($text)->toContain(__('pondok.achievement.others', ['count' => 2]));
});

it('AC-32: hides participants and achievements of other units from a scoped user', function () {
    $f = $this->f;
    $own = prestasiCreate($f, [$f->s2]);
    $other = prestasiCreate($f, [$f->s1]);
    $team = prestasiCreate($f, [$f->s1, $f->s2]);
    $this->actingAs($f->a2);

    $component = Livewire::test(ListAchievements::class)
        ->assertCanSeeTableRecords([$own, $team])
        ->assertCanNotSeeTableRecords([$other]);

    expect(tagihanPlainText($component))->not->toContain('Ahmad Satu')->toContain('Budi Dua');
});

it('AC-32: loads the table without extra queries per row', function () {
    $f = $this->f;
    $this->actingAs($f->a);
    prestasiCreate($f, [$f->s2, $f->s3]);
    prestasiCreate($f, [$f->s2]);
    $f->a->scopedUnitIds();

    $few = prestasiQueryCount(fn () => Livewire::test(ListAchievements::class)->assertSuccessful());

    foreach (range(1, 10) as $i) {
        $achievement = prestasiCreate($f, [$f->s2, $f->s3], ['verified' => $i % 2 === 0]);

        if ($i % 3 === 0) {
            kontenPost(['achievement_id' => $achievement->id]);
        }
    }

    $many = prestasiQueryCount(fn () => Livewire::test(ListAchievements::class)->assertSuccessful());

    expect($many - $few)->toBeLessThanOrEqual(2);
});

it('AC-32: shows an empty state with a create action', function () {
    $this->actingAs($this->f->k);

    $text = tagihanPlainText(Livewire::test(ListAchievements::class));

    expect($text)->toContain('Belum ada prestasi untuk tahun ajaran ini.')->toContain(__('pondok.achievement.create_action'));
});

it('AC-33: publishes an achievement as a draft post', function () {
    $f = $this->f;
    $ready = prestasiCreate($f, [$f->s2], ['verified' => true, 'public' => true, 'title' => 'Juara 1 MTQ Tahfidz 10 Juz']);
    $this->actingAs($f->admin);

    Livewire::test(ListAchievements::class)
        ->assertActionVisible(TestAction::make('publish')->table($ready))
        ->callAction(TestAction::make('publish')->table($ready))
        ->assertNotified()
        ->assertRedirect();

    $post = Post::query()->sole();

    expect($post->achievement_id)->toBe($ready->id)->and($post->status)->toBe('draft')->and($post->type)->toBe('prestasi')
        ->and($post->author_id)->toBe($f->admin->id);

    Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])->assertSuccessful();

    expect(PostResource::getUrl('edit', ['record' => $post]))->toContain("/posts/{$post->id}/edit");
});

it('AC-33: shows the publish action only for publishable achievements', function () {
    $f = $this->f;
    $private = prestasiCreate($f, [$f->s2], ['verified' => true]);
    $unverified = prestasiCreate($f, [$f->s2], ['public' => true]);
    $published = prestasiCreate($f, [$f->s2], ['verified' => true, 'public' => true]);
    kontenPost(['achievement_id' => $published->id]);
    $this->actingAs($f->admin);

    Livewire::test(ListAchievements::class)
        ->assertActionHidden(TestAction::make('publish')->table($private))
        ->assertActionHidden(TestAction::make('publish')->table($unverified))
        ->assertActionHidden(TestAction::make('publish')->table($published));
});

it('AC-33: hides the publish action without the konten module or the publish permission', function () {
    $f = $this->f;
    $ready = prestasiCreate($f, [$f->s2], ['verified' => true, 'public' => true]);

    $this->actingAs($f->k);
    Livewire::test(ListAchievements::class)->assertActionHidden(TestAction::make('publish')->table($ready));

    Setting::set('modules.konten.enabled', false);
    $this->actingAs($f->admin);
    Livewire::test(ListAchievements::class)->assertActionHidden(TestAction::make('publish')->table($ready));

    expect(Post::query()->count())->toBe(0);
});
