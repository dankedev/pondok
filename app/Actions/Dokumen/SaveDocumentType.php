<?php

namespace App\Actions\Dokumen;

use App\Models\DocumentType;
use Illuminate\Validation\ValidationException;

/**
 * Membuat atau mengubah jenis dokumen.
 *
 * Kode di-uppercase dan tidak bisa diubah setelah disimpan (dipakai
 * `DocumentTypeSeeder` untuk idempotensi). Owner tidak bisa diubah bila jenis
 * sudah dipakai dokumen (semua jenjang) atau syarat PSB. Hanya jenis santri
 * yang bisa wajib.
 */
class SaveDocumentType
{
    /**
     * @param  array{code?: mixed, name?: mixed, owner?: mixed, is_required?: mixed, has_expiry?: mixed, is_confidential?: mixed, is_active?: mixed}  $data
     */
    public function handle(array $data, ?DocumentType $type = null): DocumentType
    {
        $code = $type?->code ?? $this->code($data['code'] ?? null);

        if ($type !== null && array_key_exists('code', $data) && filled($data['code']) && mb_strtoupper(trim((string) $data['code'])) !== $type->code) {
            $this->fail('code', 'code_locked');
        }

        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 255) {
            $this->fail('name', 'name_required');
        }

        $owner = $data['owner'] ?? $type?->owner;

        if (! is_string($owner) || ! in_array($owner, DocumentType::OWNERS, true)) {
            $this->fail('owner', 'owner_invalid');
        }

        if ($type !== null && $owner !== $type->owner && $type->isInUse()) {
            $this->fail('owner', 'owner_locked');
        }

        $isRequired = (bool) ($data['is_required'] ?? false);

        if ($isRequired && $owner !== DocumentType::OWNER_STUDENT) {
            $this->fail('is_required', 'required_only_for_student');
        }

        $type ??= new DocumentType;
        $type->fill([
            'code' => $code,
            'name' => $name,
            'owner' => $owner,
            'is_required' => $isRequired,
            'has_expiry' => (bool) ($data['has_expiry'] ?? false),
            'is_confidential' => (bool) ($data['is_confidential'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ])->save();

        return $type;
    }

    private function code(mixed $value): string
    {
        $code = mb_strtoupper(trim((string) $value));

        if (preg_match('/^[A-Z0-9_]{2,30}$/', $code) !== 1) {
            $this->fail('code', 'code_format');
        }

        if (DocumentType::query()->where('code', $code)->exists()) {
            $this->fail('code', 'code_taken');
        }

        return $code;
    }

    private function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.dokumen.errors.{$reason}"),
        ]);
    }
}
