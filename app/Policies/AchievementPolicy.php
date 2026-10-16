<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Achievement;
use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Gate;

/**
 * Prestasi (F015). Mengubah, menghapus, dan memverifikasi hanya bila semua
 * peserta dalam cakupan jenjang user. Syarat data juga dicek Action-nya.
 */
class AchievementPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:achievement');
    }

    public function view(AuthUser $authUser, Achievement $achievement): bool
    {
        return $authUser->can('view:achievement');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:achievement');
    }

    public function update(AuthUser $authUser, Achievement $achievement): bool
    {
        return $authUser->can('update:achievement') && $this->manages($authUser, $achievement);
    }

    /**
     * Hanya prestasi belum terverifikasi tanpa berita (termasuk terhapus) dan tanpa dokumen.
     */
    public function delete(AuthUser $authUser, Achievement $achievement): bool
    {
        return $authUser->can('delete:achievement') && $this->manages($authUser, $achievement) && $achievement->isDeletable();
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:achievement');
    }

    public function verify(AuthUser $authUser, Achievement $achievement): bool
    {
        return $authUser->can('verify:achievement') && ! $achievement->is_verified && $this->manages($authUser, $achievement);
    }

    /**
     * Membuat draf berita: juga butuh hak membuat berita, dan prestasinya harus layak terbit.
     */
    public function publish(AuthUser $authUser, Achievement $achievement): bool
    {
        return $authUser->can('publish:achievement')
            && Gate::forUser($authUser)->allows('create', Post::class)
            && $achievement->isPublishable();
    }

    private function manages(AuthUser $authUser, Achievement $achievement): bool
    {
        return $authUser instanceof User && $achievement->isFullyVisibleTo($authUser);
    }
}
