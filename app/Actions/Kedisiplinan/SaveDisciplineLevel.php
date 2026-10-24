<?php

namespace App\Actions\Kedisiplinan;

use App\Models\DisciplineLevel;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Membuat (tanpa `$level`) atau mengubah ambang poin. `min_points` unik dan minimal 1.
 */
class SaveDisciplineLevel
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, ?DisciplineLevel $level = null): DisciplineLevel
    {
        $allowed = $level ? $actor->can('update', $level) : $actor->can('create', DisciplineLevel::class);

        throw_unless($allowed, AuthorizationException::class, __('pondok.kedisiplinan.errors.forbidden'));

        $valid = Validator::make($data, [
            'name' => ['required', 'string', 'max:50'],
            'min_points' => ['required', 'integer', 'min:1', 'max:65535', Rule::unique('discipline_levels', 'min_points')->ignore($level?->id)],
        ], [], __('pondok.kedisiplinan.fields'))->validate();

        $level ??= new DisciplineLevel;
        $level->fill($valid)->save();

        return $level;
    }
}
