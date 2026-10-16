<?php

namespace App\Actions\Dokumen;

use App\Models\IssuedLetter;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Format nomor surat keluar dan penghitung nomor urut per (jenis, tahun).
 *
 * Pengaturan dibaca dari `settings` (`letters.*`) dan digabung dengan nilai
 * bawaan. Penghitung membaca semua surat, termasuk yang `batal`, tanpa
 * cakupan jenjang, sehingga nomor tidak pernah dipakai ulang. Keunikan akhir
 * dijamin unique index `letter_no`.
 *
 * Tidak `final`: test mengganti `nextSequence` lewat subkelas di container.
 */
class LetterNumbering
{
    public const DEFAULT_FORMAT = '{seq}/{school_code}/{type_code}/{month_roman}/{year}';

    public const DEFAULT_SCHOOL_CODE = 'PONDOK';

    public const DEFAULT_PADDING = 3;

    /** @var array<string, string> */
    public const DEFAULT_TYPE_CODES = [
        IssuedLetter::TYPE_ACTIVE_STATEMENT => 'SKA',
        IssuedLetter::TYPE_TRANSFER => 'SKP',
        IssuedLetter::TYPE_RECOMMENDATION => 'SRK',
        IssuedLetter::TYPE_SYAHADAH => 'SYH',
        IssuedLetter::TYPE_STUDENT_CARD => 'KTS',
    ];

    public const TOKENS = ['seq', 'school_code', 'type_code', 'month', 'month_roman', 'year'];

    public const REQUIRED_TOKENS = ['seq', 'type_code', 'year'];

    public const MAX_LENGTH = 50;

    public const MIN_PADDING = 1;

    public const MAX_PADDING = 6;

    public const CODE_PATTERN = '/^[A-Z0-9.-]{1,10}$/';

    private const ROMAN_MONTHS = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    /**
     * Pengaturan yang berlaku: nilai tersimpan digabung bawaan.
     *
     * @return array{format: string, school_code: string, type_codes: array<string, string>, padding: int}
     */
    public function settings(): array
    {
        $format = Setting::get('letters.number_format');
        $schoolCode = Setting::get('letters.school_code');
        $padding = Setting::get('letters.sequence_padding');

        return [
            'format' => is_string($format) && $format !== '' ? $format : self::DEFAULT_FORMAT,
            'school_code' => is_string($schoolCode) && $schoolCode !== ''
                ? $schoolCode
                : $this->defaultSchoolCode((string) Setting::get('school.name', config('app.name'))),
            'type_codes' => $this->storedTypeCodes(),
            'padding' => is_numeric($padding) ? (int) $padding : self::DEFAULT_PADDING,
        ];
    }

    /**
     * Huruf pertama tiap kata nama lembaga (dipisah spasi dan `-`), huruf besar, maksimal 6.
     */
    public function defaultSchoolCode(string $schoolName): string
    {
        $code = collect(preg_split('/[\s-]+/u', $schoolName) ?: [])
            ->map(fn (string $word): string => (string) preg_replace('/[^A-Za-z0-9]/', '', $word))
            ->filter(fn (string $word): bool => $word !== '')
            ->map(fn (string $word): string => strtoupper($word[0]))
            ->take(6)
            ->implode('');

        return $code === '' ? self::DEFAULT_SCHOOL_CODE : $code;
    }

    /**
     * Nomor surat untuk jenis, tanggal, dan nomor urut, misalnya `045/MAK/SKA/X/2026`.
     *
     * @param  array{format: string, school_code: string, type_codes: array<string, string>, padding: int}|null  $settings
     */
    public function format(string $type, CarbonInterface $date, int $sequence, ?array $settings = null): string
    {
        $settings ??= $this->settings();

        return strtr($settings['format'], [
            '{seq}' => str_pad((string) $sequence, max(1, (int) $settings['padding']), '0', STR_PAD_LEFT),
            '{school_code}' => $settings['school_code'],
            '{type_code}' => $settings['type_codes'][$type] ?? self::DEFAULT_TYPE_CODES[$type] ?? strtoupper($type),
            '{month}' => $date->format('m'),
            '{month_roman}' => self::ROMAN_MONTHS[$date->month - 1],
            '{year}' => $date->format('Y'),
        ]);
    }

    /**
     * Nomor urut berikutnya untuk jenis dan tahun, dari semua surat (termasuk `batal`, tanpa cakupan jenjang).
     */
    public function nextSequence(string $type, int $year): int
    {
        return (int) IssuedLetter::withoutGlobalScopes()
            ->where('type', $type)
            ->where('sequence_year', $year)
            ->max('sequence') + 1;
    }

