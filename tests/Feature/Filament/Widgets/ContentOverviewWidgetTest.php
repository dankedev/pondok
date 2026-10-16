<?php

use App\Actions\Konten\SummarizeContent;
use App\Filament\Widgets\ContentOverviewWidget;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->f = kontenBoot();
});

/**
 * Dua post terbit, satu terjadwal, tiga draft, satu terbit yang dihapus; dua album, tiga foto, empat video.
 */
function kontenWidgetData(): void
{
    kontenPost(['title' => 'T1', 'status' => 'terbit', 'published_at' => '2026-10-14 08:00:00']);
    kontenPost(['title' => 'T2', 'status' => 'terbit', 'published_at' => '2026-10-15 10:00:00']);
    kontenPost(['title' => 'J1', 'status' => 'terbit', 'published_at' => '2026-10-20 08:00:00']);
    foreach (['D1', 'D2', 'D3'] as $title) {
        kontenPost(['title' => $title, 'status' => 'draft']);
    }
    kontenPost(['title' => 'X1', 'status' => 'terbit', 'published_at' => '2026-10-01 08:00:00'])->delete();

    $album = kontenAlbum(['title' => 'A1']);
    kontenAlbum(['title' => 'A2']);
    foreach (range(1, 3) as $i) {
        kontenPhoto($album);
    }
    foreach (range(1, 4) as $i) {
        kontenVideo(['title' => "V{$i}"]);
    }
}

it('F018 AC-6: has a metric source and reads the content tables', function () {
    expect(ContentOverviewWidget::hasMetricSource())->toBeTrue()
        ->and(ContentOverviewWidget::getRequiredTables())->toContain('posts', 'albums', 'photos', 'videos')
        ->and(ContentOverviewWidget::getDashboardModule())->toBe('konten');
});

it('F018 AC-6: summarizes published, scheduled and draft posts, albums, photos and videos', function () {
    kontenWidgetData();

    expect(app(SummarizeContent::class)->handle())->toBe([
        'posts_published' => 2,
        'posts_scheduled' => 1,
        'posts_draft' => 3,
        'albums' => 2,
        'photos' => 3,
        'videos' => 4,
    ]);
});

it('F018 AC-6: shows the numbers to admin-konten without the unavailable message', function () {
    kontenWidgetData();

    $text = dashboardWidgetText(kontenUser('admin-konten'), 'content');

    foreach (['posts_published' => 2, 'posts_scheduled' => 1, 'posts_draft' => 3, 'albums' => 2, 'photos' => 3, 'videos' => 4] as $key => $count) {
        $label = __("pondok.dashboard.content.{$key}");

        expect($label)->not->toBe("pondok.dashboard.content.{$key}")
            ->and(seleksiStatValue($text, $label))->toBe($count, "Stat {$key}");
    }

    expect($text)->not->toContain('Modul belum tersedia.');
});

it('F018 AC-6: shows module unavailable without numbers when the konten module is off', function () {
    kontenWidgetData();
    Setting::set('modules.konten.enabled', false);
    $this->mock(SummarizeContent::class, fn ($mock) => $mock->shouldNotReceive('handle'));

    $text = dashboardWidgetText(kontenUser('admin-konten'), 'content');

    expect($text)->toContain('Modul belum tersedia.')->not->toContain(__('pondok.dashboard.content.posts_published'))
        ->and(ContentOverviewWidget::isModuleAvailable())->toBeFalse();
});

it('F018 AC-6: shows module unavailable when a content table is missing', function () {
    Schema::drop('videos');

    expect(ContentOverviewWidget::isModuleAvailable())->toBeFalse()
        ->and(dashboardWidgetText(kontenUser('admin-konten'), 'content'))->toContain('Modul belum tersedia.');
});

it('F018 AC-6: links to the post list only for users who may open it', function () {
    $url = route('filament.admin.resources.posts.index');
    $link = __('pondok.dashboard.content.list_link');

    expect($link)->not->toBe('pondok.dashboard.content.list_link');

    $with = dashboardGet(kontenUser('admin-konten'));

    expect(bayarHtmlText($with->getContent()))->toContain($link);
    $with->assertSee($url, false);

    $without = dashboardGet(dashboardUserWith(['view-dashboard', 'view-content-metrics']));

    $without->assertSeeLivewire(ContentOverviewWidget::class);
    expect(bayarHtmlText($without->getContent()))->not->toContain($link);
    $without->assertDontSee($url, false);
});

it('F018 AC-6: hides the widget from roles without the content metrics permission', function () {
    dashboardGet(kontenUser('bendahara'))->assertDontSeeLivewire(ContentOverviewWidget::class);
    dashboardGet(kontenUser('super-admin'))->assertSeeLivewire(ContentOverviewWidget::class);
});

it('F018 AC-6: keeps the query count independent of the number of posts', function () {
    kontenPost(['title' => 'Satu', 'status' => 'terbit', 'published_at' => '2026-10-14 08:00:00']);
    $user = kontenUser('admin-konten');
    dashboardWidgetText($user, 'content');

    $small = dokumenQueryCount(fn () => dashboardWidgetText($user, 'content'));

    foreach (range(1, 10) as $i) {
        kontenPost(['title' => "Lagi {$i}", 'status' => 'draft']);
        kontenVideo(['title' => "Video {$i}"]);
    }

    expect(dokumenQueryCount(fn () => dashboardWidgetText($user, 'content')))->toBe($small);
});
