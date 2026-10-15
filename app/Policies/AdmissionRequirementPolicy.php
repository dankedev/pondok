<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AdmissionRequirement;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AdmissionRequirementPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:admission-requirement');
    }

    public function view(AuthUser $authUser, AdmissionRequirement $admissionRequirement): bool
    {
        return $authUser->can('view:admission-requirement');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:admission-requirement');
    }

    public function update(AuthUser $authUser, AdmissionRequirement $admissionRequirement): bool
    {
        return $authUser->can('update:admission-requirement');
    }

    public function delete(AuthUser $authUser, AdmissionRequirement $admissionRequirement): bool
    {
        return $authUser->can('delete:admission-requirement');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:admission-requirement');
    }
}
