<?php

use App\Actions\Konten\UpdateSchoolContacts;
use App\Models\Setting;

beforeEach(function () {
    $this->f = kontenBoot();
    $this->admin = kontenUser('admin-sekolah');
});

/**
 * @param  array<string, mixed>  $data
 */
function profilSaveContacts(array $data): void
{
    app(UpdateSchoolContacts::class)->handle($data, test()->admin);
}

/**
 * @return array<string, mixed>
 */
function profilContactsInput(): array
{
    return [
        'map_url' => 'https://maps.app.goo.gl/abc',
        'contacts' => [
            ['label' => 'Panitia PSB', 'name' => 'Ust. Ahmad', 'phone' => '0812-0000-0001', 'is_whatsapp' => true],
            ['label' => 'Sekretariat', 'phone' => '(0735) 000 0170', 'is_whatsapp' => false],
        ],
        'social_links' => [
            ['platform' => 'instagram', 'url' => 'https://instagram.com/alkhair'],
            ['platform' => 'youtube', 'label' => null, 'url' => 'https://youtube.com/@alkhair'],
            ['platform' => 'lainnya', 'label' => 'Blog Kami', 'url' => 'https://blog.contoh.test'],
        ],
    ];
}

it('AC-20: saves ordered contacts and socials', function () {
    profilSaveContacts(profilContactsInput());

    $contacts = Setting::get('school.contacts');
    $socials = Setting::get('school.social_links');

    expect($contacts)->toHaveCount(2)
        ->and(array_column($contacts, 'label'))->toBe(['Panitia PSB', 'Sekretariat'])
        ->and(array_column($contacts, 'phone'))->toBe(['081200000001', '07350000170'])
        ->and(array_column($contacts, 'is_whatsapp'))->toBe([true, false])
        ->and($contacts[0]['name'])->toBe('Ust. Ahmad')->and($contacts[1]['name'])->toBeNull()
        ->and($contacts[0]['key'])->toMatch('/^[a-z0-9]{8}$/')->and($contacts[1]['key'])->toMatch('/^[a-z0-9]{8}$/')
        ->and($contacts[0]['key'])->not->toBe($contacts[1]['key'])
        ->and(array_column($socials, 'platform'))->toBe(['instagram', 'youtube', 'lainnya'])
        ->and(array_column($socials, 'url'))->toBe(['https://instagram.com/alkhair', 'https://youtube.com/@alkhair', 'https://blog.contoh.test'])
        ->and($socials[2]['label'])->toBe('Blog Kami')
        ->and(Setting::get('school.map_url'))->toBe('https://maps.app.goo.gl/abc');
});

it('AC-20: logs the saved phone numbers without masking', function () {
    profilSaveContacts(profilContactsInput());

    $activities = kontenActivities()->where('description', 'school_contacts_updated');

    expect($activities)->toHaveCount(1)
        ->and($activities->first()->causer_id)->toBe($this->admin->id)
        ->and(json_encode($activities->first()->properties))->toContain('081200000001')->toContain('07350000170');
});

it('AC-20: does not log when nothing changed and does not touch other keys', function () {
    Setting::set('school.name', 'Pondok Uji');
    profilSaveContacts(profilContactsInput());
    $contacts = Setting::get('school.contacts');
    $input = profilContactsInput();
    $input['contacts'][0]['key'] = $contacts[0]['key'];
    $input['contacts'][1]['key'] = $contacts[1]['key'];

    profilSaveContacts($input);

    expect(kontenActivities()->where('description', 'school_contacts_updated'))->toHaveCount(1)
        ->and(Setting::get('school.contacts'))->toBe($contacts)
        ->and(Setting::get('school.name'))->toBe('Pondok Uji');
});

it('AC-21: keeps the key of a resent contact and drops the contacts that are not sent', function () {
    profilSaveContacts(profilContactsInput());
    $stored = Setting::get('school.contacts');

    profilSaveContacts(['contacts' => [['key' => $stored[0]['key'], 'label' => 'Label Baru', 'phone' => '081200000001', 'is_whatsapp' => true]]]);

    $contacts = Setting::get('school.contacts');

    expect($contacts)->toHaveCount(1)
        ->and($contacts[0]['key'])->toBe($stored[0]['key'])
        ->and($contacts[0]['label'])->toBe('Label Baru');
});

it('AC-21: stores abcd1234 as given when a contact is resent with that key', function () {
    Setting::set('school.contacts', [profilContact(['key' => 'abcd1234'])]);

    profilSaveContacts(['contacts' => [profilContact(['key' => 'abcd1234', 'label' => 'Diubah'])]]);

    expect(Setting::get('school.contacts')[0])->toMatchArray(['key' => 'abcd1234', 'label' => 'Diubah']);
});

