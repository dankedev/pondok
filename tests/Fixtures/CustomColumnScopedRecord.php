<?php

namespace Tests\Fixtures;

use App\Models\Concerns\HasUnitScope;
use Illuminate\Database\Eloquent\Model;

class CustomColumnScopedRecord extends Model
{
    use HasUnitScope;

    protected $table = 'scoped_records';

    protected $guarded = [];

    protected string $unitScopeColumn = 'target_unit_id';
}
