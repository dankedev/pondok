<?php

use App\Actions\Keuangan\VoidWalletTransaction;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed([UnitSeeder::class, RolePermissionSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $this->user = keuanganUser('bendahara');
    $this->student = walletStudent('SLTP');
});

function walletVoid(WalletTransaction $tx, $user, string $reason = 'Salah catat'): WalletTransaction
{
    return app(VoidWalletTransaction::class)->handle($tx, $user, $reason);
}

it('AC-5: voiding a withdrawal restores the balance and keeps the row', function () {
    walletRecord($this->student, $this->user, 'setor', 50000);
    $withdrawal = walletRecord($this->student, $this->user, 'tarik', 20000);
    $canceller = keuanganUser('bendahara');

    $result = walletVoid($withdrawal, $canceller, '  Salah input  ');
    $stored = WalletTransaction::query()->findOrFail($withdrawal->id);
    $activities = Activity::query()->where('log_name', 'keuangan')->where('subject_id', $withdrawal->id)->get();

    expect($result->status)->toBe('batal')
        ->and($stored->status)->toBe('batal')
        ->and($stored->cancelled_at)->not->toBeNull()
        ->and($stored->cancelled_by)->toBe($canceller->id)
        ->and($stored->cancel_reason)->toBe('Salah input')
        ->and($stored->amount)->toBe(20000)
        ->and($stored->recorded_by)->toBe($this->user->id)
        ->and(WalletTransaction::query()->count())->toBe(2)
        ->and(walletBalance($this->student))->toBe(50000)
        // satu activity saat dicatat, satu lagi saat dibatalkan
        ->and($activities)->toHaveCount(2);
});

it('AC-6: refuses to void a deposit when the remaining balance is smaller', function () {
    $deposit = walletRecord($this->student, $this->user, 'setor', 50000);
    walletRecord($this->student, $this->user, 'tarik', 30000);

    walletAssertRejected(fn () => walletVoid($deposit, $this->user), 'balance_would_be_negative');

    expect($deposit->fresh()->status)->toBe('berhasil')
        ->and(walletBalance($this->student))->toBe(20000);
});

it('AC-6: voids a deposit or an upward correction when the remaining balance is enough', function () {
    $deposit = walletRecord($this->student, $this->user, 'setor', 50000);
    $correction = walletRecord($this->student, $this->user, 'koreksi_tambah', 10000, ['note' => 'Koreksi']);
    walletRecord($this->student, $this->user, 'tarik', 20000);

    expect(walletBalance($this->student))->toBe(40000);

    walletVoid($correction, $this->user);

    expect(walletBalance($this->student))->toBe(30000);

    walletAssertRejected(fn () => walletVoid($deposit, $this->user), 'balance_would_be_negative');

    walletRecord($this->student, $this->user, 'setor', 20000);
    walletVoid($deposit, $this->user);

    expect(walletBalance($this->student))->toBe(0);
});

it('AC-5: voiding a downward correction restores the balance', function () {
    walletRecord($this->student, $this->user, 'setor', 10000);
    $correction = walletRecord($this->student, $this->user, 'koreksi_kurang', 4000, ['note' => 'Koreksi']);

    walletVoid($correction, $this->user);

    expect(walletBalance($this->student))->toBe(10000);
});

it('AC-7: refuses a second void and a voided row is not counted', function () {
    walletRecord($this->student, $this->user, 'setor', 50000);
    $withdrawal = walletRecord($this->student, $this->user, 'tarik', 20000);
    walletVoid($withdrawal, $this->user, 'Pertama');

    walletAssertRejected(fn () => walletVoid($withdrawal, $this->user, 'Kedua'), 'already_cancelled', 'transaction');
    walletAssertRejected(fn () => walletVoid($withdrawal->fresh(), $this->user, 'Kedua'), 'already_cancelled', 'transaction');

    $stored = $withdrawal->fresh();

    expect($stored->cancel_reason)->toBe('Pertama')
        ->and(walletBalance($this->student))->toBe(50000)
        ->and(Activity::query()->where('log_name', 'keuangan')->where('subject_id', $withdrawal->id)->count())->toBe(2);
});

it('AC-7: rejects an empty, blank, or over-long reason', function (string $reason) {
    $withdrawal = walletRecord($this->student, $this->user, 'setor', 50000);

    walletAssertRejected(fn () => walletVoid($withdrawal, $this->user, $reason), 'reason_invalid', 'reason');

    expect($withdrawal->fresh()->status)->toBe('berhasil');
})->with([
    'kosong' => [''],
    'spasi' => ['   '],
    '256 karakter' => [str_repeat('a', 256)],
]);

it('AC-7: accepts a reason of exactly 255 characters', function () {
    $withdrawal = walletRecord($this->student, $this->user, 'setor', 50000);

    walletVoid($withdrawal, $this->user, str_repeat('a', 255));

    expect($withdrawal->fresh()->status)->toBe('batal');
});

it('AC-8: refuses to void for an archived student with a validation error', function () {
    $deposit = walletRecord($this->student, $this->user, 'setor', 50000);
    $this->student->delete();

    walletAssertRejected(fn () => walletVoid($deposit, $this->user), 'student_unavailable');

    expect(WalletTransaction::withoutUnitScope()->findOrFail($deposit->id)->status)->toBe('berhasil');
});

it('AC-8: lets an alumni student\'s transaction be voided', function () {
    $alumni = walletStudent('SLTP', ['status' => 'lulus']);
    $deposit = walletRecord($alumni, $this->user, 'setor', 5000);

    walletVoid($deposit, $this->user);

    expect(walletBalance($alumni))->toBe(0);
});

it('AC-11: refuses to void a transaction of another unit, with or without a signed-in user', function () {
    $deposit = walletRecord($this->student, $this->user, 'setor', 50000);
    $scoped = keuanganUser('bendahara', ['SD']);

    walletAssertRejected(fn () => walletVoid($deposit, $scoped), 'out_of_scope');

    $this->actingAs(keuanganUser('bendahara'));
    walletAssertRejected(fn () => walletVoid($deposit, $scoped), 'out_of_scope');

    expect($deposit->fresh()->status)->toBe('berhasil');
});

it('AC-11: lets a user scoped to the same unit void', function () {
    $deposit = walletRecord($this->student, $this->user, 'setor', 50000);

    walletVoid($deposit, keuanganUser('bendahara', ['SLTP']));

    expect($deposit->fresh()->status)->toBe('batal');
});
