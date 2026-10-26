<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\WalletTransaction;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class WalletTransactionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:wallet-transaction');
    }

    public function view(AuthUser $authUser, WalletTransaction $walletTransaction): bool
    {
        return $authUser->can('view:wallet-transaction');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:wallet-transaction');
    }

    /**
     * Membatalkan transaksi (status `batal`); syarat data dicek `VoidWalletTransaction`.
     */
    public function cancel(AuthUser $authUser, WalletTransaction $walletTransaction): bool
    {
        return $authUser->can('cancel:wallet-transaction');
    }
}
