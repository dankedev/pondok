<?php

namespace App\Models;

use App\Models\Concerns\HasUnitScope;
use Database\Factories\ApplicantDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Berkas pendaftar untuk satu syarat. Satu baris per pendaftar per syarat;
 * unggah ulang menimpa baris dan file lama. File di disk `private`, diunduh
 * hanya lewat route ber-sesi (publik) atau ber-permission (panel).
 *
 * Cakupan jenjang dibaca lewat pendaftar, termasuk pendaftar terhapus.
 */
#[Fillable(['applicant_id', 'admission_requirement_id', 'file_path', 'original_name', 'mime_type', 'size_kb', 'status', 'note', 'verified_by', 'verified_at'])]
class ApplicantDocument extends Model
{
    /** @use HasFactory<ApplicantDocumentFactory> */
    use HasFactory, HasUnitScope, LogsActivity;

    public const DISK = 'private';

    public const STATUS_PENDING = 'menunggu';

    public const STATUS_VALID = 'valid';

    public const STATUS_REJECTED = 'ditolak';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VALID,
        self::STATUS_REJECTED,
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'size_kb' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('psb')
            ->logOnly(['status', 'note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * @param  list<int>  $unitIds
     */
    public function applyUnitScope(Builder $builder, array $unitIds): void
    {
        $builder->whereIn(
            $this->qualifyColumn('applicant_id'),
            Applicant::withoutGlobalScopes()->whereIn('unit_id', $unitIds)->select('id'),
        );
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class)->withTrashed();
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(AdmissionRequirement::class, 'admission_requirement_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Warna badge sesuai design guide §4.
     */
    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_VALID => 'success',
            self::STATUS_REJECTED => 'danger',
            default => 'warning',
        };
    }

    public function statusLabel(): string
    {
        return __("pondok.applicant_document.statuses.{$this->status}");
    }
}
