<?php

namespace App\Models;

use App\Models\Concerns\HasDocuments;
use App\Models\Concerns\HasUnitScope;
use Closure;
use Database\Factories\AchievementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Prestasi santri (F015), individu atau beregu, dicatat lewat
 * `App\Actions\Prestasi\SaveAchievement`. Tanpa soft delete.
 *
 * Cakupan jenjang lewat santri peserta (termasuk yang diarsipkan): terlihat
 * bila minimal satu peserta dalam cakupan user, dikelola hanya bila semua
 * peserta dalam cakupan (`isFullyVisibleTo`). Tanpa peserta tidak terlihat
 * oleh user bercakupan (fail-closed).
 */
#[Fillable(['academic_year_id', 'category', 'title', 'event_name', 'organizer', 'level', 'kind', 'achieved_on', 'recorded_by', 'verified_by', 'is_public'])]
class Achievement extends Model
{
    /** @use HasFactory<AchievementFactory> */
    use HasDocuments, HasFactory, HasUnitScope;

    public const CATEGORIES = ['tahfidz', 'akademik', 'keagamaan', 'olahraga', 'seni', 'organisasi', 'lainnya'];

    /** Urut dari tingkat terendah ke tertinggi. */
    public const LEVELS = ['pondok', 'kecamatan', 'kabupaten', 'provinsi', 'nasional', 'internasional'];

    public const KIND_INDIVIDUAL = 'individu';

    public const KIND_TEAM = 'beregu';

    public const KINDS = [self::KIND_INDIVIDUAL, self::KIND_TEAM];

    public const MAX_PARTICIPANTS = 50;

    public const RANK_MAX_LENGTH = 50;

    public const ROLE_MAX_LENGTH = 50;

    public const STATUS_VERIFIED = 'terverifikasi';

    public const STATUS_UNVERIFIED = 'belum';

    /** Warna badge sesuai design guide §4. */
    public const VERIFICATION_COLORS = [
        self::STATUS_VERIFIED => 'success',
        self::STATUS_UNVERIFIED => 'warning',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_public' => false,
    ];

    protected function casts(): array
    {
        return [
            'achieved_on' => 'date',
            'is_public' => 'boolean',
        ];
    }

    /**
     * @param  list<int>  $unitIds
     */
    public function applyUnitScope(Builder $builder, array $unitIds): void
    {
        $builder->whereExists($this->participantsInUnits($unitIds));
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Peserta, termasuk santri yang diarsipkan; cakupan jenjang santri tetap berlaku.
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class)->withPivot(['rank', 'role'])->withTrashed();
    }

    /**
     * Berita dari prestasi ini (satu per prestasi), termasuk yang dihapus.
     */
    public function post(): HasOne
    {
        return $this->hasOne(Post::class)->withTrashed();
    }

    protected function isVerified(): Attribute
    {
        return Attribute::get(fn (): bool => $this->verified_by !== null);
    }

    protected function verificationStatus(): Attribute
    {
        return Attribute::get(fn (): string => $this->verified_by !== null ? self::STATUS_VERIFIED : self::STATUS_UNVERIFIED);
    }

    public function scopeForAcademicYear(Builder $query, int $academicYearId): void
    {
        $query->where($this->qualifyColumn('academic_year_id'), $academicYearId);
    }

    /**
     * Prestasi yang punya peserta (termasuk terarsip) di jenjang `$unitId`.
     */
    public function scopeForUnit(Builder $query, int $unitId): void
    {
        $query->whereExists($this->participantsInUnits([$unitId]));
    }

    public function scopeVerified(Builder $query): void
    {
        $query->whereNotNull($this->qualifyColumn('verified_by'));
    }

    /**
     * Boleh dijadikan berita: publik, terverifikasi, dan belum punya berita (termasuk yang dihapus).
     */
    public function scopePublishable(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_public'), true)
            ->whereNotNull($this->qualifyColumn('verified_by'))
            ->whereNotExists(fn (QueryBuilder $sub) => $sub
                ->select(DB::raw(1))
                ->from('posts')
                ->whereColumn('posts.achievement_id', $this->qualifyColumn('id')));
    }

    /**
     * Atribut opsional dari query tabel (`withOutsideParticipantsFor`): ada peserta di luar
     * cakupan user login. Dipakai `isFullyVisibleTo` agar tanpa query per baris.
     */
    public const OUTSIDE_PARTICIPANTS_ATTRIBUTE = 'has_outside_participants';

    /** @var array<int, bool> */
    private array $fullyVisibleCache = [];

    /**
     * User tanpa cakupan, atau semua peserta (termasuk terarsip) berada dalam cakupan user.
     */
    public function isFullyVisibleTo(User $user): bool
    {
        $unitIds = $user->scopedUnitIds();

        if ($unitIds === []) {
            return true;
        }

        if (array_key_exists(self::OUTSIDE_PARTICIPANTS_ATTRIBUTE, $this->attributes) && Auth::id() === $user->getKey()) {
            return ! (bool) $this->attributes[self::OUTSIDE_PARTICIPANTS_ATTRIBUTE];
        }

        return $this->fullyVisibleCache[$user->getKey()] ??= DB::table('achievement_student')
            ->join('students', 'students.id', '=', 'achievement_student.student_id')
            ->where('achievement_student.achievement_id', $this->getKey())
            ->whereNotIn('students.unit_id', $unitIds)
            ->doesntExist();
    }

    /**
     * Muat `has_outside_participants` untuk cakupan `$user` (dipakai tabel prestasi).
     */
    public function scopeWithOutsideParticipantsFor(Builder $query, ?User $user): void
    {
        $unitIds = $user?->scopedUnitIds() ?? [];

        if ($unitIds === []) {
            return;
        }

        $query->withExists([
            'students as '.self::OUTSIDE_PARTICIPANTS_ATTRIBUTE => fn (Builder $query) => $query
                ->withoutGlobalScopes()
                ->whereNotIn('students.unit_id', $unitIds),
        ]);
    }

    public function hasPost(): bool
    {
        return $this->relationLoaded('post') ? $this->post !== null : $this->post()->exists();
    }

    /**
     * Boleh dihapus: belum terverifikasi, tanpa berita (termasuk terhapus), tanpa dokumen (tanpa cakupan
     * jenjang). Tabel memuat `has_documents` lewat `withExists` agar tanpa query per baris.
     */
    public function isDeletable(): bool
    {
        $hasDocuments = array_key_exists('has_documents', $this->attributes)
            ? (bool) $this->attributes['has_documents']
            : $this->hasAnyDocuments();

        return ! $this->is_verified && ! $this->hasPost() && ! $hasDocuments;
    }

    public function isPublishable(): bool
    {
        return $this->is_public && $this->is_verified && ! $this->hasPost();
    }

    /**
     * @param  list<int>  $unitIds
     */
    private function participantsInUnits(array $unitIds): Closure
    {
        return fn (QueryBuilder $sub) => $sub
            ->select(DB::raw(1))
            ->from('achievement_student')
            ->join('students', 'students.id', '=', 'achievement_student.student_id')
            ->whereColumn('achievement_student.achievement_id', $this->qualifyColumn('id'))
            ->whereIn('students.unit_id', $unitIds);
    }
}
