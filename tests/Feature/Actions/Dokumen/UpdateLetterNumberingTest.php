<?php

use App\Actions\Dokumen\CancelIssuedLetter;
use App\Actions\Dokumen\LetterNumbering;
use App\Actions\Dokumen\UpdateLetterNumbering;
use App\Models\Setting;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->f = suratFixture();
});

/**
 * Semua key settings penomoran yang boleh berubah.
 *
 * @return array<string, mixed>
 */
function suratStoredNumbering(): array
{
    return collect(['letters.number_format', 'letters.school_code', 'letters.type_codes', 'letters.sequence_padding'])
        ->mapWithKeys(fn (string $key): array => [$key => Setting::query()->find($key)?->value])
        ->all();
}

it('AC-8: saves numbering settings', function () {
    $f = $this->f;

    app(UpdateLetterNumbering::class)->handle(suratNumberingData([
        'number_format' => '{type_code}-{seq}/{school_code}/{month}/{year}',
        'school_code' => 'ALK',
        'type_codes' => ['pindah' => 'SPD'],
        'sequence_padding' => 4,
    ]), $f->admin);

    expect(Setting::get('letters.number_format'))->toBe('{type_code}-{seq}/{school_code}/{month}/{year}')
        ->and(Setting::get('letters.school_code'))->toBe('ALK')
        ->and(Setting::get('letters.type_codes')['pindah'])->toBe('SPD')
        ->and((int) Setting::get('letters.sequence_padding'))->toBe(4)
        ->and(app(LetterNumbering::class)->format('pindah', CarbonImmutable::parse('2026-10-15'), 7))->toBe('SPD-0007/ALK/10/2026');

    $activities = dokumenActivities();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()->event)->toBe('numbering_updated')
        ->and($activities->first()->causer_id)->toBe($f->admin->id)
        ->and($activities->first()->properties->keys()->all())->toContain('old', 'new');
});

it('AC-8: keeps personal data out of the numbering activity', function () {
    suratIssue($this->f);

    app(UpdateLetterNumbering::class)->handle(suratNumberingData(['school_code' => 'ALK']), $this->f->admin);

    $json = json_encode(dokumenActivities()->where('event', 'numbering_updated')->first()->properties);

    expect($json)->not->toContain('Fauzan')->not->toContain('2026000123');
});

it('AC-8: rejects invalid numbering settings', function (array $overrides, string $reason, string $field) {
    $before = suratStoredNumbering();

    suratAssertRejected(fn () => app(UpdateLetterNumbering::class)->handle(suratNumberingData($overrides), $this->f->admin), $reason, $field);

    expect(suratStoredNumbering())->toEqual($before)
        ->and(dokumenActivities())->toHaveCount(0);
})->with([
    'unknown token' => [['number_format' => '{seq}/{foo}/{type_code}/{year}'], 'format_token_unknown', 'number_format'],
    'missing seq' => [['number_format' => '{school_code}/{type_code}/{year}'], 'format_token_missing', 'number_format'],
    'missing type code' => [['number_format' => '{seq}/{school_code}/{year}'], 'format_token_missing', 'number_format'],
    'missing year' => [['number_format' => '{seq}/{school_code}/{type_code}'], 'format_token_missing', 'number_format'],
    'space in format' => [['number_format' => '{seq} {type_code}/{year}'], 'format_character_invalid', 'number_format'],
    'hash in format' => [['number_format' => '{seq}/{type_code}/#/{year}'], 'format_character_invalid', 'number_format'],
    'format too long' => [['number_format' => str_repeat('A', 45).'/{seq}/{type_code}/{year}'], 'format_too_long', 'number_format'],
    'school code with space' => [['school_code' => 'MA K'], 'code_invalid', 'school_code'],
    'school code too long' => [['school_code' => 'ABCDEFGHIJK'], 'code_invalid', 'school_code'],
    'duplicate type codes' => [['type_codes' => ['pindah' => 'SKA']], 'type_codes_not_unique', 'type_codes'],
    'duplicate after upper-casing' => [['type_codes' => ['pindah' => 'ska']], 'type_codes_not_unique', 'type_codes'],
    'type code with space' => [['type_codes' => ['pindah' => 'S P']], 'code_invalid', 'type_codes'],
    'type code too long' => [['type_codes' => ['pindah' => 'ABCDEFGHIJK']], 'code_invalid', 'type_codes'],
    'padding zero' => [['sequence_padding' => 0], 'padding_invalid', 'sequence_padding'],
    'padding seven' => [['sequence_padding' => 7], 'padding_invalid', 'sequence_padding'],
]);

