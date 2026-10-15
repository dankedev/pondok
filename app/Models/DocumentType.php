<?php

namespace App\Models;

use App\Models\Scopes\UnitScope;
use Database\Factories\DocumentTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenis dokumen (KK, akta, ijazah, ...). Milik modul Dokumen (F013), dibuat
 * lebih awal karena syarat berkas PSB merujuknya. Tidak dihapus, cukup
 * dinonaktifkan.
 */
#[Fillable(['code', 'name', 'owner', 'is_required', 'has_expiry', 'is_confidential', 'is_active'])]
class DocumentType extends Model
{
    /** @use HasFactory<DocumentTypeFactory> */
    use HasFactory;

    public const OWNER_STUDENT = 'santri';

    public const OWNER_GUARDIAN = 'wali';

    public const OWNER_STAFF = 'staff';

    public const OWNER_ACHIEVEMENT = 'prestasi';

    public const OWNERS = [
        self::OWNER_STUDENT,
        self::OWNER_GUARDIAN,
        self::OWNER_STAFF,
        self::OWNER_ACHIEVEMENT,
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'has_expiry' => 'boolean',
            'is_confidential' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Dipakai dokumen (semua jenjang) atau syarat berkas PSB. Jenis yang dipakai
     * tidak bisa dihapus dan owner-nya tidak bisa diubah.
     */
    public function isInUse(): bool
    {
        return $this->documents()->withoutGlobalScope(UnitScope::class)->exists()
            || $this->admissionRequirements()->exists();
    }

    public function ownerLabel(): string
    {
        return __("pondok.document_type.owners.{$this->owner}");
    }

    public function admissionRequirements(): HasMany
    {
        return $this->hasMany(AdmissionRequirement::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeForStudents(Builder $query): void
    {
        $query->where('owner', self::OWNER_STUDENT);
    }
}
