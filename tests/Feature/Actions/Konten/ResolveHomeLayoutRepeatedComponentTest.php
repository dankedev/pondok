<?php

use App\Actions\Konten\ResolveHomeLayout;

function rhBlock(string $type, string $text): array
{
    return ['type' => $type, 'data' => ['text' => $text]];
}

function rhTheme(array $components): array
{
    return ['home' => ['layout' => array_map(fn (string $component): array => ['component' => $component], $components)]];
}

/**
 * @return list<string>
 */
function rhTexts(array $result): array
{
    return array_map(fn (array $node): string => $node['block']['data']['text'], $result['nodes']);
}

it('F114: the nth node of a type uses the nth block of that type', function () {
    $blocks = [rhBlock('quote', 'satu'), rhBlock('video', 'v'), rhBlock('quote', 'dua')];

    $result = app(ResolveHomeLayout::class)->handle($blocks, rhTheme(['quote', 'video', 'quote']));

    expect(rhTexts($result))->toBe(['satu', 'v', 'dua']);
});

it('F114: a repeated node without a matching block falls back to the first block (F102 contract)', function () {
    $result = app(ResolveHomeLayout::class)->handle([rhBlock('quote', 'satu')], rhTheme(['quote', 'quote']));

    expect(rhTexts($result))->toBe(['satu', 'satu']);
});

it('F114: a single node still uses the first block even when the admin has more', function () {
    $result = app(ResolveHomeLayout::class)->handle([rhBlock('quote', 'satu'), rhBlock('quote', 'dua')], rhTheme(['quote']));

    expect(rhTexts($result))->toBe(['satu']);
});

it('F114: repeated nodes nested in a container also take the next block', function () {
    $theme = ['home' => ['layout' => [
        ['component' => 'quote'],
        ['component' => 'container', 'children' => [['component' => 'quote']]],
    ]]];

    $result = app(ResolveHomeLayout::class)->handle([rhBlock('quote', 'satu'), rhBlock('quote', 'dua')], $theme);

    expect($result['nodes'][0]['block']['data']['text'])->toBe('satu')
        ->and($result['nodes'][1]['children'][0]['block']['data']['text'])->toBe('dua');
});
