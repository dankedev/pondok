<?php

namespace App\Actions\Akademik;

use App\Models\Setting;
use App\Models\Student;
use App\Models\Unit;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * NIS otomatis dari setting `students.nis_format`.
 *
 * Token: `{YYYY}` tahun masuk, `{YY}` dua digit terakhir, `{UNIT}` kode
 * jenjang, dan tepat satu token urut `{NNN}`–`{NNNNNN}` di akhir format.
 * Literal lain hanya huruf, angka, `.`, `-`, `/`; panjang hasil maksimal 30.
 * Format tidak valid memakai bawaan `{YYYY}{NNNN}` dan dicatat di log.
 *
 * Urutan = nomor terbesar santri (termasuk terhapus) yang NIS-nya cocok pola
 * prefiks + lebar urut, ditambah 1. Tidak menyimpan apa pun.
 */
class GenerateStudentNumber
{
    public const DEFAULT_FORMAT = '{YYYY}{NNNN}';

    public const SETTING_KEY = 'students.nis_format';

    public const MAX_LENGTH = 30;

    public const TOKENS = ['YYYY', 'YY', 'UNIT'];

    private const SEQUENCE_PATTERN = '/\{(N{3,6})\}$/';

    /**
     * @throws ValidationException nomor urut melebihi lebar token urut
     */
    public function handle(Unit $unit, int $entryYear): string
    {
        $format = $this->format();

        if (strlen($this->prefix($format, $unit, $entryYear)) + $this->width($format) > self::MAX_LENGTH) {
            Log::warning('Format NIS menghasilkan nomor terlalu panjang, memakai bawaan', ['format' => $format]);
            $format = self::DEFAULT_FORMAT;
        }

        $prefix = $this->prefix($format, $unit, $entryYear);
        $width = $this->width($format);
        $pattern = '/^'.preg_quote($prefix, '/').'(\d{'.$width.'})$/';

        // `LIKE` hanya menyaring kandidat; kecocokan pasti dicek regex di PHP.
        $last = Student::withoutGlobalScopes()->withTrashed()
            ->where('nis', 'like', $prefix.'%')
            ->pluck('nis')
            ->map(fn (string $nis): int => preg_match($pattern, $nis, $digits) === 1 ? (int) $digits[1] : 0)
            ->max() ?? 0;

        $next = $last + 1;

        if ($next >= 10 ** $width) {
            throw ValidationException::withMessages([
                'nis' => __('pondok.akademik.errors.nis_sequence_exhausted'),
            ]);
        }

        return $prefix.str_pad((string) $next, $width, '0', STR_PAD_LEFT);
    }

    /**
     * Format sah: hanya token dikenal dan literal yang diizinkan, satu token urut di akhir,
     * panjang tanpa `{UNIT}` maksimal 30.
     */
    public static function isValidFormat(string $format): bool
    {
        if (preg_match(self::SEQUENCE_PATTERN, $format, $match) !== 1) {
            return false;
        }

        $head = substr($format, 0, -strlen($match[0]));

        if (preg_match('/^(?:\{YYYY\}|\{YY\}|\{UNIT\}|[A-Za-z0-9.\-\/])*$/', $head) !== 1) {
            return false;
        }

        $length = strlen(strtr($head, ['{YYYY}' => '0000', '{YY}' => '00', '{UNIT}' => ''])) + strlen($match[1]);

        return $length <= self::MAX_LENGTH;
    }

    /**
     * Format yang berlaku (nilai tersimpan, atau bawaan bila kosong/tidak sah).
     */
    public function resolvedFormat(): string
    {
        return $this->format();
    }

    /**
     * Validasi format dari halaman admin; melempar `ValidationException` di field `format`.
     */
    public function validateFormat(mixed $value): string
    {
        $format = is_string($value) ? trim($value) : '';

        if (preg_match(self::SEQUENCE_PATTERN, $format, $match) !== 1) {
            $this->fail('format', 'format_sequence_missing');
        }

        $head = substr($format, 0, -strlen($match[0]));

        preg_match_all('/\{([^{}]*)\}/', $head, $tokens);

        if (array_diff($tokens[1], self::TOKENS) !== []) {
            $this->fail('format', 'format_token_unknown');
        }

        if (preg_match('#^[A-Za-z0-9.\-/]*$#', (string) preg_replace('/\{[^{}]*\}/', '', $head)) !== 1) {
            $this->fail('format', 'format_character_invalid');
        }

        $length = strlen(strtr($head, ['{YYYY}' => '0000', '{YY}' => '00', '{UNIT}' => ''])) + strlen($match[1]);

        if ($length > self::MAX_LENGTH) {
            $this->fail('format', 'format_too_long');
        }

        return $format;
    }

    /**
     * Pratinjau format untuk halaman admin (contoh nomor urut 1).
     */
    public function preview(string $format, Unit $unit, int $entryYear): string
    {
        $format = $this->validateFormat($format);
        $prefix = $this->prefix($format, $unit, $entryYear);
        $width = $this->width($format);

        if (strlen($prefix) + $width > self::MAX_LENGTH) {
            $this->fail('format', 'format_too_long');
        }

        return $prefix.str_pad('1', $width, '0', STR_PAD_LEFT);
    }

    private function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.numbering.errors.{$reason}"),
        ]);
    }

    private function format(): string
    {
        $format = Setting::get(self::SETTING_KEY, self::DEFAULT_FORMAT);

        if (is_string($format) && static::isValidFormat($format)) {
            return $format;
        }

        Log::warning('Setting students.nis_format tidak valid, memakai bawaan', ['format' => $format, 'default' => self::DEFAULT_FORMAT]);

        return self::DEFAULT_FORMAT;
    }

    private function prefix(string $format, Unit $unit, int $entryYear): string
    {
        $year = sprintf('%04d', $entryYear);

        return strtr((string) preg_replace(self::SEQUENCE_PATTERN, '', $format), [
            '{YYYY}' => $year,
            '{YY}' => substr($year, -2),
            '{UNIT}' => (string) $unit->code,
        ]);
    }

    private function width(string $format): int
    {
        preg_match(self::SEQUENCE_PATTERN, $format, $match);

        return strlen($match[1]);
    }
}
