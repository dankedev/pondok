<?php

namespace App\Actions\Konten;

/**
 * Tampilan nomor telepon tersimpan (design guide §5) dan tautan WhatsApp `wa.me`.
 * Masukan adalah nilai tersimpan F016 (`^\+?[0-9]{8,15}$`).
 */
class FormatPhoneForDisplay
{
    public function handle(?string $digits): ?string
    {
        if ($digits === null || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '+62')) {
            return '+62 '.$this->group(substr($digits, 3), [3, 4]);
        }

        if (str_starts_with($digits, '0')) {
            return $this->group($digits, [4, 4]);
        }

        return $digits;
    }

    public function whatsappUrl(?string $digits): ?string
    {
        if ($digits === null || $digits === '') {
            return null;
        }

        $number = match (true) {
            str_starts_with($digits, '+') => substr($digits, 1),
            str_starts_with($digits, '0') => '62'.substr($digits, 1),
            default => $digits,
        };

        return 'https://wa.me/'.$number;
    }

    /**
     * @param  list<int>  $sizes  ukuran kelompok awal; sisanya menjadi kelompok terakhir
     */
    private function group(string $digits, array $sizes): string
    {
        $parts = [];

        foreach ($sizes as $size) {
            if ($digits === '') {
                break;
            }

            $parts[] = substr($digits, 0, $size);
            $digits = substr($digits, $size);
        }

        if ($digits !== '') {
            $parts[] = $digits;
        }

        return implode('-', $parts);
    }
}
