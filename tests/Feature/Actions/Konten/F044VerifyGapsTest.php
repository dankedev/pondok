<?php

use App\Actions\Konten\GetPrintIdentity;
use App\Actions\Konten\UpdateSchoolContacts;
use App\Filament\Pages\Settings\Contacts;
use App\Filament\Pages\Settings\Identity;
use App\Models\Setting;
use App\Models\StaticPage;
use Database\Seeders\Demo\DemoContent;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\StaticPageSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| F044 VERIFY: celah di luar test RED (migrasi dengan data kotor, is_print dari form, form Identity/Contacts,
| pencocokan halaman Kontak demo).
*/

beforeEach(function () {
    $this->f = kontenBoot();
    $this->admin = kontenUser('admin-sekolah');
});

function f044Migration(): object
{
    return require database_path('migrations/2026_10_19_000001_move_school_phone_to_contacts.php');
}

it('VERIFY: migration marks only the first of two contacts that match the office number', function () {
    profilRawSet('school.contacts', [
        profilContact(['key' => 'aaaa1111', 'phone' => '0735 000 170']),
        profilContact(['key' => 'bbbb2222', 'phone' => '0735-000-170']),
    ]);
    profilRawSet('school.phone', '0735000170');

    f044Migration()->up();

    expect(array_column(profilRawGet('school.contacts'), 'is_print'))->toBe([true, false])
        ->and(profilRawHas('school.phone'))->toBeFalse();
});

it('VERIFY: migration survives a broken school.contacts value', function (string $raw) {
    profilRawSet('school.contacts', $raw);
    profilRawSet('school.phone', '0735000170');

    f044Migration()->up();

    $contacts = profilRawGet('school.contacts');

    expect($contacts)->toHaveCount(1)
        ->and($contacts[0])->toMatchArray(['label' => 'Kantor', 'phone' => '0735000170', 'is_print' => true])
        ->and(profilRawHas('school.phone'))->toBeFalse();
})->with(['string' => ['"rusak"'], 'number' => ['42'], 'json null' => ['null']]);

it('VERIFY: contact is_print from a form string is read as a boolean', function (mixed $input, bool $expected) {
    app(UpdateSchoolContacts::class)->handle(['contacts' => [['label' => 'A', 'phone' => '081200000001', 'is_print' => $input]]], $this->admin);

    expect(Setting::get('school.contacts')[0]['is_print'])->toBe($expected);
})->with([['1', true], ['0', false], ['', false], [1, true], [null, false], [[], false]]);

it('VERIFY: two print contacts sent as strings are rejected and nothing is written', function () {
    Setting::set('school.address', 'Alamat lama');

    expect(fn () => app(UpdateSchoolContacts::class)->handle([
        'address' => 'Alamat baru',
        'contacts' => [
            ['label' => 'A', 'phone' => '081200000001', 'is_print' => '1'],
            ['label' => 'B', 'phone' => '081200000002', 'is_print' => 'true'],
        ],
    ], $this->admin))->toThrow(ValidationException::class);

    expect(Setting::get('school.address'))->toBe('Alamat lama')->and(Setting::get('school.contacts'))->toBeNull();
});

it('VERIFY: print identity ignores a non-boolean is_print written by hand', function () {
    profilSet(['school.name' => 'Pondok X', 'school.contacts' => [profilContact(['is_print' => '1']), profilContact(['key' => 'bbbb2222', 'phone' => '0811', 'is_print' => 1])]]);

    expect(app(GetPrintIdentity::class)->handle()['phone'])->toBeNull();
});

it('VERIFY: the Identity form cannot change address, phone or email even when forced through Livewire', function () {
    $this->actingAs(kontenUser('admin-konten'));
    profilSet(['school.name' => 'Pondok Lama', 'school.address' => 'Alamat lama', 'school.email' => 'lama@contoh.test', 'school.contacts' => [profilContact(['is_print' => true])]]);

    Livewire::test(Identity::class)
        ->set('data.address', 'Alamat paksa')
        ->set('data.email', 'paksa@contoh.test')
        ->set('data.phone', '0899')
        ->set('data.name', 'Pondok Baru')
        ->call('save');

    expect(Setting::get('school.name'))->toBe('Pondok Baru')
        ->and(Setting::get('school.address'))->toBe('Alamat lama')
        ->and(Setting::get('school.email'))->toBe('lama@contoh.test')
        ->and(Setting::get('school.contacts'))->toHaveCount(1)
        ->and(Setting::get('school.phone'))->toBeNull();
});

it('VERIFY: the Contacts form saves a new address without dropping contacts, socials, map or print flag', function () {
    $this->actingAs(kontenUser('admin-konten'));
    profilSet([
        'school.address' => 'Alamat lama',
        'school.map_url' => 'https://maps.app.goo.gl/abc',
        'school.contacts' => [profilContact(['key' => 'aaaa1111', 'is_print' => true])],
        'school.social_links' => [['platform' => 'instagram', 'label' => null, 'url' => 'https://instagram.com/x']],
    ]);

    Livewire::test(Contacts::class)->fillForm(['address' => 'Alamat baru'])->call('save')->assertHasNoFormErrors();

    expect(Setting::get('school.address'))->toBe('Alamat baru')
        ->and(Setting::get('school.map_url'))->toBe('https://maps.app.goo.gl/abc')
        ->and(Setting::get('school.social_links'))->toHaveCount(1)
        ->and(Setting::get('school.contacts')[0])->toMatchArray(['key' => 'aaaa1111', 'is_print' => true]);
});

it('VERIFY: settings pages stay open for profile roles when the konten module is off', function () {
    Setting::set('modules.konten.enabled', false);
    $user = kontenUser('admin-sekolah');

    foreach (['settings/identity', 'settings/contacts'] as $slug) {
        $this->actingAs($user)->get("/admin/{$slug}")->assertOk();
    }
});

it('VERIFY: demo repair does not overwrite an admin contact page that merely contains a longer number', function () {
    $this->seed(StaticPageSeeder::class);
    Setting::set('school.address', 'Alamat demo');
    $custom = '<p>Hubungi bagian keuangan di 0735 000 01709 pada jam kerja.</p>';
    StaticPage::findBySlug('kontak')->update(['body' => $custom]);

    (new DemoContent(new DemoContext))->profile();

    expect((string) StaticPage::findBySlug('kontak')->body)->toBe($custom);
});
