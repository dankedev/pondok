<?php

use App\Actions\Dokumen\CancelIssuedLetter;
use App\Actions\Dokumen\IssueLetter;
use App\Actions\Dokumen\UpdateLetterNumbering;
use App\Models\AcademicYear;
use App\Models\IssuedLetter;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->f = suratFixture();
});

/**
 * Banyaknya surat di seluruh jenjang (tanpa cakupan).
 */
function suratCount(): int
{
    return IssuedLetter::withoutGlobalScopes()->count();
}

it('AC-10: issues a letter with a snapshot', function () {
    $f = $this->f;

    $letter = suratIssue($f);
    $stored = IssuedLetter::withoutGlobalScopes()->findOrFail($letter->id);

    expect($stored->status)->toBe('terbit')
        ->and($stored->letter_no)->toBe('001/MAK/SKA/X/2026')
        ->and($stored->type)->toBe('keterangan_aktif')
        ->and($stored->sequence)->toBe(1)
        ->and($stored->sequence_year)->toBe(2026)
        ->and($stored->issued_on->toDateString())->toBe('2026-10-15')
        ->and($stored->issued_by)->toBe($f->admin->id)
        ->and($stored->student_id)->toBe($f->s1->id)
        ->and($stored->signed_by_staff_id)->toBe($f->t1->id)
        ->and($stored->purpose)->toBe('Persyaratan beasiswa')
        ->and($stored->verification_code)->toMatch('/^['.IssuedLetter::CODE_ALPHABET.']{12}$/')
        ->and($stored->file_path)->toBeNull()
        ->and($stored->snapshot['student'])->toMatchArray([
            'name' => 'Muhammad Fauzan Akbar',
            'nis' => '2026000123',
            'nisn' => '0098765432',
            'gender' => 'L',
            'birth_place' => 'Bandung',
            'birth_date' => '2014-03-02',
            'unit_name' => $f->sd->name,
            'classroom_name' => '4A',
            'academic_year_name' => '2026/2027',
            'status' => 'aktif',
        ])
        ->and($stored->snapshot['signers'])->toEqual([['label' => 'Penandatangan', 'name' => 'Ust. Hasan', 'position' => 'Kepala Sekolah', 'nip' => '1987']])
        ->and($stored->snapshot)->not->toHaveKey('signer')
        ->and($stored->snapshot['school']['name'])->toBe("Ma'had Al-Khair");
});

it('AC-10: logs one activity without personal data', function () {
    $letter = suratIssue($this->f);
    $activities = dokumenActivities();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()->event)->toBe('issued')
        ->and($activities->first()->getExtraProperty('letter_no'))->toBe('001/MAK/SKA/X/2026')
        ->and($activities->first()->getExtraProperty('type'))->toBe('keterangan_aktif')
        ->and($letter->letter_no)->toBe('001/MAK/SKA/X/2026');

    $json = json_encode($activities->first()->properties);

    expect($json)->not->toContain('Fauzan')->not->toContain('2026000123')->not->toContain('0098765432')->not->toContain('Bandung');
});

it('AC-10: stores the verification code of every letter uniquely', function () {
    $f = $this->f;
    $codes = collect(range(1, 5))->map(fn (): string => suratIssue($f)->verification_code);

    expect($codes->unique()->count())->toBe(5);
});

it('AC-11: numbers letters uniquely and sequentially', function () {
    $f = $this->f;

    $numbers = collect(range(1, 3))->map(fn (): string => suratIssue($f)->letter_no)->all();
    $other = suratIssue($f, ['type' => 'rekomendasi']);

    expect($numbers)->toBe(['001/MAK/SKA/X/2026', '002/MAK/SKA/X/2026', '003/MAK/SKA/X/2026'])
        ->and($other->letter_no)->toBe('001/MAK/SRK/X/2026');
});

it('AC-11: never reuses the number of a cancelled letter', function () {
    $f = $this->f;
    $letters = collect(range(1, 3))->map(fn () => suratIssue($f));

    app(CancelIssuedLetter::class)->handle($letters->last(), $f->admin, 'Salah keperluan');

    expect(suratIssue($f)->letter_no)->toBe('004/MAK/SKA/X/2026')
        ->and(IssuedLetter::withoutGlobalScopes()->where('letter_no', '003/MAK/SKA/X/2026')->value('status'))->toBe('batal');
});

it('AC-11: restarts the sequence every calendar year', function () {
    $f = $this->f;
    suratIssue($f);
    suratIssue($f);

    $this->travelTo(CarbonImmutable::parse('2027-01-04 10:00', 'Asia/Jakarta'));
    $next = suratIssue($f);

    expect($next->letter_no)->toBe('001/MAK/SKA/I/2027')
        ->and($next->sequence_year)->toBe(2027)
        ->and($next->issued_on->toDateString())->toBe('2027-01-04');
});

