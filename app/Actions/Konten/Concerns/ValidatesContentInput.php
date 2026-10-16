<?php

namespace App\Actions\Konten\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Validasi bersama Action konten. Galat memakai pesan `pondok.konten.errors.<reason>`
 * pada field terkait dan berhenti di galat pertama.
 */
trait ValidatesContentInput
{
    /**
     * @param  array<string, mixed>  $replace
     */
    protected function fail(string $field, string $reason, array $replace = []): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.konten.errors.{$reason}", $replace),
        ]);
    }

    /**
     * Teks wajib (di-trim): kosong → `$requiredReason`, lebih dari `$max` karakter → `too_long`.
     */
    protected function requiredText(mixed $value, string $field, int $max, string $requiredReason = 'title_required'): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        if ($text === '') {
            $this->fail($field, $requiredReason);
        }

        return $this->limit($text, $field, $max);
    }

    /**
     * Teks polos opsional: tag HTML dibuang dan di-trim; kosong → null.
     */
    protected function plainText(mixed $value, string $field, int $max): ?string
    {
        $text = is_scalar($value) ? trim(strip_tags((string) $value)) : '';

        return $text === '' ? null : $this->limit($text, $field, $max);
    }

    /**
     * Teks opsional apa adanya (di-trim); kosong → null.
     */
    protected function optionalText(mixed $value, string $field, int $max): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? null : $this->limit($text, $field, $max);
    }

    /**
     * Bilangan bulat 0–`$max`; kosong → `$default`.
     */
    protected function sortOrder(mixed $value, int $max, int $default = 0, string $field = 'sort_order'): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $number = filter_var(is_string($value) ? trim($value) : $value, FILTER_VALIDATE_INT);

        if ($number === false || $number < 0 || $number > $max) {
            $this->fail($field, 'sort_order_invalid', ['max' => $max]);
        }

        return $number;
    }

    private function limit(string $text, string $field, int $max): string
    {
        if (mb_strlen($text) > $max) {
            $this->fail($field, 'too_long', ['max' => $max]);
        }

        return $text;
    }
}
