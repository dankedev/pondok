<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AdmissionWave;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AdmissionWavePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:admission-wave');
    }

    public function view(AuthUser $authUser, AdmissionWave $admissionWave): bool
    {
        return $authUser->can('view:admission-wave');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:admission-wave');
    }

    public function update(AuthUser $authUser, AdmissionWave $admissionWave): bool
    {
        return $authUser->can('update:admission-wave');
    }

    public function delete(AuthUser $authUser, AdmissionWave $admissionWave): bool
    {
        return $authUser->can('delete:admission-wave');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:admission-wave');
    }
}