it('AC-11: takes the year and date from Asia/Jakarta, not from UTC', function () {
    $f = $this->f;
    $this->travelTo(CarbonImmutable::parse('2026-12-31 20:00', 'UTC'));

    $letter = suratIssue($f);

    expect($letter->letter_no)->toBe('001/MAK/SKA/I/2027')
        ->and($letter->sequence_year)->toBe(2027)
        ->and($letter->issued_on->toDateString())->toBe('2027-01-01');
});

it('AC-11: continues the sequence for a unit-scoped issuer even when the highest number belongs to another unit', function () {
    $f = $this->f;
    suratIssue($f);
    suratIssue($f);

    $letter = suratIssue($f, student: $f->s2, by: $f->scoped);

    expect($letter->letter_no)->toBe('003/MAK/SKA/X/2026')
        ->and($letter->sequence)->toBe(3);
});

it('AC-11: keeps numbering types independent of each other', function () {
    $f = $this->f;
    suratIssue($f);
    suratIssue($f, ['type' => 'pindah']);

    expect(suratIssue($f, ['type' => 'pindah'])->letter_no)->toBe('002/MAK/SKP/X/2026')
        ->and(suratIssue($f)->letter_no)->toBe('002/MAK/SKA/X/2026');
});

it('AC-12: freezes letter content', function () {
    $f = $this->f;
    $letter = suratIssue($f);
    $snapshot = IssuedLetter::withoutGlobalScopes()->find($letter->id)->snapshot;

    $f->s1->forceFill(['name' => 'Zainal Abidin', 'nis' => '9999'])->save();
    $f->t1->forceFill(['name' => 'Ust. Baru', 'position' => 'Wakil'])->save();
    Setting::set('school.name', 'Pondok Lain');

    $html = $this->actingAs($f->admin)->get("/admin/letters/{$letter->id}/print")->assertOk()->getContent();
    $text = suratText($html);

    expect(IssuedLetter::withoutGlobalScopes()->find($letter->id)->snapshot)->toEqual($snapshot)
        ->and($text)->toContain('Muhammad Fauzan Akbar')->toContain('2026000123')->toContain('Ust. Hasan')->toContain('Kepala Sekolah')->toContain("Ma'had Al-Khair")
        ->not->toContain('Zainal')->not->toContain('Ust. Baru')->not->toContain('Pondok Lain');
});

it('AC-12: does not renumber old letters when the numbering settings change', function () {
    $f = $this->f;
    $letter = suratIssue($f);

    app(UpdateLetterNumbering::class)->handle(suratNumberingData(['number_format' => '{type_code}-{seq}/{year}', 'school_code' => 'ALK', 'sequence_padding' => 5]), $f->admin);

    expect($letter->fresh()->letter_no)->toBe('001/MAK/SKA/X/2026')
        ->and(suratIssue($f)->letter_no)->toBe('SKA-00002/2026');
});

it('AC-13: rejects invalid letters', function (string $case, string $reason, string $field) {
    $f = $this->f;

    suratAssertRejected(function () use ($case, $f) {
        match ($case) {
            'unknown type' => suratIssue($f, ['type' => 'surat_cinta']),
            'archived student' => (function () use ($f) {
                $f->s1->delete();

                return suratIssue($f);
            })(),
            'student of another unit' => suratIssue($f, by: $f->scoped),
            'graduated, keterangan aktif' => (function () use ($f) {
                $f->s1->forceFill(['status' => 'lulus'])->save();

                return suratIssue($f);
            })(),
            'graduated, kartu santri' => (function () use ($f) {
                $f->s1->forceFill(['status' => 'lulus'])->save();

                return suratIssue($f, ['type' => 'kartu_santri']);
            })(),
            'left, pindah' => (function () use ($f) {
                $f->s1->forceFill(['status' => 'keluar'])->save();

                return suratIssue($f, ['type' => 'pindah']);
            })(),
            'graduated, pindah' => (function () use ($f) {
                $f->s1->forceFill(['status' => 'lulus'])->save();

                return suratIssue($f, ['type' => 'pindah']);
            })(),
            'left, rekomendasi' => (function () use ($f) {
                $f->s1->forceFill(['status' => 'keluar'])->save();

                return suratIssue($f, ['type' => 'rekomendasi']);
            })(),
            'moved, syahadah' => (function () use ($f) {
                $f->s1->forceFill(['status' => 'pindah'])->save();

                return suratIssue($f, ['type' => 'syahadah']);
            })(),
            'inactive signer' => suratIssue($f, ['signers' => [$f->t2->id]]),
            'unknown signer' => suratIssue($f, ['signers' => [999999]]),
            'purpose too long' => suratIssue($f, ['purpose' => str_repeat('a', 256)]),
        };
    }, $reason, $field);

    expect(suratCount())->toBe(0)
        ->and(dokumenActivities())->toHaveCount(0);
})->with([
    'unknown type' => ['unknown type', 'letter_type_invalid', 'type'],
    'archived student' => ['archived student', 'student_archived', 'student'],
    'student of another unit' => ['student of another unit', 'student_not_found', 'student'],
    'graduated, keterangan aktif' => ['graduated, keterangan aktif', 'student_status_not_allowed', 'student'],
    'graduated, kartu santri' => ['graduated, kartu santri', 'student_status_not_allowed', 'student'],
    'left, pindah' => ['left, pindah', 'student_status_not_allowed', 'student'],
    'graduated, pindah' => ['graduated, pindah', 'student_status_not_allowed', 'student'],
    'left, rekomendasi' => ['left, rekomendasi', 'student_status_not_allowed', 'student'],
    'moved, syahadah' => ['moved, syahadah', 'student_status_not_allowed', 'student'],
    'inactive signer' => ['inactive signer', 'signer_invalid', 'signers.0.staff_id'],
    'unknown signer' => ['unknown signer', 'signer_invalid', 'signers.0.staff_id'],
    'purpose too long' => ['purpose too long', 'purpose_too_long', 'purpose'],
]);

