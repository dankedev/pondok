<?php

namespace App\Actions\Core;

use App\Models\Staff;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;

/**
 * Membuat atau memperbarui satu pegawai dari baris template. Akun login
 * (`user_id`) tidak pernah disentuh; kolom opsional kosong tidak menghapus data.
 */
class ImportStaffRow
{
    /**
     * @param  array<string, mixed>  $row
     *
     * @throws RowImportFailedException
     */
    public function handle(array $row): Staff
    {
        $nip = $this->clean($row['nip'] ?? null);
        $phone = $this->clean($row['phone'] ?? null);
        $name = (string) $this->clean($row['name'] ?? null);
        $isActive = match ($this->clean($row['is_active'] ?? null)) {
            'ya' => true,
            'tidak' => false,
            default => null,
        };

        $staff = $nip !== null
            ? Staff::query()->where('nip', $nip)->first()
            : $this->findByNameAndPhone($name, $phone);

        $staff ??= new Staff(['is_active' => true]);

        $staff->fill(array_filter([
            'nip' => $nip,
            'phone' => $phone,
            'is_active' => $isActive,
        ], fn (mixed $value): bool => $value !== null) + [
            'name' => $name,
            'gender' => $this->clean($row['gender'] ?? null),
            'position' => $this->clean($row['position'] ?? null),
        ])->save();

        return $staff;
    }

    private function findByNameAndPhone(string $name, ?string $phone): ?Staff
    {
        $matches = Staff::query()->get()->filter(
            fn (Staff $staff): bool => $this->same($staff->name, $name) && $this->same($staff->phone, $phone),
        );

        if ($matches->count() > 1) {
            throw new RowImportFailedException(__('pondok.staff.import.errors.ambiguous'));
        }

        return $matches->first();
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Dibandingkan di PHP: `lower()` SQLite tidak mengecilkan huruf non-ASCII.
     */
    private function same(?string $stored, ?string $given): bool
    {
        return mb_strtolower(trim((string) $stored)) === mb_strtolower(trim((string) $given));
    }
}
