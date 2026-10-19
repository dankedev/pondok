<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class InvoicePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:invoice');
    }

    public function view(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('view:invoice');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:invoice');
    }

    public function update(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('update:invoice');
    }

    public function delete(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('delete:invoice');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:invoice');
    }

    /**
     * Menjalankan generator tagihan bulanan dari panel.
     */
    public function generate(AuthUser $authUser): bool
    {
        return $authUser->can('generate:invoice');
    }

    /**
     * Mengimpor saldo tunggakan awal dari CSV.
     */
    public function import(AuthUser $authUser): bool
    {
        return $authUser->can('import:invoice');
    }

    /**
     * Membatalkan tagihan (status `batal`); syarat data dicek `CancelInvoice`.
     */
    public function cancel(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('cancel:invoice');
    }
}
