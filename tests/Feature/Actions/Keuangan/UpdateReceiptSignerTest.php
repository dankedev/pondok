<?php

use App\Actions\Keuangan\UpdateReceiptSigner;
use App\Models\Setting;
use App\Models\Staff;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->actor = keuanganUser('bendahara');
    $this->staff = Staff::factory()->create(['name' => 'Ustadz Yusuf', 'position' => 'Bendahara']);
});

it('AC-7: stores the signer and writes exactly one activity', function () {
    Activity::query()->delete();

    app(UpdateReceiptSigner::class)->handle($this->staff->id, $this->actor);

    $activity = Activity::query()->sole();

    expect(Setting::get('keuangan.receipt_signer_staff_id'))->toBe($this->staff->id)
        ->and($activity->log_name)->toBe('keuangan')
        ->and($activity->event)->toBe('receipt_signer_updated')
        ->and($activity->causer_id)->toBe($this->actor->id)
        ->and($activity->properties['old'])->toBeNull()
        ->and($activity->properties['new'])->toBe(['staff_id' => $this->staff->id, 'name' => 'Ustadz Yusuf', 'position' => 'Bendahara'])
        ->and($activity->created_at)->not->toBeNull();
});

it('AC-7: logs the old signer and a null new value when cleared', function () {
    app(UpdateReceiptSigner::class)->handle($this->staff->id, $this->actor);
    Activity::query()->delete();

    app(UpdateReceiptSigner::class)->handle(null, $this->actor);

    $activity = Activity::query()->sole();

    expect(Setting::get('keuangan.receipt_signer_staff_id'))->toBeNull()
        ->and($activity->properties['old']['staff_id'])->toBe($this->staff->id)
        ->and($activity->properties['new'])->toBeNull();
});

it('AC-8: does nothing when the value is unchanged, even if the staff is now inactive', function () {
    app(UpdateReceiptSigner::class)->handle($this->staff->id, $this->actor);
    Activity::query()->delete();

    app(UpdateReceiptSigner::class)->handle($this->staff->id, $this->actor);
    $this->staff->update(['is_active' => false]);
    app(UpdateReceiptSigner::class)->handle($this->staff->id, $this->actor);

    expect(Activity::query()->count())->toBe(0)
        ->and(Setting::get('keuangan.receipt_signer_staff_id'))->toBe($this->staff->id);
});

it('AC-8: rejects an inactive or missing staff without changing anything', function (string $case) {
    $other = Staff::factory()->create(['is_active' => false]);
    app(UpdateReceiptSigner::class)->handle($this->staff->id, $this->actor);
    Activity::query()->delete();

    expect(fn () => app(UpdateReceiptSigner::class)->handle($case === 'missing' ? $other->id + 999 : $other->id, $this->actor))
        ->toThrow(ValidationException::class);

    expect(Setting::get('keuangan.receipt_signer_staff_id'))->toBe($this->staff->id)
        ->and(Activity::query()->count())->toBe(0);
})->with(['inactive', 'missing']);
