<?php

namespace Tests\Fixtures;

use App\Models\Concerns\HasUnitScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Model uji untuk HasUnitScope selama model domain (Student, dsb.) belum ada.
 */
class ScopedRecord extends Model
{
    use HasUnitScope;

    protected $table = 'scoped_records';

    protected $guarded = [];
}
