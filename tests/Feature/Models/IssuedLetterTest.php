<?php

use App\Models\IssuedLetter;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->f = suratFixture();
});

it('AC-3: generates unambiguous verification codes', function () {
    $codes = collect(range(1, 200))->map(fn (): string => IssuedLetter::newVerificationCode());

    expect(IssuedLetter::CODE_ALPHABET)->toBe('23456789ABCDEFGHJKMNPQRSTUVWXYZ')
        ->and(IssuedLetter::CODE_LENGTH)->toBe(12)
        ->and($codes->every(fn (string $code): bool => strlen($code) === 12 && preg_match('/^['.IssuedLetter::CODE_ALPHABET.']{12}$/', $code) === 1))->toBeTrue()
        ->and($codes->unique()->count())->toBe(200)
        ->and($codes->contains(fn (string $code): bool => preg_match('/[01ILO]/', $code) === 1))->toBeFalse();
});

it('AC-3: normalises and formats verification codes', function () {
    $letter = (new IssuedLetter)->forceFill(['verification_code' => 'ABCDEFGHJKMN']);

    expect(IssuedLetter::normalizeVerificationCode(' abcd-efgh jkmn '))->toBe('ABCDEFGHJKMN')
        ->and($letter->formattedVerificationCode())->toBe('ABCD-EFGH-JKMN');
});

it('AC-3: builds an absolute verification url', function () {
    $letter = (new IssuedLetter)->forceFill(['verification_code' => 'ABCDEFGHJKMN']);

    expect($letter->verificationUrl())->toBe(rtrim((string) config('app.url'), '/').'/verifikasi-surat/ABCDEFGHJKMN');
});

it('AC-4: masks student names', function (string $name, string $expected) {
    expect(IssuedLetter::maskName($name))->toBe($expected);
})->with([
    'three words' => ['Muhammad Fauzan Akbar', 'Muhammad F. A.'],
    'one word' => ['Fauzan', 'Fauzan'],
    'two words' => ['Siti Aisyah', 'Siti A.'],
    'extra spaces' => ['  Muhammad   Fauzan  Akbar ', 'Muhammad F. A.'],
]);

it('AC-4: masks the name from the snapshot, not from the student', function () {
    $letter = (new IssuedLetter)->forceFill(['snapshot' => ['student' => ['name' => 'Muhammad Fauzan Akbar']]]);
    $letter->setRelation('student', $this->f->s2);

    expect($letter->maskedStudentName())->toBe('Muhammad F. A.');
});

it('AC-4: maps statuses to colours', function () {
    expect(IssuedLetter::STATUS_COLORS)->toBe(['terbit' => 'success', 'batal' => 'danger'])
        ->and(IssuedLetter::STATUS_ISSUED)->toBe('terbit')
        ->and(IssuedLetter::STATUS_CANCELLED)->toBe('batal');
});

it('AC-5: scopes letters by unit', function () {
    $f = $this->f;
    $own = suratIssue($f, student: $f->s2);
    $other = suratIssue($f);

    $this->actingAs($f->scoped);

    expect(IssuedLetter::query()->pluck('id')->all())->toBe([$own->id]);

    $this->actingAs($f->admin);

    expect(IssuedLetter::query()->pluck('id')->sort()->values()->all())->toBe([$own->id, $other->id]);

    Auth::forgetGuards();

    expect(IssuedLetter::query()->count())->toBe(2);
});

it('AC-5: keeps letters of an archived student visible to the scoped user', function () {
    $f = $this->f;
    $letter = suratIssue($f, student: $f->s2);
    $f->s2->delete();

    $this->actingAs($f->scoped);

    expect(IssuedLetter::query()->pluck('id')->all())->toBe([$letter->id]);
});
