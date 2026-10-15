<?php

namespace App\Actions\Asrama;

use App\Models\AcademicYear;
use App\Models\Dormitory;
use App\Models\DormitorySupervisor;
use App\Models\Room;
use App\Models\Staff;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menugaskan staff sebagai kepala asrama atau musyrif untuk satu tahun ajaran.
 *
 * Kepala asrama hanya satu per asrama per tahun ajaran: kepala lama ditutup
 * saat kepala baru ditugaskan. Musyrif boleh lebih dari satu.
 */
class AssignSupervisor
{
    public function handle(
        Dormitory $dormitory,
        Staff $staff,
        string $role,
        AcademicYear $academicYear,
        ?Room $room = null,
        ?CarbonInterface $startOn = null,
    ): DormitorySupervisor {
        $startOn = ($startOn ?? today())->toImmutable()->startOfDay();

        if (! in_array($role, DormitorySupervisor::ROLES, true)) {
            $this->fail('role', 'invalid_role');
        }

        if (! $staff->is_active) {
            $this->fail('staff_id', 'staff_inactive');
        }

        if ($room !== null && $room->dormitory_id !== $dormitory->id) {
            $this->fail('room_id', 'room_dormitory_mismatch');
        }

        if ($role === DormitorySupervisor::ROLE_HEAD && $room !== null) {
            $this->fail('room_id', 'head_requires_dormitory');
        }

        return DB::transaction(function () use ($dormitory, $staff, $role, $academicYear, $room, $startOn): DormitorySupervisor {
            $existing = DormitorySupervisor::query()
                ->where('dormitory_id', $dormitory->id)
                ->where('staff_id', $staff->id)
                ->where('role', $role)
                ->where('academic_year_id', $academicYear->id)
                ->where('room_id', $room?->id)
                ->whereNull('end_on')
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($role === DormitorySupervisor::ROLE_HEAD) {
                $this->replaceHead($dormitory, $academicYear, $startOn);
            }

            return DormitorySupervisor::query()->create([
                'dormitory_id' => $dormitory->id,
                'room_id' => $room?->id,
                'staff_id' => $staff->id,
                'academic_year_id' => $academicYear->id,
                'role' => $role,
                'start_on' => $startOn,
            ]);
        });
    }

    private function replaceHead(Dormitory $dormitory, AcademicYear $academicYear, CarbonInterface $startOn): void
    {
        $heads = DormitorySupervisor::query()
            ->where('dormitory_id', $dormitory->id)
            ->where('academic_year_id', $academicYear->id)
            ->where('role', DormitorySupervisor::ROLE_HEAD)
            ->whereNull('end_on')
            ->lockForUpdate()
            ->get();

        if ($heads->contains(fn (DormitorySupervisor $head): bool => $startOn->lt($head->start_on))) {
            $this->fail('start_on', 'start_before_current_head');
        }

        $heads->each(fn (DormitorySupervisor $head) => $head->update(['end_on' => $startOn]));
    }

    private function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.asrama.errors.{$reason}"),
        ]);
    }
}
