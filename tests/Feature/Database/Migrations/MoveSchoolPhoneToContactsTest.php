<?php

use Illuminate\Support\Facades\DB;

/*
| F044 AC-1. Migrasi `database/migrations/2026_10_19_000001_move_school_phone_to_contacts.php` (kelas anonim, up/down),
| ditulis dengan DB::table. Kontrak: `school.phone` disalin apa adanya (hanya trim); pencocokan dengan kontak lama memakai
| normalisasi (buang spasi, `-`, `.`, `(`, `)`); kontak baru: label "Kantor", name null, is_whatsapp false, is_print true.
*/

function phoneMigration(): object
{
    return require database_path('migrations/2026_10_19_000001_move_school_phone_to_contacts.php');
}

it('AC-1a: marks the matching contact for print without adding a contact', function () {
    profilRawSet('school.contacts', [
        profilContact(['key' => 'aaaa1111', 'phone' => '081200000001', 'is_print' => true]),
        profilContact(['key' => 'bbbb2222', 'label' => 'Kantor Lama', 'phone' => '0735000170', 'is_whatsapp' => false]),
    ]);
    profilRawSet('school.phone', '(0735) 000-170');

    phoneMigration()->up();

    $contacts = profilRawGet('school.contacts');

    expect($contacts)->toHaveCount(2)
        ->and(array_column($contacts, 'is_print'))->toBe([false, true])
        ->and(array_column($contacts, 'key'))->toBe(['aaaa1111', 'bbbb2222'])
        ->and(profilRawHas('school.phone'))->toBeFalse();
});

it('AC-1b: appends an office contact marked for print when the number is new', function () {
    profilRawSet('school.contacts', [profilContact(['key' => 'aaaa1111', 'phone' => '081200000001'])]);
    profilRawSet('school.phone', ' 0735-000-170 ');

    phoneMigration()->up();

    $contacts = profilRawGet('school.contacts');
    $office = end($contacts);

    expect($contacts)->toHaveCount(2)
        ->and($office)->toMatchArray(['label' => 'Kantor', 'name' => null, 'phone' => '0735-000-170', 'is_whatsapp' => false, 'is_print' => true])
        ->and($office['key'])->toMatch('/^[a-z0-9]{8}$/')->not->toBe('aaaa1111')
        ->and($contacts[0]['is_print'] ?? false)->toBeFalse()
        ->and(profilRawHas('school.phone'))->toBeFalse();
});

it('AC-1b: creates the contacts list when there is none, and still moves an invalid phone value', function (string $phone) {
    profilRawSet('school.phone', $phone);

    phoneMigration()->up();

    $contacts = profilRawGet('school.contacts');

    expect($contacts)->toHaveCount(1)
        ->and($contacts[0])->toMatchArray(['label' => 'Kantor', 'phone' => $phone, 'is_print' => true])
        ->and(profilRawHas('school.phone'))->toBeFalse();
})->with(['plain' => ['0735000170'], 'invalid' => ['telp. kantor 12ab']]);

it('AC-1c: changes nothing in contacts without a phone and removes an empty phone key', function (?string $phone) {
    $contacts = [profilContact(['key' => 'aaaa1111'])];
    profilRawSet('school.contacts', $contacts);

    if ($phone !== null) {
        profilRawSet('school.phone', $phone);
    }

    phoneMigration()->up();

    expect(profilRawGet('school.contacts'))->toBe($contacts)->and(profilRawHas('school.phone'))->toBeFalse();
})->with(['no key' => [null], 'empty' => ['']]);

it('AC-1c: does not create a contacts row when there is no phone', function () {
    phoneMigration()->up();

    expect(profilRawHas('school.contacts'))->toBeFalse();
});

it('AC-1c: is idempotent', function () {
    profilRawSet('school.contacts', [profilContact(['key' => 'aaaa1111'])]);
    profilRawSet('school.phone', '0735000170');

    phoneMigration()->up();
    $after = DB::table('settings')->orderBy('key')->pluck('value', 'key')->all();
    phoneMigration()->up();

    expect(DB::table('settings')->orderBy('key')->pluck('value', 'key')->all())->toBe($after);
});

it('AC-1c: down writes the phone back from the print contact and keeps every contact', function () {
    profilRawSet('school.contacts', [
        profilContact(['key' => 'aaaa1111', 'phone' => '081200000001']),
        profilContact(['key' => 'bbbb2222', 'phone' => '0735000170', 'is_print' => true]),
    ]);

    phoneMigration()->down();

    expect(profilRawGet('school.phone'))->toBe('0735000170')->and(profilRawGet('school.contacts'))->toHaveCount(2);

    // naik lagi tidak menggandakan kontak
    phoneMigration()->up();

    expect(profilRawGet('school.contacts'))->toHaveCount(2)
        ->and(array_column(profilRawGet('school.contacts'), 'is_print'))->toBe([false, true]);
});

it('AC-1c: down without a print contact writes no phone', function () {
    profilRawSet('school.contacts', [profilContact(['key' => 'aaaa1111'])]);

    phoneMigration()->down();

    expect(profilRawHas('school.phone'))->toBeFalse();
});

it('Review minor 2: an already full list of ten contacts becomes eleven without data loss', function () {
    profilRawSet('school.contacts', array_map(fn (int $i): array => profilContact(['key' => 'k'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'phone' => '0812000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]), range(1, 10)));
    profilRawSet('school.phone', '0735000170');

    phoneMigration()->up();

    expect(profilRawGet('school.contacts'))->toHaveCount(11);
});
