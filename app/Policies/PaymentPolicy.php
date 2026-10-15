<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payment;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PaymentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:payment');
    }

    public function view(AuthUser $authUser, Payment $payment): bool
    {
        return $authUser->can('view:payment');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:payment');
    }

    public function update(AuthUser $authUser, Payment $payment): bool
    {
        return $authUser->can('update:payment');
    }

    public function delete(AuthUser $authUser, Payment $payment): bool
    {
        return $authUser->can('delete:payment');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:payment');
    }

    /**
     * Membatalkan pembayaran (status `batal`); syarat data dicek `CancelPayment`.
     */
    public function cancel(AuthUser $authUser, Payment $payment): bool
    {
        return $authUser->can('cancel:payment');
    }
}
