<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StaticPage;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Halaman statis (F037): halaman bebas bisa dibuat dan dihapus; halaman sistem tidak
 * bisa dihapus. Super-admin melewati policy (Shield `intercept_gate = before`), jadi
 * UI menyembunyikan aksi hapus secara eksplisit dan `DeleteStaticPage` menjadi guard akhir.
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
        return $authUser->can('delete:static-page') && ! $staticPage->isSystem();
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        // Tanpa hapus massal: halaman dihapus satu per satu lewat `DeleteStaticPage`.
        return false;
    }
}
