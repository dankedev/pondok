<?php

use App\Enums\DiniyahPredicate;

it('AC-2: maps every score boundary to its predicate', function () {
    $expected = [
        0 => 'rasib', 59 => 'rasib',
        60 => 'maqbul', 69 => 'maqbul',
        70 => 'jayyid', 79 => 'jayyid',
        80 => 'jayyid_jiddan', 89 => 'jayyid_jiddan',
        90 => 'mumtaz', 100 => 'mumtaz',
    ];

    foreach ($expected as $score => $value) {
        expect(DiniyahPredicate::fromScore($score)->value)->toBe($value, "nilai {$score}");
    }
});

it('AC-2: rejects a score outside 0-100', function () {
    foreach ([-1, 101, 1000] as $score) {
        expect(fn () => DiniyahPredicate::fromScore($score))->toThrow(InvalidArgumentException::class);
    }
});