    /**
     * Validasi dan normalisasi masukan pengaturan penomoran.
     *
     * @param  array<string, mixed>  $data
     * @return array{number_format: string, school_code: string|null, type_codes: array<string, string>, sequence_padding: int}
     */
    public function validate(array $data): array
    {
        $format = $this->validatedFormat($data['number_format'] ?? null);
        $schoolCode = $this->validatedSchoolCode($data['school_code'] ?? null);
        $typeCodes = $this->validatedTypeCodes($data['type_codes'] ?? null);
        $padding = $this->validatedPadding($data['sequence_padding'] ?? null);

        $this->ensureNotTooLong($format, $schoolCode, $typeCodes, $padding);
        $this->ensureTypeCodesUnlocked($typeCodes);

        return [
            'number_format' => $format,
            'school_code' => $schoolCode,
            'type_codes' => $typeCodes,
            'sequence_padding' => $padding,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function storedTypeCodes(): array
    {
        $stored = Setting::get('letters.type_codes');
        $codes = self::DEFAULT_TYPE_CODES;

        if (is_array($stored)) {
            foreach ($stored as $type => $code) {
                if (array_key_exists($type, $codes) && is_string($code) && $code !== '') {
                    $codes[$type] = $code;
                }
            }
        }

        return $codes;
    }

    private function validatedFormat(mixed $value): string
    {
        $format = is_string($value) ? trim($value) : '';

        preg_match_all('/\{([^{}]*)\}/', $format, $matches);

        if (array_diff($matches[1], self::TOKENS) !== []) {
            $this->fail('number_format', 'format_token_unknown');
        }

        if (array_diff(self::REQUIRED_TOKENS, $matches[1]) !== []) {
            $this->fail('number_format', 'format_token_missing');
        }

        $literal = (string) preg_replace('/\{[^{}]*\}/', '', $format);

        if (preg_match('#^[A-Za-z0-9/._-]*$#', $literal) !== 1) {
            $this->fail('number_format', 'format_character_invalid');
        }

        return $format;
    }

    private function validatedSchoolCode(mixed $value): ?string
    {
        $code = is_string($value) ? strtoupper(trim($value)) : (is_numeric($value) ? (string) $value : '');

        if ($code === '') {
            return null;
        }

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            $this->fail('school_code', 'code_invalid');
        }

        return $code;
    }

    /**
     * @return array<string, string>
     */
    private function validatedTypeCodes(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $codes = [];

        foreach (array_keys(self::DEFAULT_TYPE_CODES) as $type) {
            $code = $value[$type] ?? null;
            $code = is_string($code) || is_numeric($code) ? strtoupper(trim((string) $code)) : '';

            if (preg_match(self::CODE_PATTERN, $code) !== 1) {
                $this->fail('type_codes', 'code_invalid');
            }

            $codes[$type] = $code;
        }

        if (count(array_unique($codes)) !== count($codes)) {
            $this->fail('type_codes', 'type_codes_not_unique');
        }

        return $codes;
    }

    private function validatedPadding(mixed $value): int
    {
        $padding = is_int($value) ? $value : filter_var(is_string($value) ? trim($value) : $value, FILTER_VALIDATE_INT);

        if ($padding === false || $padding < self::MIN_PADDING || $padding > self::MAX_PADDING) {
            $this->fail('sequence_padding', 'padding_invalid');
        }

        return $padding;
    }

    /**
     * Contoh terpanjang (nomor urut 6 digit, kode terpanjang, bulan VIII) harus muat di kolom `letter_no`.
     *
     * @param  array<string, string>  $typeCodes
     */
    private function ensureNotTooLong(string $format, ?string $schoolCode, array $typeCodes, int $padding): void
    {
        $longestType = collect($typeCodes)->sortByDesc(fn (string $code): int => strlen($code))->keys()->first();
        $example = $this->format((string) $longestType, CarbonImmutable::create(2026, 8, 28), 999999, [
            'format' => $format,
            'school_code' => $schoolCode ?? $this->defaultSchoolCode((string) Setting::get('school.name', config('app.name'))),
            'type_codes' => $typeCodes,
            'padding' => $padding,
        ]);

        if (strlen($example) > self::MAX_LENGTH) {
            $this->fail('number_format', 'format_too_long');
        }
    }

    /**
     * Kode jenis tidak boleh berubah bila jenis itu sudah punya surat (termasuk `batal`) di tahun berjalan.
     *
     * @param  array<string, string>  $typeCodes
     */
    private function ensureTypeCodesUnlocked(array $typeCodes): void
    {
        $current = $this->storedTypeCodes();
        $changed = array_keys(array_filter($typeCodes, fn (string $code, string $type): bool => $current[$type] !== $code, ARRAY_FILTER_USE_BOTH));

        if ($changed === []) {
            return;
        }

        $locked = IssuedLetter::withoutGlobalScopes()
            ->whereIn('type', $changed)
            ->where('sequence_year', CarbonImmutable::now('Asia/Jakarta')->year)
            ->orderBy('type')
            ->distinct()
            ->pluck('type')
            ->first();

        if ($locked !== null) {
            $this->fail('type_codes', 'type_code_locked', ['type' => IssuedLetter::typeLabelFor($locked)]);
        }
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function fail(string $field, string $reason, array $replace = []): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.dokumen.errors.{$reason}", $replace),
        ]);
    }
}
