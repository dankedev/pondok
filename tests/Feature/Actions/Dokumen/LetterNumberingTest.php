<?php

use App\Actions\Dokumen\CancelIssuedLetter;
use App\Actions\Dokumen\LetterNumbering;
use App\Models\Setting;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->f = suratFixture();
    $this->numbering = app(LetterNumbering::class);
});

it('AC-6: formats default letter numbers', function (string $type, string $date, int $sequence, string $expected) {
    expect($this->numbering->format($type, CarbonImmutable::parse($date), $sequence))->toBe($expected);
})->with([
    'keterangan aktif' => ['keterangan_aktif', '2026-10-15', 45, '045/MAK/SKA/X/2026'],
    'pindah' => ['pindah', '2026-10-15', 1, '001/MAK/SKP/X/2026'],
    'rekomendasi' => ['rekomendasi', '2026-10-15', 2, '002/MAK/SRK/X/2026'],
    'syahadah' => ['syahadah', '2026-10-15', 3, '003/MAK/SYH/X/2026'],
    'kartu santri' => ['kartu_santri', '2026-10-15', 4, '004/MAK/KTS/X/2026'],
    'seq beyond padding' => ['keterangan_aktif', '2026-10-15', 1000, '1000/MAK/SKA/X/2026'],
    'january' => ['keterangan_aktif', '2027-01-04', 1, '001/MAK/SKA/I/2027'],
    'december' => ['keterangan_aktif', '2026-12-31', 1, '001/MAK/SKA/XII/2026'],
    'april' => ['keterangan_aktif', '2026-04-01', 1, '001/MAK/SKA/IV/2026'],
    'september' => ['keterangan_aktif', '2026-09-01', 1, '001/MAK/SKA/IX/2026'],
]);

it('AC-6: derives the school code from the school name', function (string $name, string $expected) {
    expect($this->numbering->defaultSchoolCode($name))->toBe($expected);
})->with([
    "Ma'had Al-Kitab" => ["Ma'had Al-Kitab", 'MAK'],
    'four words' => ['Pondok Pesantren Al Ikhlas', 'PPAI'],
    'only symbols' => ["'''", 'PONDOK'],
    'empty' => ['', 'PONDOK'],
    'more than six words' => ['Satu Dua Tiga Empat Lima Enam Tujuh Delapan', 'SDTELE'],
    'lower case' => ['pondok modern', 'PM'],
]);

it('AC-6: reads the school code from the school name in settings', function () {
    Setting::set('school.name', 'Pondok Pesantren Al Ikhlas');

    expect($this->numbering->format('keterangan_aktif', CarbonImmutable::parse('2026-10-15'), 1))->toBe('001/PPAI/SKA/X/2026')
        ->and($this->numbering->settings()['school_code'])->toBe('PPAI');
});

it('AC-6: reports the defaults when nothing is stored', function () {
    expect($this->numbering->settings())->toEqual([
        'format' => '{seq}/{school_code}/{type_code}/{month_roman}/{year}',
        'school_code' => 'MAK',
        'type_codes' => ['keterangan_aktif' => 'SKA', 'pindah' => 'SKP', 'rekomendasi' => 'SRK', 'syahadah' => 'SYH', 'kartu_santri' => 'KTS'],
        'padding' => 3,
    ]);
});

it('AC-7: honours stored numbering settings', function () {
    Setting::set('letters.number_format', '{type_code}-{seq}/{school_code}/{month}/{year}');
    Setting::set('letters.school_code', 'ALK');
    Setting::set('letters.type_codes', ['pindah' => 'SPD']);
    Setting::set('letters.sequence_padding', 4);

    expect($this->numbering->format('pindah', CarbonImmutable::parse('2026-03-02'), 7))->toBe('SPD-0007/ALK/03/2026')
        ->and($this->numbering->format('keterangan_aktif', CarbonImmutable::parse('2026-03-02'), 7))->toBe('SKA-0007/ALK/03/2026')
        ->and($this->numbering->format('kartu_santri', CarbonImmutable::parse('2026-12-02'), 12))->toBe('KTS-0012/ALK/12/2026');
});

it('AC-7: accepts explicit settings instead of the stored ones', function () {
    $settings = $this->numbering->settings();
    $settings['padding'] = 5;
    $settings['school_code'] = 'XYZ';

    expect($this->numbering->format('syahadah', CarbonImmutable::parse('2026-10-15'), 9, $settings))->toBe('00009/XYZ/SYH/X/2026');
});

it('AC-7: lets the stored school code win over the derived one and falls back when it is empty', function () {
    Setting::set('letters.school_code', null);

    expect($this->numbering->format('pindah', CarbonImmutable::parse('2026-10-15'), 1))->toBe('001/MAK/SKP/X/2026');

    Setting::set('letters.school_code', 'ALK');

    expect($this->numbering->format('pindah', CarbonImmutable::parse('2026-10-15'), 1))->toBe('001/ALK/SKP/X/2026');
});

it('AC-6: counts the next sequence per type and year from every letter, including cancelled ones', function () {
    $f = $this->f;

    expect($this->numbering->nextSequence('keterangan_aktif', 2026))->toBe(1);

    $first = suratIssue($f);
    suratIssue($f, student: $f->s2, by: $f->admin);
    app(CancelIssuedLetter::class)->handle($first, $f->admin, 'Salah');

    expect($this->numbering->nextSequence('keterangan_aktif', 2026))->toBe(3)
        ->and($this->numbering->nextSequence('keterangan_aktif', 2027))->toBe(1)
        ->and($this->numbering->nextSequence('pindah', 2026))->toBe(1);
});

it('AC-6: counts the next sequence without the unit scope of the logged in user', function () {
    $f = $this->f;
    suratIssue($f);
    $this->actingAs($f->scoped);

    expect($this->numbering->nextSequence('keterangan_aktif', 2026))->toBe(2);
});
