<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ValidatesContentInput;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Simpan gambar konten publik (sampul, foto galeri, logo) ke disk `public` dengan
 * nama acak. Jenis dibaca dari isi berkas, bukan nama; hanya JPG/PNG (SVG, GIF, WEBP ditolak).
 */
class StorePublicImage
{
    use ValidatesContentInput;

    public const DISK = 'public';

    /** JPG dan PNG saja: unggahan sementara Livewire (config/livewire.php) hanya menerima kedua jenis ini. */
    public const MIME_TYPES = ['image/jpeg', 'image/png'];

    /** Batas sampul dan foto (KB); `upload_max_filesize` bawaan banyak cPanel 2–8 MB. */
    public const MAX_KILOBYTES = 2048;

    public const LOGO_MAX_KILOBYTES = 1024;

    /** Favicon portal: PNG saja. */
    public const FAVICON_MAX_KILOBYTES = 256;

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public function handle(UploadedFile $file, string $directory, string $field, int $maxKilobytes = self::MAX_KILOBYTES): string
    {
        return $this->store($file, $directory, $this->validate($file, $field, $maxKilobytes));
    }

    /**
     * Periksa jenis dan ukuran; kembalikan ekstensi berkas.
     */
    public function validate(mixed $file, string $field, int $maxKilobytes = self::MAX_KILOBYTES): string
    {
        $extension = $file instanceof UploadedFile ? (self::EXTENSIONS[$file->getMimeType()] ?? null) : null;

        if ($extension === null || (int) $file->getSize() > $maxKilobytes * 1024) {
            $this->fail($field, 'image_invalid', ['max' => $maxKilobytes]);
        }

        return $extension;
    }

    /**
     * Simpan berkas yang sudah divalidasi; kembalikan path relatif di disk `public`.
     */
    public function store(UploadedFile $file, string $directory, string $extension): string
    {
        $path = Storage::disk(self::DISK)->putFileAs(trim($directory, '/'), $file, Str::uuid().'.'.$extension);

        if ($path === false) {
            throw new RuntimeException('Gambar gagal disimpan.');
        }

        return $path;
    }

    /**
     * Hapus berkas (bila ada) dari disk `public`.
     */
    public function delete(?string ...$paths): void
    {
        $paths = array_values(array_filter($paths));

        if ($paths !== []) {
            Storage::disk(self::DISK)->delete($paths);
        }
    }
}
