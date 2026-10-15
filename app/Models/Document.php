<?php

namespace App\Models;

use App\Models\Concerns\HasUnitScope;
use Carbon\CarbonInterface;
use Database\Factories\DocumentFactory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Dokumen santri, wali, atau staff (polymorphic lewat alias morph).
 *
 * Tidak pernah diubah atau dihapus dari panel: versi baru menambah baris dan
 * menandai baris lama lewat `replaced_by_id`, koreksi verifikasi lewat status
 * `ditolak`. Berkas di disk `private`, nomor (KK/NIK) terenkripsi.
 *
 * Cakupan jenjang: dokumen santri lewat jenjang santri (termasuk yang
 * diarsipkan), dokumen wali lewat santri yang terhubung, dokumen staff selalu
 * terlihat, tipe lain tidak terlihat oleh user bercakupan.
 */
#[Fillable(['document_type_id', 'documentable_type', 'documentable_id', 'number', 'issued_on', 'expires_on', 'file_path', 'original_name', 'mime_type', 'size_kb', 'status', 'verified_by', 'verified_at', 'uploaded_by', 'replaced_by_id', 'source_applicant_document_id'])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, HasUnitScope;

    public const STATUS_PENDING = 'menunggu';

    public const STATUS_VALID = 'valid';

    public const STATUS_REJECTED = 'ditolak';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VALID,
        self::STATUS_REJECTED,
    ];

    /** Status tampilan untuk versi yang sudah diganti (dihitung dari `replaced_by_id`). */
    public const DISPLAY_REPLACED = 'diganti';

    /** Warna badge sesuai design guide §4. */
    public const STATUS_COLORS = [
        self::STATUS_PENDING => 'warning',
        self::STATUS_VALID => 'success',
        self::STATUS_REJECTED => 'danger',
        self::DISPLAY_REPLACED => 'gray',
    ];

    public const DISK = 'private';

    public const DIRECTORY = 'documents';

    public const MAX_SIZE_KB = 2048;

    /** MIME yang diterima, dengan ekstensi berkas tersimpan. */
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'application/pdf'];

    public const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    public const NUMBER_MAX_LENGTH = 50;

    /** Alias morph pemilik dokumen. F015 menambah `achievement`. */
    public const DOCUMENTABLE_TYPES = [
        'student' => Student::class,
        'guardian' => Guardian::class,
        'staff' => Staff::class,
    ];

    /** `document_types.owner` ke alias morph. */
    public const OWNER_TO_MORPH = [
        DocumentType::OWNER_STUDENT => 'student',
        DocumentType::OWNER_GUARDIAN => 'guardian',
        DocumentType::OWNER_STAFF => 'staff',
        DocumentType::OWNER_ACHIEVEMENT => 'achievement',
    ];

    /** Permission tambahan agar jenis pemilik terlihat (selain permission dokumen). */
    public const OWNER_TYPE_PERMISSIONS = [
        'student' => null,
        'guardian' => 'update:guardian',
        'staff' => 'update:staff',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'number' => 'encrypted',
            'issued_on' => 'date',
            'expires_on' => 'date',
            'verified_at' => 'datetime',
            'size_kb' => 'integer',
        ];
    }

    /**
     * @param  list<int>  $unitIds
     */
    public function applyUnitScope(Builder $builder, array $unitIds): void
    {
        $students = Student::withoutGlobalScopes()->whereIn('unit_id', $unitIds)->select('id');

        $builder->where(function (Builder $query) use ($students): void {
            $query
                ->where(fn (Builder $query) => $query
                    ->where($this->qualifyColumn('documentable_type'), 'student')
                    ->whereIn($this->qualifyColumn('documentable_id'), $students))
                ->orWhere(fn (Builder $query) => $query
                    ->where($this->qualifyColumn('documentable_type'), 'guardian')
                    ->whereIn($this->qualifyColumn('documentable_id'), fn ($sub) => $sub
                        ->select('guardian_id')
                        ->from('student_guardian')
                        ->whereIn('student_id', $students)))
                ->orWhere($this->qualifyColumn('documentable_type'), 'staff');
        });
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    public function previousVersion(): HasOne
    {
        return $this->hasOne(self::class, 'replaced_by_id');
    }

    /**
     * Versi terbaru (belum diganti).
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('replaced_by_id'));
    }

    /**
     * Masa berlaku sudah lewat (sebelum `$today`).
     */
    public function scopeExpired(Builder $query, ?CarbonInterface $today = null): void
    {
        $query->where($this->qualifyColumn('expires_on'), '<', ($today ?? today())->toDateString());
    }

    /**
     * `diganti` bila sudah ada versi baru, selain itu status verifikasi.
     */
    protected function displayStatus(): Attribute
    {
        return Attribute::get(fn (): string => $this->replaced_by_id !== null ? self::DISPLAY_REPLACED : (string) $this->status);
    }

    public function isCurrent(): bool
    {
        return $this->replaced_by_id === null;
    }

    /**
     * Kerahasiaan mengikuti jenis dokumen saat ini, bukan snapshot.
     */
    public function isConfidential(): bool
    {
        return (bool) $this->documentType?->is_confidential;
    }

    public function isExpired(?CarbonInterface $today = null): bool
    {
        return $this->expires_on !== null && $this->expires_on->toDateString() < ($today ?? today())->toDateString();
    }

    /**
     * Nomor dimasker untuk tabel: 4 karakter awal dan akhir, sisanya `•`.
     */
    public function maskedNumber(): ?string
    {
        $number = $this->number;

        if ($number === null) {
            return null;
        }

        $length = mb_strlen($number);

        if ($length <= 8) {
            return str_repeat('•', $length);
        }

        return mb_substr($number, 0, 4).str_repeat('•', $length - 8).mb_substr($number, -4);
    }

    public function ownerAlias(): string
    {
        return (string) $this->documentable_type;
    }

    public function statusLabel(): string
    {
        return __("pondok.document.statuses.{$this->display_status}");
    }

    /**
     * Ekstensi berkas tersimpan, misalnya `pdf`.
     */
    public function fileExtension(): string
    {
        return pathinfo((string) $this->file_path, PATHINFO_EXTENSION) ?: (self::EXTENSIONS[$this->mime_type] ?? 'bin');
    }

    /**
     * Alias morph untuk model pemilik, atau null bila tidak didukung.
     */
    public static function aliasFor(Model $owner): ?string
    {
        $alias = array_search($owner::class, self::DOCUMENTABLE_TYPES, true);

        return $alias === false ? null : $alias;
    }

    /**
     * Jenis pemilik (alias morph) yang dokumennya boleh dilihat user, di luar
     * cakupan jenjang. Super-admin melihat semua, termasuk tipe yang belum dikenal.
     *
     * @return list<string>
     */
    public static function visibleOwnerTypesFor(User $user): array
    {
        if (static::seesEveryOwnerType($user)) {
            return array_values(array_unique([...array_keys(self::DOCUMENTABLE_TYPES), ...array_values(self::OWNER_TO_MORPH)]));
        }

        return array_values(array_filter(
            array_keys(self::OWNER_TYPE_PERMISSIONS),
            fn (string $alias): bool => self::OWNER_TYPE_PERMISSIONS[$alias] === null || $user->can(self::OWNER_TYPE_PERMISSIONS[$alias]),
        ));
    }

    public static function seesEveryOwnerType(User $user): bool
    {
        return $user->hasRole(RolePermissionSeeder::SUPER_ADMIN);
    }

    public static function userSeesOwnerType(User $user, string $alias): bool
    {
        return static::seesEveryOwnerType($user) || in_array($alias, static::visibleOwnerTypesFor($user), true);
    }
}
