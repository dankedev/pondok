<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StaticPage;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Halaman statis (profil, visi-misi, kontak) hanya bisa dilihat dan diubah.
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
        // Halaman statis tetap tiga (dibuat seeder): tidak dibuat atau dihapus dari panel.
        return false;
    }

    public function update(AuthUser $authUser, StaticPage $staticPage): bool
    {
        return $authUser->can('update:static-page');
    }

    public function delete(AuthUser $authUser, StaticPage $staticPage): bool
    {
        // Halaman statis tetap tiga (dibuat seeder): tidak dibuat atau dihapus dari panel.
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        // Halaman statis tetap tiga (dibuat seeder): tidak dibuat atau dihapus dari panel.
        return false;
    }
}
