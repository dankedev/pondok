<?php

namespace App\Actions\Perizinan;

use App\Enums\LeaveApprover;
use App\Enums\LeaveRequestStatus;
use App\Models\DormitorySupervisor;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Penyetuju izin dihitung, tidak disimpan (F024 D3).
 *
 * Pemegang `approve-any:leave-request` (kesantrian, super-admin) memutus semua izin `diajukan` yang
 * terlihat olehnya. Pemegang `approve:leave-request` hanya bila penugasan aktifnya di
 * `dormitory_supervisors` cocok dengan kamar aktif santri: jenis ber-approver `musyrif` → musyrif
 * kamar itu atau musyrif tingkat asrama (`room_id` kosong); `kepala_asrama` → kepala asrama santri.
 * Jenis `kesantrian` dan santri nonmukim hanya untuk pemegang `approve-any`.
 */
class ResolveLeaveApprover
{
    /**
     * Izin `diajukan` yang boleh diputus `$user`.
     *
     * @return Builder<LeaveRequest>
     */
    public function pendingFor(User $user): Builder
    {
        $query = LeaveRequest::withoutGlobalScopes()->where('leave_requests.status', LeaveRequestStatus::Submitted->value);

        if ($user->can('approve-any:leave-request')) {
            return $query->visibleTo($user);
        }

        if (! $user->can('approve:leave-request')) {
            return $query->whereRaw('1 = 0');
        }

        $supervisions = DormitorySupervisor::query()
            ->active()
            ->whereIn('staff_id', Staff::query()->where('user_id', $user->id)->select('id'));

        $musyrif = (clone $supervisions)->where('role', DormitorySupervisor::ROLE_SUPERVISOR);
        $roomsOfMusyrif = Room::query()->select('rooms.id')->where(function (Builder $rooms) use ($musyrif): void {
            $rooms->whereIn('rooms.id', (clone $musyrif)->whereNotNull('room_id')->select('room_id'))
                ->orWhereIn('rooms.dormitory_id', (clone $musyrif)->whereNull('room_id')->select('dormitory_id'));
        });

        $roomsOfHead = Room::query()->select('rooms.id')->whereIn('rooms.dormitory_id', (clone $supervisions)
            ->where('role', DormitorySupervisor::ROLE_HEAD)
            ->select('dormitory_id'));

        return $query->visibleTo($user)->where(function (Builder $routed) use ($roomsOfMusyrif, $roomsOfHead): void {
            $routed->where(fn (Builder $q) => $this->routedTo($q, LeaveApprover::Musyrif, $roomsOfMusyrif))
                ->orWhere(fn (Builder $q) => $this->routedTo($q, LeaveApprover::HeadOfDormitory, $roomsOfHead));
        });
    }

    public function canDecide(User $user, LeaveRequest $request): bool
    {
        return $this->pendingFor($user)->whereKey($request->getKey())->exists();
    }

    /**
     * Izin berjenis `$approver` milik santri yang kamar aktifnya ada di `$rooms`.
     *
     * @param  Builder<LeaveRequest>  $query
     * @param  Builder<Room>  $rooms
     */
    private function routedTo(Builder $query, LeaveApprover $approver, Builder $rooms): void
    {
        $query->whereIn('leave_requests.leave_type_id', LeaveType::query()->where('approver', $approver->value)->select('id'))
            ->whereIn('leave_requests.student_id', RoomAssignment::withoutUnitScope()->active()->whereIn('room_id', $rooms)->select('student_id'));
    }
}