it('AC-13: requires a purpose except for the student card', function (string $type, string $purpose) {
    $f = $this->f;

    suratAssertRejected(fn () => suratIssue($f, ['type' => $type, 'purpose' => $purpose]), 'purpose_required', 'purpose');

    expect(suratCount())->toBe(0)
        ->and(dokumenActivities())->toHaveCount(0);
})->with(['keterangan_aktif', 'pindah', 'rekomendasi', 'syahadah'])->with(['empty' => [''], 'only spaces' => ['   ']]);

it('AC-13: requires a purpose when the key is missing', function () {
    $f = $this->f;

    suratAssertRejected(fn () => app(IssueLetter::class)->handle($f->s1, ['type' => 'pindah', 'signers' => [$f->t1->id]], $f->admin), 'purpose_required', 'purpose');
});

it('AC-13: issues recommendations for graduates and transfer letters for students who moved', function () {
    $f = $this->f;
    $f->s1->forceFill(['status' => 'lulus'])->save();

    $recommendation = suratIssue($f, ['type' => 'rekomendasi']);
    $f->s1->forceFill(['status' => 'pindah'])->save();
    $transfer = suratIssue($f, ['type' => 'pindah']);

    expect($recommendation->letter_no)->toBe('001/MAK/SRK/X/2026')
        ->and($transfer->letter_no)->toBe('001/MAK/SKP/X/2026')
        ->and($recommendation->fresh()->snapshot['student']['status'])->toBe('lulus');
});

it('AC-13: accepts a purpose of exactly 255 characters and trims it', function () {
    $f = $this->f;

    expect(suratIssue($f, ['purpose' => str_repeat('a', 255)])->purpose)->toBe(str_repeat('a', 255))
        ->and(suratIssue($f, ['purpose' => '  Beasiswa  '])->purpose)->toBe('Beasiswa');
});

it('AC-14: issues a student card without purpose', function () {
    $letter = suratIssue($this->f, ['type' => 'kartu_santri', 'purpose' => 'apa saja']);

    expect($letter->letter_no)->toBe('001/MAK/KTS/X/2026')
        ->and(IssuedLetter::withoutGlobalScopes()->find($letter->id)->purpose)->toBeNull();
});

it('AC-14: issues a student card when no purpose is given at all', function () {
    $f = $this->f;
    $letter = app(IssueLetter::class)->handle($f->s1, ['type' => 'kartu_santri', 'signers' => [$f->t1->id]], $f->admin);

    expect($letter->letter_no)->toBe('001/MAK/KTS/X/2026')->and($letter->purpose)->toBeNull();
});

it('AC-15: retries when the sequence collides with an existing letter', function () {
    $f = $this->f;
    suratIssue($f);
    $numbering = suratFakeNumbering([1]);

    $letter = suratIssue($f);

    expect($letter->letter_no)->toBe('002/MAK/SKA/X/2026')
        ->and($numbering->calls)->toBe(2)
        ->and(suratCount())->toBe(2)
        ->and(dokumenActivities()->where('event', 'issued'))->toHaveCount(2);
});