it('AC-22: rejects invalid contacts with an indexed field and changes nothing', function (array $contacts, string $field) {
    profilSaveContacts(profilContactsInput());
    $before = profilSettingsSnapshot();

    profilAssertInvalid(fn () => profilSaveContacts(['contacts' => $contacts]), $field);

    expect(profilSettingsSnapshot())->toBe($before);
})->with([
    'no label' => [[profilContact(['key' => null]), ['label' => '', 'phone' => '081200000002']], 'contacts.1.label'],
    'bad phone' => [[profilContact(['key' => null]), ['label' => 'B', 'phone' => '12ab']], 'contacts.1.phone'],
    'missing phone' => [[['label' => 'A']], 'contacts.0.phone'],
    'eleven contacts' => [array_map(fn (int $i): array => ['label' => "K{$i}", 'phone' => '0812000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)], range(1, 11)), 'contacts'],
    'duplicate keys' => [[profilContact(['key' => 'abcd1234']), profilContact(['key' => 'abcd1234', 'phone' => '081200000002'])], 'contacts'],
    'label too long' => [[['label' => str_repeat('a', 61), 'phone' => '081200000002']], 'contacts.0.label'],
    'name too long' => [[['label' => 'A', 'name' => str_repeat('a', 101), 'phone' => '081200000002']], 'contacts.0.name'],
]);

it('AC-22: reports a bad phone with the phone_invalid message', function () {
    kontenAssertRejected(fn () => profilSaveContacts(['contacts' => [['label' => 'A', 'phone' => '12ab']]]), 'phone_invalid', 'contacts.0.phone');
});

it('AC-22: accepts exactly ten contacts', function () {
    profilSaveContacts(['contacts' => array_map(fn (int $i): array => ['label' => "K{$i}", 'phone' => '0812000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)], range(1, 10))]);

    expect(Setting::get('school.contacts'))->toHaveCount(10);
});

it('AC-23: rejects unsafe social urls and changes nothing', function (string $url) {
    profilSaveContacts(profilContactsInput());
    $before = profilSettingsSnapshot();

    kontenAssertRejected(fn () => profilSaveContacts(['social_links' => [['platform' => 'instagram', 'url' => $url]]]), 'url_invalid', 'social_links.0.url');

    expect(profilSettingsSnapshot())->toBe($before);
})->with([
    'javascript' => ['javascript:alert(1)'],
    'ftp' => ['ftp://x'],
    'protocol relative' => ['//evil.test'],
    'tab' => ["https://a.test/\tx"],
    'newline' => ["https://a.test/\nx"],
    'space' => ['https://a.test/a b'],
    'no host' => ['https:///x'],
    'data' => ['data:text/html,x'],
]);

it('AC-23: rejects an unknown platform and a lainnya link without a label', function (array $link, string $field) {
    profilSaveContacts(profilContactsInput());
    $before = profilSettingsSnapshot();

    profilAssertInvalid(fn () => profilSaveContacts(['social_links' => [$link]]), $field);

    expect(profilSettingsSnapshot())->toBe($before);
})->with([
    'myspace' => [['platform' => 'myspace', 'url' => 'https://a.test'], 'social_links.0.platform'],
    'lainnya without label' => [['platform' => 'lainnya', 'url' => 'https://a.test'], 'social_links.0.label'],
    'lainnya blank label' => [['platform' => 'lainnya', 'label' => '  ', 'url' => 'https://a.test'], 'social_links.0.label'],
]);

it('AC-23: rejects more than ten social links and a url over 255 characters', function () {
    profilAssertInvalid(fn () => profilSaveContacts(['social_links' => array_fill(0, 11, ['platform' => 'x', 'url' => 'https://x.com/a'])]), 'social_links');
    kontenAssertRejected(fn () => profilSaveContacts(['social_links' => [['platform' => 'x', 'url' => 'https://a.test/'.str_repeat('a', 250)]]]), 'too_long', 'social_links.0.url');
});

it('AC-23: exposes the platform list as a constant', function () {
    expect(UpdateSchoolContacts::PLATFORMS)->toBe(['instagram', 'facebook', 'youtube', 'tiktok', 'telegram', 'whatsapp_channel', 'x', 'website', 'lainnya']);
});

it('AC-24: validates the map url', function () {
    profilSaveContacts(['map_url' => 'https://maps.app.goo.gl/abc']);

    expect(Setting::get('school.map_url'))->toBe('https://maps.app.goo.gl/abc');

    foreach (['javascript:x', "https://a.test/\tx", '//evil.test', 'ftp://x.test'] as $url) {
        kontenAssertRejected(fn () => profilSaveContacts(['map_url' => $url]), 'url_invalid', 'map_url');
    }

    expect(Setting::get('school.map_url'))->toBe('https://maps.app.goo.gl/abc');
});

it('AC-25: stores empty lists and an empty map url as null', function () {
    profilSaveContacts(profilContactsInput());

    profilSaveContacts(['map_url' => '', 'contacts' => [], 'social_links' => []]);

    expect(Setting::get('school.contacts'))->toBeNull()
        ->and(Setting::get('school.social_links'))->toBeNull()
        ->and(Setting::get('school.map_url'))->toBeNull();
});

it('AC-20: writes nothing when validation fails and leaves the log untouched', function () {
    kontenAssertRejected(fn () => profilSaveContacts(['map_url' => 'https://ok.test', 'contacts' => [['label' => 'A', 'phone' => '12ab']]]), 'phone_invalid', 'contacts.0.phone');

    expect(Setting::get('school.map_url'))->toBeNull()->and(kontenActivities())->toHaveCount(0);
});

it('AC-4: saves address, email and the print flag and keeps the keys of resent contacts', function () {
    profilSaveContacts(['contacts' => [profilContact(['key' => 'abcd1234'])]]);

    profilSaveContacts([
        'address' => 'Jl. Pesantren No. 1',
        'email' => 'info@alkhair.sch.id',
        'contacts' => [
            profilContact(['key' => 'abcd1234', 'is_print' => true]),
            ['label' => 'Panitia', 'phone' => '081200000002', 'is_whatsapp' => true],
        ],
    ]);

    $contacts = Setting::get('school.contacts');

    expect(Setting::get('school.address'))->toBe('Jl. Pesantren No. 1')
        ->and(Setting::get('school.email'))->toBe('info@alkhair.sch.id')
        ->and($contacts[0])->toMatchArray(['key' => 'abcd1234', 'is_print' => true])
        ->and($contacts[1]['is_print'])->toBeFalse()
        ->and(kontenActivities()->where('description', 'school_contacts_updated'))->not->toBeEmpty();
});

it('AC-4: rejects invalid address, email or two print contacts on the right field and changes nothing', function (array $input, string $field) {
    profilSaveContacts(['address' => 'Alamat lama', 'email' => 'lama@alkhair.sch.id', 'contacts' => [profilContact(['key' => 'abcd1234'])]]);
    $before = profilSettingsSnapshot();
    $activities = kontenActivities()->count();

    profilAssertInvalid(fn () => profilSaveContacts($input), $field);

    expect(profilSettingsSnapshot())->toBe($before)->and(kontenActivities()->count())->toBe($activities);
})->with([
    'bad email' => [['email' => 'bukan-email', 'address' => 'Baru'], 'email'],
    'email too long' => [['email' => str_repeat('a', 250).'@x.id'], 'email'],
    'address too long' => [['address' => str_repeat('a', 501)], 'address'],
    'two print contacts' => [['address' => 'Baru', 'contacts' => [profilContact(['key' => 'abcd1234', 'is_print' => true]), profilContact(['key' => 'efgh5678', 'phone' => '081200000002', 'is_print' => true])]], 'contacts'],
]);

it('AC-4: reports two print contacts with the print_contact_multiple message', function () {
    kontenAssertRejected(fn () => profilSaveContacts(['contacts' => [
        ['label' => 'A', 'phone' => '081200000001', 'is_print' => true],
        ['label' => 'B', 'phone' => '081200000002', 'is_print' => true],
    ]]), 'print_contact_multiple', 'contacts');
});

it('AC-10 (F038): saves the psb flag for several contacts, parsed like the other flags, and logs only a change', function () {
    $input = profilContactsInput();
    $input['contacts'] = [
        ['label' => 'A', 'phone' => '081200000001', 'is_psb' => true],
        ['label' => 'B', 'phone' => '081200000002', 'is_psb' => '1'],
        ['label' => 'C', 'phone' => '081200000003', 'is_psb' => 'false'],
        ['label' => 'D', 'phone' => '081200000004', 'is_psb' => '0'],
        ['label' => 'E', 'phone' => '081200000005', 'is_psb' => ''],
        ['label' => 'F', 'phone' => '081200000006'],
    ];
    profilSaveContacts($input);
    $contacts = Setting::get('school.contacts');

    expect(array_column($contacts, 'is_psb'))->toBe([true, true, false, false, false, false]);

    $again = $input;
    foreach ($contacts as $i => $contact) {
        $again['contacts'][$i]['key'] = $contact['key'];
    }
    profilSaveContacts($again);
    expect(kontenActivities()->where('description', 'school_contacts_updated'))->toHaveCount(1);

    $again['contacts'][2]['is_psb'] = true;
    profilSaveContacts($again);
    expect(kontenActivities()->where('description', 'school_contacts_updated'))->toHaveCount(2)
        ->and(Setting::get('school.contacts')[2]['is_psb'])->toBeTrue();
});
