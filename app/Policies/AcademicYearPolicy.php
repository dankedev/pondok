<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AcademicYear;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AcademicYearPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:academic-year');
    }

    public function view(AuthUser $authUser, AcademicYear $academicYear): bool
    {
        return $authUser->can('view:academic-year');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:academic-year');
    }

    public function update(AuthUser $authUser, AcademicYear $academicYear): bool
    {
        return $authUser->can('update:academic-year');
    }

    public function delete(AuthUser $authUser, AcademicYear $academicYear): bool
    {
        return $authUser->can('delete:academic-year');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:academic-year');
    }
}
