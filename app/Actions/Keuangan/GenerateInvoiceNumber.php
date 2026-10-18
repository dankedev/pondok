<?php

namespace App\Actions\Keuangan;

use App\Models\Invoice;
use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Nomor tagihan dari setting `keuangan.invoice_format`.
 *
 * Token: `{YYYY}` tahun, `{YY}` dua digit terakhir, `{MM}` bulan, dan tepat
 * satu token urut `{NNN}`–`{NNNNNN}` di akhir format. Literal lain hanya huruf,
 * angka, `.`, `-`, `/`; panjang hasil maksimal 20. Format tidak valid memakai
 * bawaan `INV-{YYYY}{MM}-{NNNNN}` dan dicatat di log.
 *
 * Penghitung dibaca tanpa cakupan jenjang dan tanpa filter status: tagihan
 * jenjang lain dan tagihan `batal` tetap memakai nomornya.
 */
class GenerateInvoiceNumber
{
    public const DEFAULT_FORMAT = 'INV-{YYYY}{MM}-{NNNNN}';

    public const SETTING_KEY = 'keuangan.invoice_format';

    public const MAX_LENGTH = 20;

    public const TOKENS = ['YYYY', 'YY', 'MM'];

    private const SEQUENCE_PATTERN = '/\{(N{3,6})\}$/';

    /**
     * Nomor urut terbesar yang sudah dipakai di bulan `$month`, 0 bila belum ada.
     */
    public function lastSequence(CarbonInterface $month): int
    {
        $format = $this->configuredFormat();
        $prefix = $this->prefix($format, $month);
        $width = $this->width($format);
        $pattern = '/^'.preg_quote($prefix, '/').'(\d{'.$width.'})$/';

        // `LIKE` hanya menyaring kandidat; kecocokan pasti dicek regex di PHP.
        return Invoice::withoutUnitScope()
            ->where('invoice_no', 'like', $prefix.'%')
            ->pluck('invoice_no')
            ->map(fn (string $number): int => preg_match($pattern, $number, $digits) === 1 ? (int) $digits[1] : 0)
            ->max() ?? 0;
    }

    public function format(CarbonInterface $month, int $sequence): string
    {
        $format = $this->configuredFormat();
        $width = $this->width($format);
        $max = 10 ** $width - 1;

        if ($sequence < 1 || $sequence > $max) {
            throw new LogicException("Nomor urut tagihan {$sequence} di luar rentang 1-{$max}.");
        }

        return $this->prefix($format, $month).str_pad((string) $sequence, $width, '0', STR_PAD_LEFT);
    }

    /**
     * Nomor berikutnya untuk satu tagihan (dipakai PSB, F010/F011).
     */
    public function handle(CarbonInterface $month): string
    {
        return $this->format($month, $this->lastSequence($month) + 1);
    }

    /**
     * Format sah: token dikenal, literal diizinkan, satu token urut di akhir, panjang ≤ 20.
     */
    public static function isValidFormat(string $format): bool
    {
        if (preg_match(self::SEQUENCE_PATTERN, $format, $match) !== 1) {
            return false;
        }

        $head = substr($format, 0, -strlen($match[0]));

        if (preg_match('/^(?:\{YYYY\}|\{YY\}|\{MM\}|[A-Za-z0-9.\-\/])*$/', $head) !== 1) {
            return false;
        }

        $length = strlen(strtr($head, ['{YYYY}' => '0000', '{YY}' => '00', '{MM}' => '00'])) + strlen($match[1]);

        return $length <= self::MAX_LENGTH;
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

        $length = strlen(strtr($head, ['{YYYY}' => '0000', '{YY}' => '00', '{MM}' => '00'])) + strlen($match[1]);

        if ($length > self::MAX_LENGTH) {
            $this->fail('format', 'format_too_long');
        }

        return $format;
    }

    /**
     * Format yang berlaku (nilai tersimpan, atau bawaan bila kosong/tidak sah).
     */
    public function resolvedFormat(): string
    {
        return $this->configuredFormat();
    }

    /**
     * Pratinjau format untuk halaman admin (contoh nomor urut 1).
     */
    public function preview(string $format, CarbonInterface $month): string
    {
        $format = $this->validateFormat($format);

        return $this->prefix($format, $month).str_pad('1', $this->width($format), '0', STR_PAD_LEFT);
    }

    private function configuredFormat(): string
    {
        $format = Setting::get(self::SETTING_KEY, self::DEFAULT_FORMAT);

        if (is_string($format) && static::isValidFormat($format)) {
            return $format;
        }

        Log::warning('Setting keuangan.invoice_format tidak valid, memakai bawaan', ['format' => $format, 'default' => self::DEFAULT_FORMAT]);

        return self::DEFAULT_FORMAT;
    }

    private function prefix(string $format, CarbonInterface $month): string
    {
        return strtr((string) preg_replace(self::SEQUENCE_PATTERN, '', $format), [
            '{YYYY}' => $month->format('Y'),
            '{YY}' => $month->format('y'),
            '{MM}' => $month->format('m'),
        ]);
    }

    private function width(string $format): int
    {
        preg_match(self::SEQUENCE_PATTERN, $format, $match);

        return strlen($match[1]);
    }

    private function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.numbering.errors.{$reason}"),
        ]);
    }
}
