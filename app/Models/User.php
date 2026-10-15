<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'is_active', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /** @var list<int>|null */
    protected ?array $scopedUnitIdsCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->can('view-dashboard');
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
