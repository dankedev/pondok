<?php

namespace App\Actions\Konten\Concerns;

use App\Models\Setting;
use App\Models\User;

/**
 * Bersama Action profil lembaga: normalisasi telepon, batas daftar, dan simpan
 * key `settings` yang berubah saja dalam satu transaksi beserta activity log `konten`.
 */
trait SavesProfileSettings
{
    use ReplacesStoredFiles, ValidatesContentInput;

    /**
     * Telepon tanpa spasi, `-`, `.`, dan kurung; harus `^\+?[0-9]{8,15}$`. Kosong → null.
     */
    protected function phone(mixed $value, string $field): ?string
    {
        $phone = is_scalar($value) ? str_replace([' ', '-', '.', '(', ')'], '', trim((string) $value)) : '';

        if ($phone === '') {
            return null;
        }

        if (preg_match('/^\+?[0-9]{8,15}$/', $phone) !== 1) {
            $this->fail($field, 'phone_invalid');
        }

        return $phone;
    }

    /**
     * Daftar input (bukan array → kosong); lebih dari `$max` item ditolak.
     *
     * @return list<array<string, mixed>>
     */
    protected function listInput(mixed $value, string $field, int $max): array
    {
        $items = is_array($value) ? array_values($value) : [];

        if (count($items) > $max) {
            $this->fail($field, 'list_too_long', ['max' => $max]);
        }

        return array_map(fn (mixed $item): array => is_array($item) ? $item : [], $items);
    }

    /**
     * Simpan key yang berubah; tidak ada perubahan berarti tidak ada log.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $newPaths
     * @param  list<string|null>  $oldPaths
     */
    protected function saveProfileSettings(array $values, string $description, User $actor, array $newPaths = [], array $oldPaths = []): void
    {
        $changed = [];

        foreach ($values as $key => $value) {
            $old = Setting::get($key);

            if ($old !== $value) {
                $changed[$key] = ['old' => $old, 'new' => $value];
            }
        }

        $this->saveWithFiles($newPaths, $oldPaths, function () use ($changed, $description, $actor): void {
            foreach ($changed as $key => $change) {
                Setting::set($key, $change['new']);
            }

            if ($changed !== []) {
                activity('konten')
                    ->causedBy($actor)
                    ->event('updated')
                    ->withProperties(['changed' => $changed])
                    ->log($description);
            }
        });
    }
}
