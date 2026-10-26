<?php

namespace App\Enums;

use InvalidArgumentException;

/**
 * Predikat nilai diniyah (F027), dihitung dari nilai 0-100 dan tidak disimpan.
 */
enum DiniyahPredicate: string
{
    case Mumtaz = 'mumtaz';
    case JayyidJiddan = 'jayyid_jiddan';
    case Jayyid = 'jayyid';
    case Maqbul = 'maqbul';
    case Rasib = 'rasib';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score < 0, $score > 100 => throw new InvalidArgumentException("Nilai diniyah harus 0-100, diterima {$score}."),
            $score >= 90 => self::Mumtaz,
            $score >= 80 => self::JayyidJiddan,
            $score >= 70 => self::Jayyid,
            $score >= 60 => self::Maqbul,
            default => self::Rasib,
        };
    }

    public function label(): string
    {
        return __("pondok.diniyah.predicates.{$this->value}");
    }
}
