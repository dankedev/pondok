<?php

namespace App\Actions\Psb;

use App\Models\AdmissionRequirement;
use App\Models\DocumentType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membuat atau mengubah syarat berkas PSB.
 *
 * Cakupan jenjang dibaca dari user login: user bercakupan hanya mengelola
 * syarat jenjangnya dan tidak bisa membuat atau mengubah syarat semua
 * jenjang. Jenis dokumen harus milik santri dan aktif (kecuali nilai yang
 * sudah tersimpan). Satu jenis dokumen paling banyak satu syarat per jenjang,
 * dan syarat semua jenjang tidak berdampingan dengan syarat per jenjang untuk
 * jenis yang sama.
 */
class SaveAdmissionRequirement
{
    /**
     * @param  array{unit_id?: mixed, document_type_id: mixed, name: mixed, is_required?: mixed, sort_order?: mixed}  $data
     */
    public function handle(array $data, ?AdmissionRequirement $requirement = null): AdmissionRequirement
    {
        $isScoped = $this->scopedUnitIds() !== [];

        if ($requirement !== null) {
            if ($requirement->unit_id === null && $isScoped) {
                $this->fail('unit_id', 'all_units_requires_unscoped');
            }

            if ($requirement->unit_id !== null && ! $this->isAccessibleUnit($requirement->unit_id)) {
                $this->fail('unit_id', 'unit_not_found');
            }
        }

        $unitId = blank($data['unit_id'] ?? null) ? null : (int) $data['unit_id'];

        if ($unitId === null && $isScoped) {
            $this->fail('unit_id', 'all_units_requires_unscoped');
        }

        if ($unitId !== null && ! $this->isAccessibleUnit($unitId)) {
            $this->fail('unit_id', 'unit_not_found');
        }

        $documentTypeId = $this->documentTypeId($data['document_type_id'] ?? null, $requirement);

        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 255) {
            $this->fail('name', 'name_required');
        }

        $sortOrder = $data['sort_order'] ?? null;

        if (blank($sortOrder)) {
            $sortOrder = 0;
        } else {
            $sortOrder = $this->sortOrder($sortOrder) ?? $this->fail('sort_order', 'sort_order_invalid');
        }

        return DB::transaction(function () use ($data, $requirement, $unitId, $documentTypeId, $name, $sortOrder): AdmissionRequirement {
            $conflict = AdmissionRequirement::query()
                ->where('document_type_id', $documentTypeId)
                ->when($requirement, fn ($query, AdmissionRequirement $requirement) => $query->whereKeyNot($requirement->getKey()))
                ->when($unitId !== null, fn ($query) => $query->where(fn ($query) => $query->whereNull('unit_id')->orWhere('unit_id', $unitId)))
                ->exists();

            if ($conflict) {
                $this->fail('document_type_id', 'requirement_exists');
            }

            $requirement ??= new AdmissionRequirement;
            $requirement->fill([
                'unit_id' => $unitId,
                'document_type_id' => $documentTypeId,
                'name' => $name,
                'is_required' => (bool) ($data['is_required'] ?? true),
                'sort_order' => $sortOrder,
            ])->save();

            return $requirement;
        });
    }

    private function documentTypeId(mixed $value, ?AdmissionRequirement $requirement): int
    {
        $documentType = blank($value) ? null : DocumentType::query()->find((int) $value);

        if ($documentType === null) {
            $this->fail('document_type_id', 'document_type_not_found');
        }

        if ($documentType->owner !== DocumentType::OWNER_STUDENT) {
            $this->fail('document_type_id', 'document_type_not_for_student');
        }

        if (! $documentType->is_active && $documentType->id !== $requirement?->document_type_id) {
            $this->fail('document_type_id', 'document_type_inactive');
        }

        return $documentType->id;
    }

    /**
     * @return list<int>
     */
    private function scopedUnitIds(): array
    {
        $user = Auth::user();

        return $user instanceof User ? $user->scopedUnitIds() : [];
    }

    private function isAccessibleUnit(int $unitId): bool
    {
        return Unit::query()->accessible()->whereKey($unitId)->exists();
    }

    /**
     * Bilangan bulat 0-255 (int atau string berisi digit saja); selain itu null.
     */
    private function sortOrder(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $value = filter_var(trim($value), FILTER_VALIDATE_INT);
        }

        return is_int($value) && $value >= 0 && $value <= 255 ? $value : null;
    }

    private function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.psb.errors.{$reason}"),
        ]);
    }
}
