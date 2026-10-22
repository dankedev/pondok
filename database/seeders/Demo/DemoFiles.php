<?php

namespace Database\Seeders\Demo;

use Illuminate\Http\UploadedFile;

/**
 * Berkas placeholder kecil untuk data demo, dibuat di direktori sementara
 * sebagai `UploadedFile` (mode test) agar validasi MIME Action berlaku.
 * Berkas sementara dihapus lewat `cleanup()`.
 */
final class DemoFiles
{
    /** Pasangan warna gradasi (atas, bawah) dari palet portal: navy #000A3C dan emas #F8B800 (design guide §2). */
    private const GRADIENTS = [
        [[0, 10, 60], [39, 62, 140]],
        [[255, 249, 229], [255, 212, 82]],
        [[6, 18, 71], [95, 120, 196]],
        [[248, 184, 0], [255, 240, 191]],
        [[221, 228, 246], [238, 242, 251]],
    ];

    /** PNG 1x1, dipakai bila GD tidak tersedia. */
    private const FALLBACK_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /** @var list<string> */
    private array $paths = [];

    public function pdf(string $name): UploadedFile
    {
        $text = 'Dokumen contoh - data demo Pondok Pesantren Contoh';
        $stream = "BT /F1 12 Tf 72 720 Td ({$text}) Tj ET";
        $content = "%PDF-1.4\n"
            ."1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            ."2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            ."3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n"
            .'4 0 obj << /Length '.strlen($stream)." >> stream\n{$stream}\nendstream endobj\n"
            ."5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n"
            ."trailer << /Root 1 0 R >>\n%%EOF\n";

        return $this->make($name.'.pdf', $content, 'application/pdf');
    }

    public function png(string $name, int $variant = 0): UploadedFile
    {
        return $this->make($name.'.png', $this->pngContent($variant), 'image/png');
    }

    public function cleanup(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->paths = [];
    }

    private function make(string $name, string $content, string $mime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'demo');
        file_put_contents($path, $content);
        $this->paths[] = $path;

        return new UploadedFile($path, $name, $mime, null, true);
    }

    /**
     * Pola netral tanpa teks: gradasi vertikal lembut dua warna palet dan tiga
     * lingkaran transparan. Warna dan posisi ditentukan `$variant` (deterministik).
     */
    private function pngContent(int $variant): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return (string) base64_decode(self::FALLBACK_PNG);
        }

        $width = 800;
        $height = 600;
        [$top, $bottom] = self::GRADIENTS[$variant % count(self::GRADIENTS)];
        $image = imagecreatetruecolor($width, $height);

        for ($y = 0; $y < $height; $y++) {
            $t = $y / ($height - 1);
            $color = (int) imagecolorallocate(
                $image,
                (int) round($top[0] + ($bottom[0] - $top[0]) * $t),
                (int) round($top[1] + ($bottom[1] - $top[1]) * $t),
                (int) round($top[2] + ($bottom[2] - $top[2]) * $t),
            );
            imageline($image, 0, $y, $width - 1, $y, $color);
        }

        imagealphablending($image, true);
        $circle = (int) imagecolorallocatealpha($image, 255, 255, 255, 105);

        for ($i = 0; $i < 3; $i++) {
            $seed = ($variant * 7 + $i * 13) % 17;
            $x = 120 + ($seed * 37 + $i * 211) % 560;
            $y = 110 + ($seed * 53 + $i * 149) % 380;
            $diameter = 160 + ($seed * 23) % 220;
            imagefilledellipse($image, $x, $y, $diameter, $diameter, $circle);
        }

        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }
}
