<?php

use App\Actions\Keuangan\GenerateInvoiceNumber;
use App\Actions\Keuangan\GenerateReceiptNumber;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->f = tagihanFixture(withRates: false);
});

it('F039: resolves the default invoice format', function () {
    Setting::query()->where('key', GenerateInvoiceNumber::SETTING_KEY)->delete();
    $action = app(GenerateInvoiceNumber::class);

    expect(GenerateInvoiceNumber::DEFAULT_FORMAT)->toBe('INV-{YYYY}{MM}-{NNNNN}')
        ->and($action->resolvedFormat())->toBe('INV-{YYYY}{MM}-{NNNNN}')
        ->and($action->format(CarbonImmutable::parse('2026-10-01'), 1))->toBe('INV-202610-00001');
});

it('F039: follows a configured invoice format', function () {
    Setting::set(GenerateInvoiceNumber::SETTING_KEY, 'TGH/{YY}/{NNNN}');
    $action = app(GenerateInvoiceNumber::class);

    expect($action->resolvedFormat())->toBe('TGH/{YY}/{NNNN}')
        ->and($action->format(CarbonImmutable::parse('2026-10-01'), 7))->toBe('TGH/26/0007')
        ->and($action->preview('TGH/{YY}/{NNNN}', CarbonImmutable::parse('2026-10-01')))->toBe('TGH/26/0001');
});

it('F039: continues the sequence for the configured invoice prefix', function () {
    Setting::set(GenerateInvoiceNumber::SETTING_KEY, 'TGH/{YY}/{NNNN}');
    tagihanInvoice(tagihanStudent($this->f), $this->f->year, ['invoice_no' => 'TGH/26/0009']);

    $action = app(GenerateInvoiceNumber::class);

    expect($action->lastSequence(CarbonImmutable::parse('2026-10-01')))->toBe(9)
        ->and($action->handle(CarbonImmutable::parse('2026-10-01')))->toBe('TGH/26/0010');
});

it('F039: validates the invoice format', function (string $format, bool $valid) {
    $action = app(GenerateInvoiceNumber::class);

    if ($valid) {
        expect($action->validateFormat($format))->toBe($format);
    } else {
        expect(fn () => $action->validateFormat($format))->toThrow(ValidationException::class);
    }
})->with([
    'default' => ['INV-{YYYY}{MM}-{NNNNN}', true],
    'short' => ['{YY}/{NNN}', true],
    'no sequence' => ['INV-{YYYY}', false],
    'sequence not last' => ['INV-{NNNN}{YYYY}', false],
    'unknown token' => ['INV-{FOO}{NNNN}', false],
    'space' => ['INV {YYYY} {NNNN}', false],
]);

it('F039: validates invoice formats statically', function (string $format, bool $valid) {
    expect(GenerateInvoiceNumber::isValidFormat($format))->toBe($valid);
})->with([
    'default' => ['INV-{YYYY}{MM}-{NNNNN}', true],
    'two N' => ['{YYYY}{NN}', false],
    'seven N' => ['{YYYY}{NNNNNNN}', false],
    'underscore' => ['{YYYY}_{NNNN}', false],
]);

it('F039: falls back to the default invoice format and warns', function () {
    Setting::set(GenerateInvoiceNumber::SETTING_KEY, 'INV-{FOO}');
    Log::spy();

    expect(app(GenerateInvoiceNumber::class)->format(CarbonImmutable::parse('2026-10-01'), 1))->toBe('INV-202610-00001');
    Log::shouldHaveReceived('warning')->atLeast()->once();
});

it('F039: follows a configured receipt format', function () {
    Setting::set(GenerateReceiptNumber::SETTING_KEY, 'KT/{YY}/{NNNN}');
    $action = app(GenerateReceiptNumber::class);

    expect($action->resolvedFormat())->toBe('KT/{YY}/{NNNN}')
        ->and($action->format(CarbonImmutable::parse('2026-10-01'), 1))->toBe('KT/26/0001');
});

it('F039: refuses receipt sequences beyond the configured width', function () {
    Setting::set(GenerateReceiptNumber::SETTING_KEY, 'KT/{NNN}');

    expect(fn () => app(GenerateReceiptNumber::class)->format(CarbonImmutable::parse('2026-10-01'), 1000))
        ->toThrow(LogicException::class);
});
