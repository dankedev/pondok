<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StaticPage;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Halaman statis (F037, F048): semua halaman setara, bisa dibuat dan dihapus oleh
 * pemegang permission.
 */
class StaticPagePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:static-page');
    }

    public function view(AuthUser $authUser, StaticPage $staticPage): bool
    {
        return $authUser->can('view:static-page');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:static-page');
    }

    public function update(AuthUser $authUser, StaticPage $staticPage): bool
    {
        return $authUser->can('update:static-page');
    }

    public function delete(AuthUser $authUser, StaticPage $staticPage): bool
    {
        return $authUser->can('delete:static-page');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        // Tanpa hapus massal: halaman dihapus satu per satu lewat `DeleteStaticPage`.
        return false;
    }
}
