<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'is_active', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    /** @var list<int>|null */
    protected ?array $scopedUnitIdsCache = null;

    /**
     * Email yang berubah harus diverifikasi ulang, kecuali penyimpanan itu juga
     * mengisi `email_verified_at` (mis. alur ganti email Filament).
     */
    protected static function booted(): void
    {
        static::updating(function (User $user): void {
            if ($user->isDirty('email') && ! $user->isDirty('email_verified_at')) {
                $user->email_verified_at = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Panel `admin` untuk staf (`view-dashboard`), panel `wali` untuk peran `wali` (F022).
     * Staf yang juga wali boleh masuk keduanya.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($panel->getId()) {
            'wali' => $this->hasRole('wali'),
            default => $this->can('view-dashboard'),
        };
    }

    /**
     * Peran berwenang besar tanpa MFA diberi peringatan persisten di panel (F055).
     */
    public function shouldBeWarnedToEnableMultiFactor(): bool
    {
        return $this->hasAnyRole(['super-admin', 'bendahara']) && blank($this->getAppAuthenticationSecret());
    }

    /**
     * Cakupan jenjang user. Kosong berarti boleh melihat semua jenjang.
     */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class)->withTimestamps();
    }

    public function staff(): HasOne
    {
        return $this->hasOne(Staff::class);
    }

    /**
     * Data wali yang tertaut ke akun ini (portal wali, F022).
     */
    public function guardian(): HasOne
    {
        return $this->hasOne(Guardian::class);
    }

    /**
     * Santri yang terhubung dengan wali akun ini. Satu-satunya pintu data portal wali (F022):
     * kepemilikan menggantikan cakupan jenjang, santri diarsipkan tetap tersaring SoftDeletes.
     *
     * @return Builder<Student>
     */
    public function portalStudents(): Builder
    {
        return Student::withoutUnitScope()
            ->whereHas('guardians', fn (Builder $query) => $query->where('guardians.user_id', $this->id));
    }

    public function portalStudent(int $id): Student
    {
        return $this->portalStudents()->findOrFail($id);
    }

    /**
     * Tagihan non-batal milik santri portal.
     *
     * @return Builder<Invoice>
     */
    public function portalInvoices(Student $student): Builder
    {
        return Invoice::withoutUnitScope()
            ->where('student_id', $student->id)
            ->where('status', '!=', Invoice::STATUS_CANCELLED);
    }

    public function portalInvoice(int $id): Invoice
    {
        return Invoice::withoutUnitScope()
            ->whereIn('student_id', $this->portalStudents()->select('students.id'))
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->findOrFail($id);
    }

    /**
     * Dokumen terkini berjenis non-rahasia milik santri portal.
     *
     * @return Builder<Document>
     */
    public function portalDocuments(): Builder
    {
        return Document::withoutUnitScope()
            ->where('documentable_type', 'student')
            ->whereIn('documentable_id', $this->portalStudents()->select('students.id'))
            ->whereNull('replaced_by_id')
            ->whereHas('documentType', fn (Builder $query) => $query->where('is_confidential', false));
    }

    public function portalDocument(int $id): Document
    {
        return $this->portalDocuments()->findOrFail($id);
    }

    /**
     * Keringanan yang disetujui user ini. Selama ada, user tidak bisa dihapus.
     */
    public function approvedFeeOverrides(): HasMany
    {
        return $this->hasMany(FeeOverride::class, 'approved_by');
    }

    /**
     * Tagihan yang dibuat user ini lewat panel. Selama ada, user tidak bisa dihapus.
     */
    public function generatedInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'generated_by');
    }

    /**
     * Pembayaran yang diterima user ini. Selama ada, user tidak bisa dihapus.
     */
    public function receivedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'received_by');
    }

    /**
     * Pembayaran yang dibatalkan user ini. Selama ada, user tidak bisa dihapus.
     */
    public function cancelledPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'cancelled_by');
    }

    /**
     * Surat keluar yang diterbitkan user ini. Selama ada, user tidak bisa dihapus.
     */
    public function issuedLetters(): HasMany
    {
        return $this->hasMany(IssuedLetter::class, 'issued_by');
    }

    /**
     * Surat keluar yang dibatalkan user ini. Selama ada, user tidak bisa dihapus.
     */
    public function cancelledLetters(): HasMany
    {
        return $this->hasMany(IssuedLetter::class, 'cancelled_by');
    }

    /**
     * Prestasi yang dicatat user ini (F015). Selama ada, user tidak bisa dihapus.
     */
    public function recordedAchievements(): HasMany
    {
        return $this->hasMany(Achievement::class, 'recorded_by');
    }

    /**
     * Prestasi yang diverifikasi user ini (F015). Selama ada, user tidak bisa dihapus.
     */
    public function verifiedAchievements(): HasMany
    {
        return $this->hasMany(Achievement::class, 'verified_by');
    }

    /**
     * ID jenjang yang boleh dilihat user. Array kosong berarti tanpa batasan.
     *
     * @return list<int>
     */
    public function scopedUnitIds(): array
    {
        return $this->scopedUnitIdsCache ??= $this->units()
            ->pluck('units.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function flushScopedUnitIds(): void
    {
        $this->scopedUnitIdsCache = null;
    }
}
