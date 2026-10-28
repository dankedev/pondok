<?php

namespace App\Services;

/**
 * Pembungkus fungsi runtime PHP (versi, ekstensi, fungsi yang dimatikan hosting,
 * `ini_get`, izin tulis) agar pemeriksaan server bisa diganti di test.
 */
class SystemProbe
{
    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function extensionLoaded(string $name): bool
    {
        return extension_loaded($name);
    }

    /**
     * Fungsi ada dan tidak tercantum di `disable_functions` (shared hosting sering
     * mematikan `proc_open`, `exec`, atau `symlink`).
     */
    public function functionEnabled(string $name): bool
    {
        if (! function_exists($name)) {
            return false;
        }

        $disabled = array_map(
            fn (string $function): string => strtolower(trim($function)),
            explode(',', (string) $this->iniGet('disable_functions')),
        );

        return ! in_array(strtolower($name), $disabled, true);
    }

    public function iniGet(string $key): string|false
    {
        return ini_get($key);
    }

    public function isWritable(string $path): bool
    {
        return is_writable($path);
    }

    /**
     * Berjalan di container Docker (berkas penanda `/.dockerenv`).
     */
    public function runningInContainer(): bool
    {
        return is_file('/.dockerenv');
    }

    /**
     * Folder adalah mount point (volume): device-nya berbeda dari folder induk.
     */
    public function isMountPoint(string $path): bool
    {
        $device = @stat($path)['dev'] ?? null;
        $parentDevice = @stat(dirname($path))['dev'] ?? null;

        return $device !== null && $parentDevice !== null && $device !== $parentDevice;
    }
}
