<?php

namespace App\Services;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Pembungkus tunggal `chillerlan/php-qrcode` (dependensi Filament) untuk QR
 * verifikasi surat. Menghasilkan SVG inline HTML5: tanpa prolog XML, tanpa
 * `xmlns`, modul terang tidak digambar (latar transparan), modul gelap hitam.
 */
class QrCodeRenderer
{
    public function options(string $outputType = QROutputInterface::MARKUP_SVG): QROptions
    {
        return new QROptions([
            'outputType' => $outputType,
            'eccLevel' => QRCode::ECC_M,
            'addQuietzone' => true,
            'outputBase64' => false,
            'drawLightModules' => false,
            // Modul gelap memakai warna bawaan `#000` (aman dicetak hitam-putih).
            'svgAddXmlHeader' => false,
        ]);
    }

    /**
     * SVG inline dengan `role="img"` dan `aria-label` (di-escape).
     */
    public function svg(string $data, string $label): string
    {
        $svg = (string) (new QRCode($this->options()))->render($data);

        $svg = (string) preg_replace('/<\?xml[^>]*\?>/', '', $svg);
        $svg = (string) preg_replace('/\s+xmlns(:[a-z]+)?="[^"]*"/i', '', $svg);

        return (string) preg_replace(
            '/^<svg\b/',
            '<svg role="img" aria-label="'.e($label).'"',
            trim($svg),
            1,
        );
    }
}
