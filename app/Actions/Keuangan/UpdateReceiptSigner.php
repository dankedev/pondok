<?php

namespace App\Actions\Keuangan;

use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Mengatur penandatangan kuitansi (satu staff aktif, atau kosong). Nilai yang
 * sama dengan setting sekarang tidak ditulis dan tidak dilog; perubahan dilog
 * sebagai `receipt_signer_updated` dengan nilai lama dan baru.
 */
class UpdateReceiptSigner
{
    public const SETTING_KEY = 'keuangan.receipt_signer_staff_id';

    public function handle(?int $staffId, User $actor): void
    {
        $currentId = Setting::get(self::SETTING_KEY);
        $currentId = $currentId === null ? null : (int) $currentId;

        if ($staffId === $currentId) {
            return;
        }

        $staff = $staffId === null ? null : Staff::query()->where('is_active', true)->find($staffId);

        if ($staffId !== null && $staff === null) {
            throw ValidationException::withMessages([
                'receipt_signer_staff_id' => __('pondok.numbering.finance.signer_invalid'),
            ]);
        }

        $old = $currentId === null ? null : $this->snapshot(Staff::query()->find($currentId), $currentId);

        Setting::set(self::SETTING_KEY, $staffId);

        activity('keuangan')
            ->causedBy($actor)
            ->event('receipt_signer_updated')
            ->withProperties(['old' => $old, 'new' => $staff === null ? null : $this->snapshot($staff, $staffId)])
            ->log('receipt_signer_updated');
    }

    /**
     * @return array{staff_id: int, name: string|null, position: string|null}
     */
    private function snapshot(?Staff $staff, int $staffId): array
    {
        return ['staff_id' => $staffId, 'name' => $staff?->name, 'position' => $staff?->position];
    }
}
