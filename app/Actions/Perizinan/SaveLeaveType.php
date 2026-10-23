<?php

namespace App\Actions\Perizinan;

use App\Actions\Perizinan\Concerns\GuardsPerizinan;
use App\Enums\LeaveApprover;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Membuat (tanpa `$type`) atau mengubah jenis izin. Jenis tidak dihapus, cukup dinonaktifkan.
 */
class SaveLeaveType
{
    use GuardsPerizinan;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor, ?LeaveType $type = null): LeaveType
    {
        $this->authorize($type ? $actor->can('update', $type) : $actor->can('create', LeaveType::class), 'leave_type');

        $valid = Validator::make($data, [
            'code' => ['required', 'string', 'max:20', Rule::unique('leave_types', 'code')->ignore($type?->id)],
            'name' => ['required', 'string', 'max:255'],
            'max_hours' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'approver' => ['required', Rule::enum(LeaveApprover::class)],
            'requires_guardian' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ], [], __('pondok.leave_type.fields'))->validate();

        $type ??= new LeaveType;
        $type->fill([
            ...$valid,
            'max_hours' => $valid['max_hours'] ?? null,
            'requires_guardian' => (bool) ($valid['requires_guardian'] ?? false),
            'is_active' => (bool) ($valid['is_active'] ?? true),
        ])->save();

        return $type;
    }
}
