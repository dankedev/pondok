<?php

namespace App\Models;

use App\Models\Concerns\HasDocuments;
use App\Models\Concerns\HasUnitScope;
use Carbon\CarbonInterface;
use Closure;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Santri semua jenjang. `unit_id` diduplikasi dari `cohorts.unit_id` untuk
 * scope jenjang, dan hanya diubah lewat Action `ChangeStudentUnit`.
 */
#[Fillable([
    'unit_id', 'cohort_id', 'applicant_id', 'nis', 'nisn', 'name', 'gender',
    'birth_place', 'birth_date', 'address', 'status', 'entered_on', 'photo_path',
])]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasDocuments, HasFactory, HasUnitScope, SoftDeletes;

    public const STATUS_ACTIVE = 'aktif';

    public const STATUSES = ['aktif', 'lulus', 'keluar', 'pindah'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'entered_on' => 'date',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * Rombel santri di tahun ajaran yang sedang aktif.
     */
    public function currentEnrollment(): HasOne
    {
        return $this->hasOne(Enrollment::class)
            ->whereIn('academic_year_id', AcademicYear::query()->active()->select('id'));
    }

    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'student_guardian')
            ->withPivot(['relation', 'is_billing_contact'])
            ->withTimestamps();
    }

    public function feeOverrides(): HasMany
    {
        return $this->hasMany(FeeOverride::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Surat keluar untuk santri ini (F014).
     */
    /**
     * Prestasi yang diikuti santri (F015), dengan peringkat dan peran.
     */
    public function achievements(): BelongsToMany
    {
        return $this->belongsToMany(Achievement::class)->withPivot(['rank', 'role']);
    }

    public function issuedLetters(): HasMany
    {
        return $this->hasMany(IssuedLetter::class);
    }

    /**
     * Riwayat keanggotaan halaqah tahfidz (F023).
     */
    public function halaqahMembers(): HasMany
    {
        return $this->hasMany(HalaqahMember::class);
    }

    /**
     * Capaian hafalan per juz; juz tanpa baris berarti belum (F023).
     */
    public function juzProgress(): HasMany
    {
        return $this->hasMany(StudentJuzProgress::class);
    }

    /**
     * Riwayat kunjungan kesehatan (F031).
     */
    public function healthVisits(): HasMany
    {
        return $this->hasMany(HealthVisit::class);
    }

    /**
     * Pelanggaran berpoin (F026), termasuk yang dibatalkan.
     */
    public function violations(): HasMany
    {
        return $this->hasMany(StudentViolation::class);
    }

    /**
     * Izin keluar/pulang (F024).
     */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * Kunjungan wali / sambangan (F024).
     */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    /**
     * Kehadiran harian per rombel (F025).
     */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function roomAssignments(): HasMany
    {
        return $this->hasMany(RoomAssignment::class);
    }

    /**
     * Penempatan kamar yang sedang berjalan. Ada berarti santri mukim.
     */
    public function activeRoomAssignment(): HasOne
    {
        return $this->hasOne(RoomAssignment::class)->whereNull('end_on');
    }

    public function activeRoom(): HasOneThrough
    {
        return $this->hasOneThrough(Room::class, RoomAssignment::class, 'student_id', 'id', 'id', 'room_id')
            ->whereNull('room_assignments.end_on');
    }

    public function isResident(): bool
    {
        return $this->activeRoomAssignment()->exists();
    }

    public function scopeResident(Builder $query): void
    {
        $query->whereHas('activeRoomAssignment');
    }

    public function scopeNonResident(Builder $query): void
    {
        $query->whereDoesntHave('activeRoomAssignment');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Santri yang boleh dilihat dan dicatat `$user` di modul pembinaan (kesehatan, perizinan), dalam
     * cakupan jenjangnya `unit_user` (tanpa bergantung pada user login).
     *
     * Pemegang `view-any:student` (kesantrian, super-admin) melihat semua santri. Selain itu hanya
     * santri dengan penempatan kamar aktif di asrama/kamar yang ia awasi (`RoomAssignment::visibleTo`;
     * pemegang `view-any:dormitory` tanpa izin santri karenanya melihat semua santri mukim).
     *
     * @return Builder<Student>
     */
    public static function visibleStudentsFor(User $user): Builder
    {
        $unitIds = $user->scopedUnitIds();

        $students = static::withoutUnitScope()->when($unitIds !== [], fn (Builder $query) => $query->whereIn('unit_id', $unitIds));

        if ($user->can('view-any:student')) {
            return $students;
        }

        return $students->whereIn('id', RoomAssignment::withoutUnitScope()->active()->visibleTo($user)->select('student_id'));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Santri yang belum memiliki semua berkas wajib (jenis wajib aktif milik
     * santri tanpa dokumen current `valid` yang masih berlaku). Konsisten dengan
     * `CheckDocumentCompleteness`; tidak terpengaruh cakupan jenjang dokumen.
     */
    public function scopeWithIncompleteDocuments(Builder $query, ?CarbonInterface $today = null): void
    {
        $query->whereExists($this->missingRequiredDocumentTypes($today));
    }

    /**
     * Kebalikan `withIncompleteDocuments`. Tanpa jenis wajib aktif, semua santri lengkap.
     */
    public function scopeWithCompleteDocuments(Builder $query, ?CarbonInterface $today = null): void
    {
        $query->whereNotExists($this->missingRequiredDocumentTypes($today));
    }

    /**
     * Subquery jenis wajib aktif yang belum terpenuhi untuk baris `students`.
     */
    private function missingRequiredDocumentTypes(?CarbonInterface $today): Closure
    {
        $date = ($today ?? today())->toDateString();
        $students = $this->getTable();

        return fn (QueryBuilder $types) => $types
            ->select('document_types.id')
            ->from('document_types')
            ->where('document_types.is_required', true)
            ->where('document_types.is_active', true)
            ->where('document_types.owner', DocumentType::OWNER_STUDENT)
            ->whereNotExists(fn (QueryBuilder $documents) => $documents
                ->select('documents.id')
                ->from('documents')
                ->whereColumn('documents.document_type_id', 'document_types.id')
                ->where('documents.documentable_type', $this->getMorphClass())
                ->whereColumn('documents.documentable_id', "{$students}.id")
                ->whereNull('documents.replaced_by_id')
                ->where('documents.status', Document::STATUS_VALID)
                ->where(fn (QueryBuilder $expiry) => $expiry
                    ->whereNull('documents.expires_on')
                    ->orWhere('documents.expires_on', '>=', $date)));
    }
}
