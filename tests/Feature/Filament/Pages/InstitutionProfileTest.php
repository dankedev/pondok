<?php

use App\Filament\Pages\Settings\Contacts;
use App\Filament\Pages\Settings\Identity;
use App\Filament\Pages\Site\Footer;
use App\Filament\Pages\Site\Home;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| F044: cluster Profil lembaga dibubarkan. Lima halaman: `site/home|navigation|footer` (grup Situs) dan
| `settings/identity|contacts` (grup Pengaturan); kelas di `App\Filament\Pages\Site` dan `App\Filament\Pages\Settings`.
*/
const PROFILE_PAGES = [
    'settings/identity' => Identity::class,
    'settings/contacts' => Contacts::class,
    'site/home' => Home::class,
    'site/footer' => Footer::class,
];

beforeEach(function () {
    $this->f = kontenBoot();
    Setting::set('school.name', 'Pondok Lama');
});

it('AC-32: lets school and content admins open the four profile pages', function (string $role) {
    $user = kontenUser($role);

    foreach (array_keys(PROFILE_PAGES) as $slug) {
        $this->actingAs($user)->get("/admin/{$slug}")->assertOk();
    }

    Livewire::test(Identity::class)->assertFormSet(['name' => 'Pondok Lama']);
})->with(['super-admin', 'admin-sekolah', 'admin-konten']);

it('AC-32: forbids the four profile pages to the other roles and hides the menu item', function (string $role) {
    $user = kontenUser($role);

    foreach (array_keys(PROFILE_PAGES) as $slug) {
        $this->actingAs($user)->get("/admin/{$slug}")->assertForbidden();
    }

    foreach (PROFILE_PAGES as $class) {
        Livewire::test($class)->assertForbidden();
    }

    if ($role !== 'wali') {
        expect(kontenNavigationText($user))->not->toContain('Profil lembaga')->not->toContain('Kontak & sosial');
    }
})->with(['bendahara', 'admin-psb', 'kesantrian', 'musyrif', 'petugas-pos', 'wali']);

it('AC-32: no longer serves the old school profile or cluster urls', function () {
    $this->actingAs(kontenUser('super-admin'));

    foreach (['/admin/school-profile', '/admin/institution-profile/identity', '/admin/institution-profile/contacts'] as $url) {
        $this->get($url)->assertNotFound();
    }

    expect(class_exists('App\\Filament\\Clusters\\InstitutionProfile\\InstitutionProfileCluster'))->toBeFalse();
});

it('AC-34/AC-7: saves address, email and contacts with the print flag through the repeater', function () {
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(Contacts::class)
        ->fillForm([
            'address' => 'Jl. Pesantren No. 1',
            'email' => 'info@alkhair.sch.id',
            'map_url' => 'https://maps.app.goo.gl/abc',
            'contacts' => [
                ['label' => 'Panitia PSB', 'phone' => '0812-0000-0001', 'is_whatsapp' => true, 'is_print' => false],
                ['label' => 'Sekretariat', 'phone' => '081200000002', 'is_whatsapp' => false, 'is_print' => true],
            ],
            'social_links' => [['platform' => 'instagram', 'url' => 'https://instagram.com/alkhair']],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Setting::get('school.contacts'))->toHaveCount(2)
        ->and(array_column(Setting::get('school.contacts'), 'phone'))->toBe(['081200000001', '081200000002'])
        ->and(array_column(Setting::get('school.contacts'), 'is_print'))->toBe([false, true])
        ->and(Setting::get('school.address'))->toBe('Jl. Pesantren No. 1')
        ->and(Setting::get('school.email'))->toBe('info@alkhair.sch.id')
        ->and(Setting::get('school.map_url'))->toBe('https://maps.app.goo.gl/abc')
        ->and(Setting::get('school.social_links'))->toHaveCount(1);
});

it('AC-12 (F038): saves the psb flag of contacts through the repeater toggle', function () {
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(Contacts::class)
        ->fillForm([
            'contacts' => [
                ['label' => 'Panitia PSB', 'phone' => '0812-0000-0001', 'is_whatsapp' => true, 'is_print' => false, 'is_psb' => true],
                ['label' => 'Sekretariat', 'phone' => '081200000002', 'is_whatsapp' => false, 'is_print' => false, 'is_psb' => false],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(array_column(Setting::get('school.contacts'), 'is_psb'))->toBe([true, false]);
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

it('AC-7: shows two print contacts as an error on the contacts field and saves nothing', function () {
    $this->actingAs(kontenUser('admin-konten'));
    Setting::set('school.address', 'Alamat lama');

    $component = Livewire::test(Contacts::class)
        ->fillForm([
            'address' => 'Alamat baru',
            'contacts' => [
                ['label' => 'A', 'phone' => '081200000001', 'is_whatsapp' => true, 'is_print' => true],
                ['label' => 'B', 'phone' => '081200000002', 'is_whatsapp' => true, 'is_print' => true],
            ],
        ])
        ->call('save');

    expect(collect($component->errors()->keys())->contains(fn (string $key): bool => $key === 'data.contacts' || str_starts_with($key, 'data.contacts.')))->toBeTrue(json_encode($component->errors()->keys()))
        ->and(kontenSeeMessage($component, 'print_contact_multiple'))->toBeTrue()
        ->and(Setting::get('school.address'))->toBe('Alamat lama')
        ->and(Setting::get('school.contacts'))->toBeNull();
});

it('AC-7: fills Contacts from the settings and Identity has no address, phone or email field', function () {
    $this->actingAs(kontenUser('admin-konten'));
    profilSet([
        'school.address' => 'Jl. Pesantren No. 1', 'school.email' => 'info@alkhair.sch.id',
        'school.contacts' => [profilContact(['key' => 'aaaa1111', 'is_print' => true])],
    ]);

    Livewire::test(Contacts::class)
        ->assertFormSet(['address' => 'Jl. Pesantren No. 1', 'email' => 'info@alkhair.sch.id']);

    Livewire::test(Identity::class)
        ->assertFormFieldDoesNotExist('address')
        ->assertFormFieldDoesNotExist('phone')
        ->assertFormFieldDoesNotExist('email');

    expect(kontenPageText(kontenUser('admin-konten'), '/admin/settings/contacts'))->toContain('Cetak di kop surat dan kuitansi');
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

it('AC-13 (F045): the home page is a block builder and no longer has the fixed home fields', function () {
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(Home::class)
        ->assertFormFieldExists('home_blocks')
        ->assertFormFieldDoesNotExist('hero_greeting')
        ->assertFormFieldDoesNotExist('highlights')
        ->assertFormFieldDoesNotExist('psb_video_id');
});

it('AC-37: renders no raw translation keys and no content examples in the four forms', function (string $slug) {
    $html = $this->actingAs(kontenUser('super-admin'))->get("/admin/{$slug}")->assertOk()->getContent();

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
    $text = kontenPageText(kontenUser('admin-sekolah'), '/admin/settings/identity');

    expect($text)->toContain('Dipakai di kop surat dan kuitansi')
        ->toContain('Boleh berisi beberapa logo dalam satu gambar')
        ->toContain('PNG persegi, maks. 256 KB');
});
