<?php

use App\Models\Setting;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $this->f = tagihanFixture();
    $this->user = keuanganUser('bendahara');
    $this->invoice = bayarInvoice($this->f, tagihanStudent($this->f, resident: true));
});

it('AC-1: leaves the signer empty when the setting is not set', function () {
    $payment = bayarRecord($this->invoice, 500000, [], $this->user);

    expect($payment->fresh()->signer_name)->toBeNull()
        ->and($payment->fresh()->signer_title)->toBeNull();
});

it('AC-2: freezes the signer name and title on the payment', function () {
    $staff = Staff::factory()->create(['name' => 'Ustadz Yusuf', 'position' => 'Bendahara Pondok']);
    Setting::set('keuangan.receipt_signer_staff_id', $staff->id);

    $payment = bayarRecord($this->invoice, 500000, [], $this->user)->fresh();

    expect($payment->signer_name)->toBe('Ustadz Yusuf')
        ->and($payment->signer_title)->toBe('Bendahara Pondok')
        ->and($payment->received_by)->toBe($this->user->id)
        ->and($payment->amount)->toBe(500000);
});

it('AC-3: keeps the old payment signer after the staff is replaced, renamed and deleted', function () {
    $first = Staff::factory()->create(['name' => 'Ustadz Yusuf', 'position' => 'Bendahara']);
    $second = Staff::factory()->create(['name' => 'Ustadz Idris', 'position' => 'Wakil Bendahara']);
    Setting::set('keuangan.receipt_signer_staff_id', $first->id);
    $old = bayarRecord($this->invoice, 300000, [], $this->user);

    Setting::set('keuangan.receipt_signer_staff_id', $second->id);
    $first->update(['name' => 'Nama Baru', 'position' => 'Jabatan Baru']);
    $first->delete();
    $new = bayarRecord($this->invoice, 300000, [], $this->user);

    expect($old->fresh()->signer_name)->toBe('Ustadz Yusuf')
        ->and($old->fresh()->signer_title)->toBe('Bendahara')
        ->and($new->fresh()->signer_name)->toBe('Ustadz Idris')
        ->and($new->fresh()->signer_title)->toBe('Wakil Bendahara');
});

it('AC-4: falls back to no signer for an inactive or missing staff', function (string $case) {
    $staff = Staff::factory()->create(['is_active' => $case !== 'inactive']);
    Setting::set('keuangan.receipt_signer_staff_id', $case === 'missing' ? $staff->id + 999 : $staff->id);

    $payment = bayarRecord($this->invoice, 500000, [], $this->user)->fresh();

    expect($payment->amount)->toBe(500000)
        ->and($payment->signer_name)->toBeNull()
        ->and($payment->signer_title)->toBeNull();
})->with(['inactive', 'missing']);

it('AC-5: stores a null title for a staff without position', function (string $position) {
    $staff = Staff::factory()->create(['name' => 'Ustadz Yusuf', 'position' => $position]);
    Setting::set('keuangan.receipt_signer_staff_id', $staff->id);

    $payment = bayarRecord($this->invoice, 500000, [], $this->user)->fresh();

    expect($payment->signer_name)->toBe('Ustadz Yusuf')
        ->and($payment->signer_title)->toBeNull();
})->with(['empty' => [''], 'spaces' => ['   ']]);

it('VERIFY F062: installments each freeze the signer current at that time and log once', function () {
    $first = Staff::factory()->create(['name' => 'Ustadz Yusuf', 'position' => 'Bendahara']);
    $second = Staff::factory()->create(['name' => 'Ustadz Idris', 'position' => ' ']);
    Setting::set('keuangan.receipt_signer_staff_id', $first->id);
    $a = bayarRecord($this->invoice, 200000, [], $this->user);
    Setting::set('keuangan.receipt_signer_staff_id', $second->id);
    $b = bayarRecord($this->invoice, 200000, [], $this->user);

    expect($a->fresh()->signer_name)->toBe('Ustadz Yusuf')
        ->and($b->fresh()->signer_name)->toBe('Ustadz Idris')
        ->and($b->fresh()->signer_title)->toBeNull()
        ->and(Activity::query()->where('event', 'created')->where('subject_type', $a->getMorphClass())->count())->toBe(2);
});
