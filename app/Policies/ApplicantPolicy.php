<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Applicant;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ApplicantPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:applicant');
    }

    public function view(AuthUser $authUser, Applicant $applicant): bool
    {
        return $authUser->can('view:applicant');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:applicant');
    }

    public function update(AuthUser $authUser, Applicant $applicant): bool
    {
        return $authUser->can('update:applicant');
    }

    public function delete(AuthUser $authUser, Applicant $applicant): bool
    {
        return $authUser->can('delete:applicant');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:applicant');
    }

    /**
     * Menandai berkas pendaftar valid atau ditolak; syarat data dicek `VerifyApplicantDocument`.
     */
    public function verifyDocument(AuthUser $authUser, Applicant $applicant): bool
    {
        return $authUser->can('verify-document:applicant');
    }

    /**
     * Mengatur ulang token akses pendaftar.
     */
    public function resetToken(AuthUser $authUser, Applicant $applicant): bool
    {
        return $authUser->can('reset-token:applicant');
    }

    /**
     * Membuat tagihan pendaftaran yang tertinggal.
     */
    public function generateInvoices(AuthUser $authUser, Applicant $applicant): bool
    {
        return $authUser->can('generate-invoices:applicant');
    }

    /**
     * Keputusan seleksi: verifikasi, terima, cadangkan, kembalikan, tolak, batalkan penerimaan (F011).
     */
    public function decide(AuthUser $authUser, Applicant $applicant): bool
    {
        return $authUser->can('decide:applicant');
    }

    /**
     * Memulihkan pendaftar `ditolak` ke `diajukan` (bawaan hanya super-admin).
     */
    public function reopen(AuthUser $authUser, Applicant $applicant): bool
    {
        return $authUser->can('reopen:applicant');
    }

    /**
     * Daftar ulang: mengubah pendaftar diterima menjadi santri (F012).
     */
    public function reRegister(AuthUser $authUser, Applicant $applicant): bool
    {
        return $authUser->can('re-register:applicant');
    }
}
