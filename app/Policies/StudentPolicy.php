<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Student;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class StudentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:student');
    }

    public function view(AuthUser $authUser, Student $student): bool
    {
        return $authUser->can('view:student');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:student');
    }

    public function update(AuthUser $authUser, Student $student): bool
    {
        return $authUser->can('update:student');
    }

    public function delete(AuthUser $authUser, Student $student): bool
    {
        return $authUser->can('delete:student');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:student');
    }

    public function restore(AuthUser $authUser, Student $student): bool
    {
        return $authUser->can('restore:student');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore-any:student');
    }
}
