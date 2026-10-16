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
    /** Palet design guide: hijau tua, hijau, krem. */
    private const COLORS = [[22, 101, 52], [21, 128, 61], [245, 240, 225], [180, 140, 70]];

    /** PNG 1x1, dipakai bila GD tidak tersedia. */
    private const FALLBACK_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /** @var list<string> */
    private array $paths = [];

    public function pdf(string $name): UploadedFile
    {
        $text = "Dokumen contoh - data demo Ma'had Al-Khair Belitang";
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

    public function png(string $name, string $label, int $variant = 0): UploadedFile
    {
        return $this->make($name.'.png', $this->pngContent($label, $variant), 'image/png');
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

    private function pngContent(string $label, int $variant): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return (string) base64_decode(self::FALLBACK_PNG);
        }

        $image = imagecreatetruecolor(800, 600);
        [$r, $g, $b] = self::COLORS[$variant % 2];
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $r, $g, $b));

        [$r, $g, $b] = self::COLORS[2];
        $cream = (int) imagecolorallocate($image, $r, $g, $b);
        [$r, $g, $b] = self::COLORS[3];
        imagefilledrectangle($image, 0, 520, 800, 600, (int) imagecolorallocate($image, $r, $g, $b));

        $label = mb_substr(preg_replace('/[^\x20-\x7E]/', '', $label) ?? '', 0, 70);
        imagestring($image, 5, 40, 260, $label, $cream);
        imagestring($image, 3, 40, 550, "Gambar contoh - Ma'had Al-Khair Belitang", $cream);

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
