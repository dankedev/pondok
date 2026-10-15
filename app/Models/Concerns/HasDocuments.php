<?php

namespace App\Models\Concerns;

use App\Models\Document;
use App\Models\Scopes\UnitScope;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Pemilik dokumen (santri, wali, staff).
 */
trait HasDocuments
{
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Pemeriksaan integritas ("masih punya dokumen"), tanpa cakupan jenjang.
     */
    public function hasAnyDocuments(): bool
    {
        return $this->documents()->withoutGlobalScope(UnitScope::class)->exists();
    }
}
