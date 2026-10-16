<?php

namespace Tests\Fixtures;

use App\Services\SystemProbe;

/**
 * Probe palsu untuk test F019: semua nilai "sehat" kecuali yang diubah test.
 */
class FakeSystemProbe extends SystemProbe
{
    public string $version = '8.3.15';

    /** @var list<string> */
    public array $missingExtensions = [];

    /** @var list<string> */
    public array $disabledFunctions = [];

    /** @var array<string, string> */
    public array $ini = ['upload_max_filesize' => '8M', 'post_max_size' => '16M', 'memory_limit' => '256M'];

    /** @var list<string> */
    public array $unwritable = [];

    public function phpVersion(): string
    {
        return $this->version;
    }

    public function extensionLoaded(string $name): bool
    {
        return ! in_array($name, $this->missingExtensions, true);
    }

    public function functionEnabled(string $name): bool
    {
        return ! in_array($name, $this->disabledFunctions, true);
    }

    public function iniGet(string $key): string|false
    {
        return $this->ini[$key] ?? false;
    }

    public function isWritable(string $path): bool
    {
        return ! in_array($path, $this->unwritable, true);
    }
}
