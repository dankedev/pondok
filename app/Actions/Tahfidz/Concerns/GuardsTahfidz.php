<?php

namespace App\Actions\Tahfidz\Concerns;

use App\Models\Halaqah;
use App\Models\HalaqahMember;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Pemeriksaan bersama Action tahfidz: permission, halaqah yang terlihat dan aktif, keanggotaan
 * santri pada tanggal tertentu, dan staff aktif. Galat memakai `pondok.tahfidz.errors.<reason>`.
 */
trait GuardsTahfidz
{
    protected function authorize(User $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new AuthorizationException(__('pondok.tahfidz.errors.forbidden'));
        }
    }

    protected function authorizeVisible(User $actor, bool $visible): void
    {
        if (! $visible) {
            throw new AuthorizationException(__('pondok.tahfidz.errors.forbidden'));
        }
    }

    /**
     * Halaqah dicari dalam cakupan jenjang; yang tidak ia lihat ditolak sebagai akses, yang nonaktif sebagai validasi.
     */
    protected function visibleActiveHalaqah(User $actor, mixed $halaqahId): Halaqah
    {
        $halaqah = is_numeric($halaqahId) ? Halaqah::query()->find((int) $halaqahId) : null;

        if ($halaqah === null) {
            $this->fail('halaqah_id', 'halaqah_not_found');
        }

        $this->authorizeVisible($actor, $halaqah->isVisibleTo($actor));

        if (! $halaqah->is_active) {
            $this->fail('halaqah_id', 'halaqah_inactive');
        }

        return $halaqah;
    }

    /**
     * Santri harus anggota halaqah ini pada tanggal `$date` (interval [joined_on, left_on)).
     */
    protected function memberOn(Halaqah $halaqah, mixed $studentId, string $date): Student
    {
        $student = is_numeric($studentId) ? Student::query()->find((int) $studentId) : null;

        $isMember = $student !== null && HalaqahMember::withoutUnitScope()
            ->where('halaqah_id', $halaqah->id)
            ->where('student_id', $student->id)
            ->covering($date)
            ->exists();

        if (! $isMember) {
            $this->fail('student_id', 'not_member_on_date');
        }

        return $student;
    }

    /**
     * Staff aktif; bawaan staff milik pelaku.
     */
    protected function activeStaffId(User $actor, mixed $staffId, string $field): int
    {
        $staffId = filled($staffId) ? $staffId : $actor->staff?->id;

        if (! is_numeric($staffId) || ! Staff::query()->whereKey((int) $staffId)->where('is_active', true)->exists()) {
            $this->fail($field, 'staff_inactive');
        }

        return (int) $staffId;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function validate(array $data, array $rules): array
    {
        return Validator::make($data, $rules, [], __('pondok.tahfidz.fields'))->validate();
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    protected function fail(string $field, string $reason, array $replace = []): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.tahfidz.errors.{$reason}", $replace),
        ]);
    }
}
