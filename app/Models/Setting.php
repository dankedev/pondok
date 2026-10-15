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

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->find($key)?->value ?? $default;
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
