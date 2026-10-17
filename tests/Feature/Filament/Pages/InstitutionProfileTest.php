<?php

use App\Filament\Clusters\InstitutionProfile\Pages\Contacts;
use App\Filament\Clusters\InstitutionProfile\Pages\Footer;
use App\Filament\Clusters\InstitutionProfile\Pages\Home;
use App\Filament\Clusters\InstitutionProfile\Pages\Identity;
use App\Models\Setting;
use Filament\Forms\Components\Select;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

const PROFILE_PAGES = [
    'identity' => Identity::class,
    'contacts' => Contacts::class,
    'home' => Home::class,
    'footer' => Footer::class,
];

beforeEach(function () {
    $this->f = kontenBoot();
    Setting::set('school.name', 'Pondok Lama');
});

it('AC-32: lets school and content admins open the four profile pages', function (string $role) {
    $user = kontenUser($role);

    foreach (array_keys(PROFILE_PAGES) as $slug) {
        $this->actingAs($user)->get("/admin/institution-profile/{$slug}")->assertOk();
    }

    expect(__('pondok.school_profile.title'))->toBe('Profil lembaga')
        ->and(kontenNavigationText($user))->toContain('Profil lembaga');

    Livewire::test(Identity::class)->assertFormSet(['name' => 'Pondok Lama']);
})->with(['super-admin', 'admin-sekolah', 'admin-konten']);

it('AC-32: forbids the four profile pages to the other roles and hides the menu item', function (string $role) {
    $user = kontenUser($role);

    foreach (array_keys(PROFILE_PAGES) as $slug) {
        $this->actingAs($user)->get("/admin/institution-profile/{$slug}")->assertForbidden();
    }

    foreach (PROFILE_PAGES as $class) {
        Livewire::test($class)->assertForbidden();
    }

    if ($role !== 'wali') {
        expect(kontenNavigationText($user))->not->toContain('Profil lembaga');
    }
})->with(['bendahara', 'admin-psb', 'kesantrian', 'musyrif', 'petugas-pos', 'wali']);

it('AC-32: no longer serves the old school profile url', function () {
    $this->actingAs(kontenUser('super-admin'))->get('/admin/school-profile')->assertNotFound();
});

