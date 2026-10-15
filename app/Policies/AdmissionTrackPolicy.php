<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AdmissionTrack;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AdmissionTrackPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:admission-track');
    }

    public function view(AuthUser $authUser, AdmissionTrack $admissionTrack): bool
    {
        return $authUser->can('view:admission-track');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:admission-track');
    }

    public function update(AuthUser $authUser, AdmissionTrack $admissionTrack): bool
    {
        return $authUser->can('update:admission-track');
    }

    public function delete(AuthUser $authUser, AdmissionTrack $admissionTrack): bool
    {
        return $authUser->can('delete:admission-track');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:admission-track');
    }
}
