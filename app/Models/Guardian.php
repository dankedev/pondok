<?php

namespace App\Models;

use App\Models\Concerns\HasDocuments;
use Database\Factories\GuardianFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Wali santri. Satu wali bisa terhubung ke beberapa santri bersaudara.
 * `user_id` (akun portal wali) sengaja tidak fillable: hanya diisi `CreateGuardianPortalAccount`.
 */
#[Fillable(['name', 'phone', 'occupation', 'address'])]
class Guardian extends Model
{
    /** @use HasFactory<GuardianFactory> */
    use HasDocuments, HasFactory;

    public const RELATIONS = ['ayah', 'ibu', 'wali'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_guardian')
            ->withPivot(['relation', 'is_billing_contact'])
            ->withTimestamps();
    }
}
