<?php

namespace App\Actions\Tahfidz;

use App\Actions\Tahfidz\Concerns\GuardsTahfidz;
use App\Models\HalaqahMember;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Mengeluarkan santri dari halaqah dengan mengisi `left_on`. Barisnya tetap sebagai riwayat.
 */
class EndHalaqahMembership
{
    use GuardsTahfidz;

    public function handle(User $actor, HalaqahMember $member, CarbonInterface $leftOn): HalaqahMember
    {
        $this->authorize($actor, 'update:halaqah');
        $leftOn = $leftOn->toImmutable()->startOfDay();

        return DB::transaction(function () use ($member, $leftOn): HalaqahMember {
            $member = HalaqahMember::withoutUnitScope()->lockForUpdate()->findOrFail($member->id);

            if ($member->left_on !== null) {
                $this->fail('left_on', 'already_ended');
            }

            if ($leftOn->lt($member->joined_on) || $leftOn->gt(today())) {
                $this->fail('left_on', 'left_out_of_range');
            }

            $member->update(['left_on' => $leftOn]);

            return $member;
        });
    }
}
