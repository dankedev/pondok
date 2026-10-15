<?php

namespace App\Models;

use Database\Factories\GuardianFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Wali santri. Satu wali bisa terhubung ke beberapa santri bersaudara.
 */
#[Fillable(['name', 'phone', 'occupation', 'address', 'user_id'])]
class Guardian extends Model
{
    /** @use HasFactory<GuardianFactory> */
    use HasFactory;

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
