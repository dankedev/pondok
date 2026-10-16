<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan key-value, misalnya `school.name` atau `modules.keuangan.enabled`.
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public const CACHE_BINDING = 'pondok.settings';

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn (Setting $setting) => static::forget($setting->key));
        static::deleted(fn (Setting $setting) => static::forget($setting->key));
    }

    /**
     * Nilai dibaca sekali per key per request lewat binding `scoped`, karena
     * cek saklar modul dipanggil di setiap otorisasi resource Filament.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $cache = app(self::CACHE_BINDING);

        if (! $cache->offsetExists($key)) {
            $cache[$key] = static::query()->find($key)?->value;
        }

        return $cache[$key] ?? $default;
    }

    /**
     * Baca banyak key sekaligus: key yang belum ada di cache diambil dengan satu
     * query `whereIn`; key yang tidak ada di tabel disimpan `null` di cache.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function getMany(array $keys): array
    {
        $cache = app(self::CACHE_BINDING);
        $missing = array_values(array_filter($keys, fn (string $key): bool => ! $cache->offsetExists($key)));

        if ($missing !== []) {
            $found = static::query()->whereIn('key', $missing)->get()->pluck('value', 'key');

            foreach ($missing as $key) {
                $cache[$key] = $found->get($key);
            }
        }

        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $cache[$key];
        }

        return $values;
    }

    public static function forget(string $key): void
    {
        app(self::CACHE_BINDING)->offsetUnset($key);
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Status saklar modul `modules.<nama>.enabled`. Modul yang belum diatur dianggap mati.
     */
    public static function moduleEnabled(string $module): bool
    {
        return (bool) static::get("modules.{$module}.enabled", false);
    }
}