it('AC-15: gives up with number_conflict after three attempts', function () {
    $f = $this->f;
    suratIssue($f);
    $numbering = suratFakeNumbering(always: 1);
    Log::spy();

    suratAssertRejected(fn () => suratIssue($f), 'number_conflict', 'letter_no');

    expect($numbering->calls)->toBe(3)
        ->and(suratCount())->toBe(1)
        ->and(dokumenActivities())->toHaveCount(1);

    Log::shouldHaveReceived('error')->once();
});

it('AC-15: retries when the verification code collides', function () {
    $f = $this->f;
    $existing = suratIssue($f);
    $issuer = suratFakeIssuer([$existing->verification_code]);

    $letter = suratIssue($f);

    expect($issuer->calls)->toBe(2)
        ->and($letter->verification_code)->not->toBe($existing->verification_code)
        ->and($letter->letter_no)->toBe('002/MAK/SKA/X/2026')
        ->and(suratCount())->toBe(2);
});

it('AC-15: retries after a deadlock', function () {
    $f = $this->f;
    $numbering = suratFakeNumbering([suratDeadlock()]);

    $letter = suratIssue($f);

    expect($letter->letter_no)->toBe('001/MAK/SKA/X/2026')
        ->and($numbering->calls)->toBe(2)
        ->and(suratCount())->toBe(1)
        ->and(dokumenActivities())->toHaveCount(1);
});

it('AC-15: rethrows other database errors without a second attempt', function () {
    $f = $this->f;
    $numbering = suratFakeNumbering([suratPlainQueryException()]);

    expect(fn () => suratIssue($f))->toThrow(QueryException::class)
        ->and($numbering->calls)->toBe(1)
        ->and(suratCount())->toBe(0)
        ->and(dokumenActivities())->toHaveCount(0);
});

it('AC-40: falls back when the school profile is empty', function () {
    $f = $this->f;
    Setting::query()->where('key', 'like', 'school.%')->get()->each->delete();

    $letter = suratIssue($f);

    expect($letter->fresh()->snapshot['school'])->toEqual(['name' => config('app.name'), 'address' => null, 'phone' => null]);
});

it('AC-40: freezes the school address and phone when they are set', function () {
    Setting::set('school.address', 'Jl. Pesantren No. 1');
    Setting::set('school.contacts', [profilContact(['phone' => '0812-0000-1111', 'is_print' => true])]);

    $letter = suratIssue($this->f);

    expect($letter->fresh()->snapshot['school'])->toEqual(['name' => "Ma'had Al-Khair", 'address' => 'Jl. Pesantren No. 1', 'phone' => '0812-0000-1111']);
});

it('AC-41: leaves the classroom empty for a student without a classroom this year', function () {
    $f = $this->f;
    $student = suratStudent($f, attributes: ['name' => 'Tanpa Rombel'], enrolled: false);

    $letter = suratIssue($f, student: $student);

    expect($letter->fresh()->snapshot['student']['classroom_name'])->toBeNull()
        ->and($letter->fresh()->snapshot['student']['academic_year_name'])->toBe('2026/2027')
        ->and($letter->fresh()->snapshot['student']['name'])->toBe('Tanpa Rombel');
});

it('AC-41: leaves classroom and academic year empty without an active academic year', function () {
    $f = $this->f;
    AcademicYear::query()->update(['is_active' => false]);

    $letter = suratIssue($f);

    expect($letter->fresh()->snapshot['student']['classroom_name'])->toBeNull()
        ->and($letter->fresh()->snapshot['student']['academic_year_name'])->toBeNull()
        ->and($letter->fresh()->snapshot['student']['nis'])->toBe('2026000123');
});

it('AC-3 (F044): replacing the print contact leaves an issued letter snapshot unchanged but new letters use the new phone', function () {
    Setting::set('school.address', 'Jl. Pesantren No. 1');
    Setting::set('school.contacts', [profilContact(['key' => 'aaaa1111', 'phone' => '081200001111', 'is_print' => true])]);
    $old = suratIssue($this->f);
    $before = $old->fresh()->snapshot['school'];

    Setting::set('school.contacts', [profilContact(['key' => 'aaaa1111', 'phone' => '081200001111']), profilContact(['key' => 'bbbb2222', 'phone' => '081200002222', 'is_print' => true])]);
    $new = suratIssue($this->f);

    expect($old->fresh()->snapshot['school'])->toEqual($before)->and($before['phone'])->toBe('081200001111')
        ->and($new->fresh()->snapshot['school']['phone'])->toBe('081200002222');
});

it('AC-2 (F044): leaves the phone null when no contact is marked for print', function () {
    Setting::set('school.contacts', [profilContact(['phone' => '081200007777'])]);

    expect(suratIssue($this->f)->fresh()->snapshot['school']['phone'])->toBeNull();
});
