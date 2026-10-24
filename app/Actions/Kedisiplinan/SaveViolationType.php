<?php

namespace App\Actions\Kedisiplinan;

use App\Enums\ViolationCategory;
use App\Models\User;
use App\Models\ViolationType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Membuat (tanpa `$type`) atau mengubah jenis pelanggaran. Jenis tidak dihapus, cukup dinonaktifkan;
 * poin baru tidak mengubah catatan lama (snapshot).
 */
class SaveViolationType
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, ?ViolationType $type = null): ViolationType
    {
        $allowed = $type ? $actor->can('update', $type) : $actor->can('create', ViolationType::class);

        throw_unless($allowed, AuthorizationException::class, __('pondok.kedisiplinan.errors.forbidden'));

        $valid = Validator::make($data, [
            'name' => ['required', 'string', 'max:100', Rule::unique('violation_types', 'name')->ignore($type?->id)],
            'category' => ['required', Rule::enum(ViolationCategory::class)],
            'points' => ['required', 'integer', 'min:1', 'max:'.ViolationType::MAX_POINTS],
            'is_active' => ['nullable', 'boolean'],
        ], [], __('pondok.kedisiplinan.fields'))->validate();

        $type ??= new ViolationType;
        $type->fill([...$valid, 'is_active' => (bool) ($valid['is_active'] ?? true)])->save();

        return $type;
    }
}
