<?php

use App\Filament\Resources\Videos\Pages\ManageVideos;
use App\Models\Video;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = kontenBoot();
    $this->actingAs(kontenUser('admin-konten'));
});

it('AC-29: creates a video from a youtube url', function () {
    Livewire::test(ManageVideos::class)
        ->assertActionVisible('create')
        ->callAction('create', ['title' => 'Profil Pondok', 'youtube' => 'https://youtu.be/dQw4w9WgXcQ', 'is_featured' => true, 'sort_order' => 2])
        ->assertHasNoFormErrors();

    expect(Video::query()->sole())->title->toBe('Profil Pondok')->youtube_id->toBe('dQw4w9WgXcQ')->is_featured->toBeTrue()->sort_order->toBe(2);
});

it('AC-29: shows an invalid youtube link as an error on the youtube field', function () {
    $component = Livewire::test(ManageVideos::class)
        ->callAction('create', ['title' => 'Salah', 'youtube' => 'https://vimeo.com/1'])
        ->assertHasFormErrors(['youtube']);

    expect(kontenSeeMessage($component, 'youtube_invalid'))->toBeTrue()->and(Video::query()->count())->toBe(0);
});

it('AC-29: fills the youtube field with the watch url when editing', function () {
    $video = kontenVideo(['youtube_id' => 'dQw4w9WgXcQ']);

    Livewire::test(ManageVideos::class)
        ->mountAction(TestAction::make('edit')->table($video))
        ->assertActionDataSet(['youtube' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
});

it('AC-29: edits a video', function () {
    $video = kontenVideo(['title' => 'Lama']);

    Livewire::test(ManageVideos::class)
        ->callAction(TestAction::make('edit')->table($video), ['title' => 'Baru', 'youtube' => 'aaaaaaaaaaa', 'sort_order' => 3])
        ->assertHasNoFormErrors();

    expect($video->fresh())->title->toBe('Baru')->youtube_id->toBe('aaaaaaaaaaa')->sort_order->toBe(3);
});

it('AC-29: deletes a video through the delete action', function () {
    $video = kontenVideo();

    Livewire::test(ManageVideos::class)
        ->assertActionVisible(TestAction::make('delete')->table($video))
        ->callAction(TestAction::make('delete')->table($video));

    expect(Video::query()->count())->toBe(0);
});

it('AC-30: lists videos in the public order', function () {
    $normal = kontenVideo(['title' => 'Biasa', 'sort_order' => 0]);
    $second = kontenVideo(['title' => 'Unggulan 2', 'is_featured' => true, 'sort_order' => 2]);
    $first = kontenVideo(['title' => 'Unggulan 1', 'is_featured' => true, 'sort_order' => 1]);

    Livewire::test(ManageVideos::class)->assertCanSeeTableRecords([$first, $second, $normal], inOrder: true);
});

it('AC-29: forbids the video page to other roles', function () {
    $this->actingAs(kontenUser('bendahara'));

    $this->get('/admin/videos')->assertForbidden();
});