it('AC-34: saves the contacts through the repeater', function () {
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(Contacts::class)
        ->fillForm([
            'map_url' => 'https://maps.app.goo.gl/abc',
            'contacts' => [
                ['label' => 'Panitia PSB', 'phone' => '0812-0000-0001', 'is_whatsapp' => true],
                ['label' => 'Sekretariat', 'phone' => '081200000002', 'is_whatsapp' => false],
            ],
            'social_links' => [['platform' => 'instagram', 'url' => 'https://instagram.com/alkhair']],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Setting::get('school.contacts'))->toHaveCount(2)
        ->and(array_column(Setting::get('school.contacts'), 'phone'))->toBe(['081200000001', '081200000002'])
        ->and(Setting::get('school.map_url'))->toBe('https://maps.app.goo.gl/abc')
        ->and(Setting::get('school.social_links'))->toHaveCount(1);
});

it('AC-34: shows an invalid contact phone on the right repeater field without an exception', function () {
    $this->actingAs(kontenUser('admin-konten'));

    $component = Livewire::test(Contacts::class)
        ->fillForm(['contacts' => [
            ['label' => 'Panitia PSB', 'phone' => '081200000001', 'is_whatsapp' => true],
            ['label' => 'Sekretariat', 'phone' => '12ab', 'is_whatsapp' => true],
        ]])
        ->call('save');

    $keys = $component->errors()->keys();

    expect(collect($keys)->contains(fn (string $key): bool => preg_match('/^data\.contacts\..+\.phone$/', $key) === 1))->toBeTrue(json_encode($keys))
        ->and(Setting::get('school.contacts'))->toBeNull();
});

it('AC-35: uploads the desktop logo and the favicon and shows the logo preview', function () {
    $this->actingAs(kontenUser('admin-sekolah'));

    $component = Livewire::test(Identity::class)
        ->fillForm([
            'name' => 'Pondok Baru',
            'logo_desktop' => kontenImage('desktop.png', 300),
            'favicon' => UploadedFile::fake()->image('favicon.png', 128, 128)->size(100),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $desktop = Setting::get('school.logo_desktop_path');

    expect($desktop)->toStartWith('school/')
        ->and(Setting::get('school.favicon_path'))->toStartWith('school/')->toEndWith('.png')
        ->and(Setting::get('school.logo_path'))->toBeNull()
        ->and(kontenFiles())->toHaveCount(2);

    $component->assertSeeHtml(Storage::disk('public')->url($desktop));
});

it('AC-35: removes the favicon from the page', function () {
    Storage::disk('public')->put('school/f.png', 'png');
    Setting::set('school.favicon_path', 'school/f.png');
    $this->actingAs(kontenUser('admin-sekolah'));

    Livewire::test(Identity::class)
        ->fillForm(['remove_favicon' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::get('school.favicon_path'))->toBeNull()->and(Storage::disk('public')->exists('school/f.png'))->toBeFalse();
});

it('AC-36: lists albums and videos on the home page and saves the chosen ids', function () {
    $album = kontenAlbum(['title' => 'Fasilitas Pondok']);
    $video = kontenVideo(['title' => 'Video Profil Uji']);
    $this->actingAs(kontenUser('admin-konten'));

    $component = Livewire::test(Home::class)
        ->assertFormFieldExists('hero_album_id', fn (Select $field): bool => in_array('Fasilitas Pondok', array_values($field->getOptions()), true)
            || in_array('Fasilitas Pondok', array_values($field->getSearchResults('Fasilitas')), true))
        ->assertFormFieldExists('profile_video_id', fn (Select $field): bool => in_array('Video Profil Uji', array_values($field->getOptions()), true)
            || in_array('Video Profil Uji', array_values($field->getSearchResults('Video')), true))
        ->fillForm([
            'hero_title' => 'Judul uji',
            'highlights' => [['title' => 'Keunggulan satu', 'description' => 'Deskripsi']],
            'hero_album_id' => $album->id, 'profile_video_id' => $video->id, 'psb_video_id' => $video->id,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Setting::get('portal.hero_album_id'))->toBe($album->id)
        ->and(Setting::get('portal.profile_video_id'))->toBe($video->id)
        ->and(Setting::get('portal.psb_video_id'))->toBe($video->id)
        ->and(Setting::get('portal.hero_title'))->toBe('Judul uji')
        ->and(Setting::get('portal.highlights'))->toHaveCount(1);
});

it('AC-36: hides the media selects when the content module is off and keeps the stored ids', function () {
    $album = kontenAlbum();
    $video = kontenVideo();
    profilSet(['portal.hero_album_id' => $album->id, 'portal.profile_video_id' => $video->id, 'portal.psb_video_id' => $video->id]);
    Setting::set('modules.konten.enabled', false);
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(Home::class)
        ->assertFormFieldDoesNotExist('hero_album_id')
        ->assertFormFieldDoesNotExist('profile_video_id')
        ->assertFormFieldDoesNotExist('psb_video_id')
        ->assertSee(__('pondok.common.module_disabled'))
        ->fillForm(['hero_title' => 'Tetap tersimpan'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::get('portal.hero_title'))->toBe('Tetap tersimpan')
        ->and(Setting::get('portal.hero_album_id'))->toBe($album->id)
        ->and(Setting::get('portal.profile_video_id'))->toBe($video->id)
        ->and(Setting::get('portal.psb_video_id'))->toBe($video->id);
});

it('AC-37: renders no raw translation keys and no content examples in the four forms', function (string $slug) {
    $html = $this->actingAs(kontenUser('super-admin'))->get("/admin/institution-profile/{$slug}")->assertOk()->getContent();

    expect($html)->not->toMatch('/\bpondok\.[a-z_]+\.[a-z_]+/');

    preg_match('#<form\b.*?</form>#is', $html, $form);
    $text = html_entity_decode(strip_tags($form[0] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    preg_match_all('/placeholder="([^"]*)"/i', $form[0] ?? '', $placeholders);
    $visible = $text.' '.implode(' ', $placeholders[1]);

    expect($form)->not->toBeEmpty()
        ->and($visible)->not->toMatch('/\p{Arabic}/u')
        ->not->toMatch('/\bQS\b/')->not->toMatch('/bismillah|assalamu|ahlan/i');
})->with(array_keys(PROFILE_PAGES));

it('AC-37: explains the logo fields with the helper texts of the plan', function () {
    $text = kontenPageText(kontenUser('admin-sekolah'), '/admin/institution-profile/identity');

    expect($text)->toContain('Dipakai di kop surat dan kuitansi')
        ->toContain('Boleh berisi beberapa logo dalam satu gambar')
        ->toContain('PNG persegi, maks. 256 KB');
});
