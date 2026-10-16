<?php

namespace App\Http\Controllers\Portal\Concerns;

use Illuminate\Http\Request;

/**
 * Pembacaan query string portal: filter berupa string (atau NULL), `page` bilangan positif.
 * Nilai non-skalar dan `page` tidak valid menjadi 404 (bukan 500).
 */
trait ReadsPortalQuery
{
    protected function queryFilter(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if ($value === null) {
            return null;
        }

        abort_unless(is_string($value), 404);

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    protected function queryPage(Request $request): int
    {
        $page = $request->query('page');

        if ($page === null || $page === '') {
            return 1;
        }

        abort_unless(is_string($page) && preg_match('/^[1-9][0-9]{0,5}$/', $page) === 1, 404);

        return (int) $page;
    }
}
