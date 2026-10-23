<?php

namespace App\Actions\Perizinan;

use App\Actions\Perizinan\Concerns\GuardsPerizinan;
use App\Enums\LeaveRequestStatus;
use App\Models\HomecomingPeriod;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Membuat (tanpa `$period`) atau mengubah periode pulang massal. Mengubah `return_on` ikut memindahkan
 * batas kembali izin periode itu yang masih `disetujui`/`keluar` (F024 D7).
 */
class SaveHomecomingPeriod
{
    use GuardsPerizinan;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor, ?HomecomingPeriod $period = null): HomecomingPeriod
    {
        $this->authorize($period ? $actor->can('update', $period) : $actor->can('create', HomecomingPeriod::class), 'homecoming_period');

        $valid = Validator::make($data, [
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'name' => ['required', 'string', 'max:255'],
            'depart_on' => ['required', 'date'],
            'return_on' => ['required', 'date', 'after_or_equal:depart_on'],
        ], [], __('pondok.homecoming_period.fields'))->validate();

        return DB::transaction(function () use ($valid, $period): HomecomingPeriod {
            $period ??= new HomecomingPeriod;
            $period->fill($valid)->save();

            if ($period->wasChanged('return_on')) {
                LeaveRequest::withoutGlobalScopes()
                    ->where('homecoming_period_id', $period->id)
                    ->whereIn('status', [LeaveRequestStatus::Approved->value, LeaveRequestStatus::Out->value])
                    ->update(['return_due' => GenerateHomecomingLeaves::returnDue($period), 'updated_at' => now()]);
            }

            return $period;
        });
    }
}
