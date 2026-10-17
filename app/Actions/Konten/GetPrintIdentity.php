<?php

namespace App\Actions\Konten;

use App\Models\Setting;

/**
 * Identitas lembaga untuk cetakan (kop surat dan kuitansi) dengan satu query settings.
 *
 * Nama jatuh ke `config('app.name')`. Telepon diambil dari kontak bertanda `is_print`
 * pertama, apa adanya; tanpa kontak bertanda, telepon `null` dan baris tidak dicetak.
 */
class GetPrintIdentity
{
    /**
     * @return array{name: string, address: ?string, phone: ?string}
     */
    public function handle(): array
    {
        $values = Setting::getMany(['school.name', 'school.address', 'school.contacts']);

        return [
            'name' => $this->text($values['school.name']) ?? (string) config('app.name'),
            'address' => $this->text($values['school.address']),
            'phone' => $this->printPhone($values['school.contacts']),
        ];
    }

    private function printPhone(mixed $contacts): ?string
    {
        foreach (is_array($contacts) ? $contacts : [] as $contact) {
            if (is_array($contact) && ($contact['is_print'] ?? false) === true) {
                return $this->text($contact['phone'] ?? null);
            }
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        return is_scalar($value) && ! is_bool($value) && trim((string) $value) !== '' ? (string) $value : null;
    }
}
