<?php

namespace App\Actions\Konten;

use Illuminate\Support\Arr;
use Throwable;

/**
 * Muat katalog tema resmi (F103) dari `config('pondok.theme_catalog_url')`, maksimum 256 KB. Tidak pernah melempar:
 * kegagalan jaringan atau bentuk katalog yang salah menghasilkan `error` (pesan generik, tanpa isi respons).
 *
 * Katalog tidak dipercaya (yang dipercaya hanya tanda tangan atas berkas tema). Entri tak valid dilewati tanpa merusak yang lain,
 * entri berslug tema resmi atau berawalan `lokal-` diabaikan, dan hanya kunci entri yang dikenal yang diteruskan.
 */
class FetchThemeCatalog
{
    public const MAX_BYTES = 262144;

    public const ENTRY_KEYS = ['slug', 'name', 'description', 'version', 'schema_version', 'min_core', 'size', 'sha256', 'url', 'signature', 'key_id'];

    public function __construct(private readonly ThemeCatalogClient $client) {}

    /**
     * @return array{themes: list<array{slug: string, name: string, description: string, version: string, schema_version: int, min_core: string, size: int, sha256: string, url: string, signature: string, key_id: string}>, error: ?string}
     */
    public function handle(): array
    {
        try {
            $catalog = json_decode($this->client->get((string) config('pondok.theme_catalog_url'), self::MAX_BYTES), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['themes' => [], 'error' => __('pondok.theme_install.errors.catalog_unavailable')];
        }

        if (! is_array($catalog) || ! is_array($catalog['themes'] ?? null)) {
            return ['themes' => [], 'error' => __('pondok.theme_install.errors.catalog_unavailable')];
        }

        $themes = [];

        foreach ($catalog['themes'] as $entry) {
            if ($this->isValid($entry)) {
                $themes[] = Arr::only($entry, self::ENTRY_KEYS);
            }
        }

        return ['themes' => $themes, 'error' => null];
    }

    private function isValid(mixed $entry): bool
    {
        if (! is_array($entry) || ! ResolveThemePath::isSlug($entry['slug'] ?? null) || ResolveThemePath::isOfficial($entry['slug']) || ResolveThemePath::isLocal($entry['slug'])) {
            return false;
        }

        foreach (['name' => 60, 'description' => 300] as $key => $max) {
            if (! is_string($entry[$key] ?? null) || mb_strlen($entry[$key]) > $max || ($key === 'name' && trim($entry[$key]) === '')) {
                return false;
            }
        }

        foreach (['version', 'min_core'] as $key) {
            if (! is_string($entry[$key] ?? null) || preg_match('/^\d+\.\d+\.\d+\z/', $entry[$key]) !== 1) {
                return false;
            }
        }

        return is_int($entry['schema_version'] ?? null)
            && is_int($entry['size'] ?? null) && $entry['size'] > 0
            && is_string($entry['sha256'] ?? null) && preg_match('/^[0-9a-f]{64}\z/i', $entry['sha256']) === 1
            && is_string($entry['url'] ?? null) && $entry['url'] !== ''
            && is_string($entry['signature'] ?? null) && $entry['signature'] !== ''
            && is_string($entry['key_id'] ?? null) && $entry['key_id'] !== '';
    }
}
