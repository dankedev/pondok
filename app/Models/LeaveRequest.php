<?php

namespace App\Models;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveRequestVia;
use App\Models\Concerns\HasUnitScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Izin keluar/pulang santri (F024). Status hanya diubah Action `App\Actions\Perizinan\*`.
 * Tanpa soft delete dan tanpa hapus. "Terlambat" dihitung (`scopeLate`, `is_late`), tidak disimpan.
 *
 * Cakupan jenjang dibaca lewat santri; di atasnya user login dibatasi `scopeVisibleTo`.
 *
 * @property-read bool $is_late
 */
#[Fillable([
    'student_id', 'leave_type_id', 'homecoming_period_id', 'requested_via', 'requested_by', 'reason',
    'planned_departure', 'return_due', 'pickup_name', 'pickup_phone', 'note',
])]
class LeaveRequest extends Model
{
    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory, HasUnitScope;

    public const TEXT_MAX_LENGTH = 1000;

    protected static function booted(): void
    {
        static::addGlobalScope('visible', function (Builder $builder): void {
            $user = Auth::user();

            if ($user instanceof User) {
                $builder->visibleTo($user);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'requested_via' => LeaveRequestVia::class,
            'status' => LeaveRequestStatus::class,
            'planned_departure' => 'datetime',
            'return_due' => 'datetime',
            'approved_at' => 'datetime',
            'departed_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        // Santri yang terhapus (soft delete) tetap tampil di riwayat izin.
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function homecomingPeriod(): BelongsTo
    {
        return $this->belongsTo(HomecomingPeriod::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function checkedOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    /**
     * Izin yang boleh dilihat `$user` (F024 D5):
     * - `view-any:student` (kesantrian, super-admin) → izin semua santri dalam jenjangnya;
     * - `approve:leave-request` (musyrif) → izin santri binaan (`Student::visibleStudentsFor`);
     * - `check-in-out:leave-request` (petugas pos) → hanya izin `disetujui`/`keluar`;
     * - selain itu tidak ada (fail-closed).
     *
     * @param  Builder<LeaveRequest>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can('view-any:student') || $user->can('approve:leave-request')) {
            $query->whereIn($this->qualifyColumn('student_id'), Student::visibleStudentsFor($user)->withTrashed()->select('id'));

            return;
        }

        if ($user->can('check-in-out:leave-request')) {
            $query->whereIn($this->qualifyColumn('status'), [LeaveRequestStatus::Approved->value, LeaveRequestStatus::Out->value]);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    /**
     * Masih di luar padahal batas kembali lewat, atau sudah kembali setelah batas.
     *
     * @param  Builder<LeaveRequest>  $query
     */
    public function scopeLate(Builder $query): void
    {
        $query->where(function (Builder $late): void {
            $late->where(function (Builder $out): void {
                $out->where($this->qualifyColumn('status'), LeaveRequestStatus::Out->value)
                    ->where($this->qualifyColumn('return_due'), '<', now());
            })->orWhere(function (Builder $returned): void {
                $returned->where($this->qualifyColumn('status'), LeaveRequestStatus::Returned->value)
                    ->whereColumn($this->qualifyColumn('returned_at'), '>', $this->qualifyColumn('return_due'));
            });
        });
    }

    /**
     * Izin yang mengunci absensi `$date` menjadi `izin` (F025 D5): santri di luar pondok sepanjang hari itu.
     * Status `keluar` yang berangkat sebelum hari itu (batas atas terbuka sampai dicek kembali, termasuk
     * yang lewat `return_due`), atau `kembali` yang berangkat sebelum hari itu dan kembali sesudahnya.
     *
     * @param  Builder<LeaveRequest>  $query
     */
    public function scopeLockingDate(Builder $query, CarbonInterface $date): void
    {
        [$start, $next] = self::dayBounds($date);

        $query->where($this->qualifyColumn('departed_at'), '<', $start)
            ->where(function (Builder $status) use ($next): void {
                $status->where($this->qualifyColumn('status'), LeaveRequestStatus::Out->value)
                    ->orWhere(function (Builder $returned) use ($next): void {
                        $returned->where($this->qualifyColumn('status'), LeaveRequestStatus::Returned->value)
                            ->where($this->qualifyColumn('returned_at'), '>=', $next);
                    });
            });
    }

    /**
     * Izin disetujui/keluar/kembali yang menyentuh `$date` (F025 D5), hanya sebagai petunjuk di lembar
     * absensi: waktu keluar (atau rencana keluar) paling lambat hari itu, dan waktu kembali (atau batas
     * kembali) paling awal hari itu; status `keluar` tanpa batas atas.
     *
     * @param  Builder<LeaveRequest>  $query
     */
    public function scopeTouchingDate(Builder $query, CarbonInterface $date): void
    {
        [$start, $next] = self::dayBounds($date);
        $status = $this->qualifyColumn('status');

        $query->whereIn($status, [LeaveRequestStatus::Approved->value, LeaveRequestStatus::Out->value, LeaveRequestStatus::Returned->value])
            ->whereRaw("COALESCE({$this->qualifyColumn('departed_at')}, {$this->qualifyColumn('planned_departure')}) < ?", [$next])
            ->where(function (Builder $until) use ($status, $start): void {
                $until->where($status, LeaveRequestStatus::Out->value)
                    ->orWhereRaw("COALESCE({$this->qualifyColumn('returned_at')}, {$this->qualifyColumn('return_due')}) >= ?", [$start]);
            });
    }

    /**
     * Awal hari `$date` dan awal hari berikutnya di zona aplikasi, sebagai string datetime yang
     * dibandingkan langsung dengan kolom (portabel SQLite/MySQL/Postgres).
     *
     * @return array{string, string}
     */
    private static function dayBounds(CarbonInterface $date): array
    {
        $start = CarbonImmutable::parse($date->format('Y-m-d'), config('app.timezone'));

        return [$start->format('Y-m-d H:i:s'), $start->addDay()->format('Y-m-d H:i:s')];
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isLate(): Attribute
    {
        return Attribute::get(fn (): bool => match ($this->status) {
            LeaveRequestStatus::Out => $this->return_due !== null && $this->return_due->lt(now()),
            LeaveRequestStatus::Returned => $this->returned_at !== null && $this->return_due !== null && $this->returned_at->gt($this->return_due),
            default => false,
        });
    }

    public function isVisibleTo(User $user): bool
    {
        return static::withoutGlobalScope('visible')->visibleTo($user)->whereKey($this->getKey())->exists();
    }

    /**
     * Jenjang dibaca lewat santri (termasuk yang diarsipkan).
     *
     * @param  list<int>  $unitIds
     */
    public function applyUnitScope(Builder $builder, array $unitIds): void
    {
        $builder->whereIn(
            $this->qualifyColumn('student_id'),
            Student::withoutUnitScope()->withTrashed()->whereIn('unit_id', $unitIds)->select('id'),
        );
    }
}