it('AC-8: accepts the boundary values', function () {
    app(UpdateLetterNumbering::class)->handle(suratNumberingData([
        'number_format' => '{seq}.{type_code}_{year}-{month}',
        'school_code' => 'AB.CD-1234',
        'sequence_padding' => 6,
    ]), $this->f->admin);

    expect(Setting::get('letters.school_code'))->toBe('AB.CD-1234')
        ->and((int) Setting::get('letters.sequence_padding'))->toBe(6);

    app(UpdateLetterNumbering::class)->handle(suratNumberingData(['sequence_padding' => 1]), $this->f->admin);

    expect((int) Setting::get('letters.sequence_padding'))->toBe(1);
});

it('AC-8: locks the type code once a letter of that type exists this year', function () {
    $f = $this->f;
    suratIssue($f);
    $before = suratStoredNumbering();

    suratAssertRejected(fn () => app(UpdateLetterNumbering::class)->handle(suratNumberingData(['type_codes' => ['keterangan_aktif' => 'SKX']]), $f->admin), 'type_code_locked', 'type_codes');

    expect(suratStoredNumbering())->toEqual($before);
});

it('AC-8: locks the type code when the only letter this year is cancelled', function () {
    $f = $this->f;
    app(CancelIssuedLetter::class)->handle(suratIssue($f), $f->admin, 'Salah keperluan');

    suratAssertRejected(fn () => app(UpdateLetterNumbering::class)->handle(suratNumberingData(['type_codes' => ['keterangan_aktif' => 'SKX']]), $f->admin), 'type_code_locked', 'type_codes');
});

it('AC-8: still allows changing the code of a type without letters this year', function () {
    suratIssue($this->f);

    app(UpdateLetterNumbering::class)->handle(suratNumberingData(['type_codes' => ['syahadah' => 'SYX']]), $this->f->admin);

    expect(Setting::get('letters.type_codes')['syahadah'])->toBe('SYX')
        ->and(Setting::get('letters.type_codes')['keterangan_aktif'])->toBe('SKA');
});

it('AC-8: does not lock the code because of a letter from last year', function () {
    $f = $this->f;
    $this->travelTo(CarbonImmutable::parse('2025-06-01 10:00', 'Asia/Jakarta'));
    suratIssue($f);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));

    app(UpdateLetterNumbering::class)->handle(suratNumberingData(['type_codes' => ['keterangan_aktif' => 'SKX']]), $f->admin);

    expect(Setting::get('letters.type_codes')['keterangan_aktif'])->toBe('SKX');
});

it('AC-8: locks swapped type codes when both types have letters this year', function () {
    $f = $this->f;
    suratIssue($f);
    suratIssue($f, ['type' => 'rekomendasi']);

    suratAssertRejected(fn () => app(UpdateLetterNumbering::class)->handle(suratNumberingData(['type_codes' => ['keterangan_aktif' => 'SRK', 'rekomendasi' => 'SKA']]), $f->admin), 'type_code_locked', 'type_codes');
});

it('AC-8: keeps letter numbers of issued letters when the settings change', function () {
    $f = $this->f;
    $letter = suratIssue($f);

    app(UpdateLetterNumbering::class)->handle(suratNumberingData(['number_format' => '{type_code}-{seq}/{year}', 'sequence_padding' => 5, 'school_code' => 'ALK']), $f->admin);

    expect($letter->fresh()->letter_no)->toBe('001/MAK/SKA/X/2026');
});

it('AC-9: normalises school codes', function () {
    app(UpdateLetterNumbering::class)->handle(suratNumberingData(['school_code' => 'alk']), $this->f->admin);

    expect(Setting::get('letters.school_code'))->toBe('ALK');
});

it('AC-9: trims the school code and stores an empty one as null so the derived code is used', function (?string $input) {
    app(UpdateLetterNumbering::class)->handle(suratNumberingData(['school_code' => 'ALK']), $this->f->admin);
    app(UpdateLetterNumbering::class)->handle(suratNumberingData(['school_code' => $input]), $this->f->admin);

    expect(Setting::get('letters.school_code'))->toBeNull()
        ->and(app(LetterNumbering::class)->format('keterangan_aktif', CarbonImmutable::parse('2026-10-15'), 1))->toBe('001/MAK/SKA/X/2026');
})->with(['empty' => [''], 'spaces' => ['   '], 'null' => [null]]);
