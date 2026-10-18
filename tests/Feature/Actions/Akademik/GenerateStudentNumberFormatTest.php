<?php

use App\Actions\Akademik\GenerateStudentNumber;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->f = daftarUlangFixture();
});

it('F039: resolves, previews and validates the NIS format', function () {
    $action = app(GenerateStudentNumber::class);

    expect($action->resolvedFormat())->toBe('{YYYY}{NNNN}')
        ->and($action->preview('{UNIT}-{YY}-{NNN}', $this->f->sltp, 2027))->toBe('SLTP-27-001')
        ->and($action->validateFormat(' {UNIT}-{NNN} '))->toBe('{UNIT}-{NNN}');
});

it('F039: rejects an NIS format without a valid trailing sequence', function (string $format) {
    expect(fn () => app(GenerateStudentNumber::class)->validateFormat($format))->toThrow(ValidationException::class);
})->with([
    'no sequence' => ['{YYYY}'],
    'one N' => ['{UNIT}-{N}'],
    'unknown token' => ['{FOO}{NNNN}'],
]);
