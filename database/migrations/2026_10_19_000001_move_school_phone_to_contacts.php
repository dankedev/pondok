<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * F044: telepon kantor `school.phone` menjadi kontak berlabel bertanda cetak (data saja).
 *
 * - Nomor yang (setelah normalisasi) sama dengan kontak yang ada: kontak itu diberi
 *   `is_print = true`, kontak lain `false`, tanpa kontak baru;
 * - nomor baru: kontak `Kantor` non-WhatsApp bertanda cetak ditambahkan di akhir daftar,
 *   nilai disalin apa adanya (hanya `trim`) agar teks cetakan tetap sama;
 * - `school.phone` dihapus (juga bila kosong). Jalan kedua tidak menemukan key: idempotent.
 *
 * `down()` menulis `school.phone` dari kontak bertanda pertama; kontak dan tanda dibiarkan
 * (lossy wajar). Ditulis dengan `DB::table` agar tidak bergantung pada model.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('settings')->where('key', 'school.phone')->exists()) {
            return;
        }

        $value = $this->read('school.phone');
        $phone = is_scalar($value) && ! is_bool($value) ? trim((string) $value) : '';

        DB::transaction(function () use ($phone): void {
            if ($phone !== '') {
                $this->write('school.contacts', $this->markPrintContact($this->list('school.contacts'), $phone));
            }

            DB::table('settings')->where('key', 'school.phone')->delete();
        });

        $this->forgetSettingsCache();
    }

    public function down(): void
    {
        foreach ($this->list('school.contacts') as $contact) {
            $phone = $contact['phone'] ?? null;

            if (($contact['is_print'] ?? false) === true && is_scalar($phone) && trim((string) $phone) !== '') {
                $this->write('school.phone', (string) $phone);
                $this->forgetSettingsCache();

                return;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $contacts
     * @return list<array<string, mixed>>
     */
    private function markPrintContact(array $contacts, string $phone): array
    {
        $normalized = $this->normalizePhone($phone);
        $matched = false;

        foreach ($contacts as $index => $contact) {
            $isMatch = ! $matched && $this->normalizePhone($contact['phone'] ?? null) === $normalized;
            $contacts[$index]['is_print'] = $isMatch;
            $matched = $matched || $isMatch;
        }

        if (! $matched) {
            $contacts[] = ['key' => $this->contactKey($contacts), 'label' => 'Kantor', 'name' => null, 'phone' => $phone, 'is_whatsapp' => false, 'is_print' => true];
        }

        return $contacts;
    }

    /**
     * Buang cache settings per proses (binding scoped `pondok.settings`) agar pembaca
     * berikutnya di proses yang sama melihat data hasil migrasi.
     */
    private function forgetSettingsCache(): void
    {
        app()->forgetInstance('pondok.settings');
    }

    private function read(string $key): mixed
    {
        $value = DB::table('settings')->where('key', $key)->value('value');

        return is_string($value) ? json_decode($value, true) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(string $key): array
    {
        $value = $this->read($key);

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private function write(string $key, mixed $value): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (DB::table('settings')->where('key', $key)->exists()) {
            DB::table('settings')->where('key', $key)->update(['value' => $encoded, 'updated_at' => now()]);

            return;
        }

        DB::table('settings')->insert(['key' => $key, 'value' => $encoded, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function normalizePhone(mixed $value): ?string
    {
        $phone = is_scalar($value) ? str_replace([' ', '-', '.', '(', ')'], '', trim((string) $value)) : '';

        return $phone === '' ? null : $phone;
    }

    /**
     * @param  list<array<string, mixed>>  $contacts
     */
    private function contactKey(array $contacts): string
    {
        $used = array_column($contacts, 'key');

        do {
            $key = strtolower(Str::random(8));
        } while (in_array($key, $used, true) || preg_match('/^[a-z0-9]{8}$/', $key) !== 1);

        return $key;
    }
};
