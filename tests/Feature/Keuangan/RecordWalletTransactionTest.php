<?php

use App\Enums\WalletTransactionType;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed([UnitSeeder::class, RolePermissionSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $this->user = keuanganUser('bendahara');
    $this->student = walletStudent('SLTP');
});

it('AC-1: records a deposit that raises the balance and logs one activity', function () {
    $tx = walletRecord($this->student, $this->user, 'setor', 50000, ['note' => 'Uang saku bulan ini']);

    $stored = WalletTransaction::query()->findOrFail($tx->id);
    $activities = Activity::query()->where('log_name', 'keuangan')->where('subject_id', $tx->id)->get();

    expect($stored->student_id)->toBe($this->student->id)
        ->and($stored->type)->toBe(WalletTransactionType::Setor)
        ->and($stored->amount)->toBe(50000)
        ->and($stored->status)->toBe('berhasil')
        ->and($stored->recorded_by)->toBe($this->user->id)
        ->and($stored->occurred_on->toDateString())->toBe('2026-10-15')
        ->and($stored->note)->toBe('Uang saku bulan ini')
        ->and(walletBalance($this->student))->toBe(50000)
        ->and($activities)->toHaveCount(1);
});

it('AC-2: withdraws up to the balance and refuses more without writing a row', function () {
    walletRecord($this->student, $this->user, 'setor', 50000);
    walletRecord($this->student, $this->user, 'tarik', 20000);

    expect(walletBalance($this->student))->toBe(30000);

    walletAssertRejected(fn () => walletRecord($this->student, $this->user, 'tarik', 30001), 'insufficient_balance', 'amount');

    expect(WalletTransaction::query()->count())->toBe(2)
        ->and(walletBalance($this->student))->toBe(30000);

    walletRecord($this->student, $this->user, 'tarik', 30000);

    expect(walletBalance($this->student))->toBe(0);
});

it('AC-2: refuses to withdraw from a wallet with no deposits', function () {
    walletAssertRejected(fn () => walletRecord($this->student, $this->user, 'tarik', 1), 'insufficient_balance', 'amount');

    expect(WalletTransaction::query()->count())->toBe(0);
});

it('AC-3: rejects an invalid amount', function (mixed $amount) {
    walletAssertRejected(fn () => walletRecord($this->student, $this->user, 'setor', $amount), 'amount_invalid', 'amount');

    expect(WalletTransaction::query()->count())->toBe(0);
})->with([
    'nol' => [0],
    'negatif' => [-5000],
    'desimal' => [12.5],
    'desimal teks' => ['12.5'],
    'bukan angka' => ['abc'],
    'di atas batas' => [1_000_000_000],
]);

it('AC-3: accepts the largest allowed amount and a numeric string', function () {
    walletRecord($this->student, $this->user, 'setor', 999_999_999);
    walletRecord($this->student, $this->user, 'setor', '1000');

    expect(walletBalance($this->student))->toBe(1_000_000_999);
});

it('AC-3: rejects a future date and a note over 255 characters', function () {
    walletAssertRejected(fn () => walletRecord($this->student, $this->user, 'setor', 1000, ['occurred_on' => '2026-10-16']), 'date_future', 'occurred_on');
    walletAssertRejected(fn () => walletRecord($this->student, $this->user, 'setor', 1000, ['note' => str_repeat('a', 256)]), 'note_invalid', 'note');

    walletRecord($this->student, $this->user, 'setor', 1000, ['occurred_on' => '2026-10-15', 'note' => str_repeat('a', 255)]);

    expect(WalletTransaction::query()->count())->toBe(1);
});

it('AC-3: measures "today" in the Asia/Jakarta zone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 22:00', 'UTC'));

    $tx = walletRecord($this->student, $this->user, 'setor', 1000, ['occurred_on' => '2026-10-16']);

    expect($tx->occurred_on->toDateString())->toBe('2026-10-16');

    walletAssertRejected(fn () => walletRecord($this->student, $this->user, 'setor', 1000, ['occurred_on' => '2026-10-17']), 'date_future', 'occurred_on');
});

it('AC-4: corrections move the balance and need a note', function () {
    walletRecord($this->student, $this->user, 'setor', 10000);
    walletRecord($this->student, $this->user, 'koreksi_tambah', 5000, ['note' => 'Salah hitung']);

    expect(walletBalance($this->student))->toBe(15000);

    walletRecord($this->student, $this->user, 'koreksi_kurang', 3000, ['note' => 'Salah hitung lagi']);

    expect(walletBalance($this->student))->toBe(12000);

    foreach (['koreksi_tambah', 'koreksi_kurang'] as $type) {
        walletAssertRejected(fn () => walletRecord($this->student, $this->user, $type, 1000), 'note_required', 'note');
        walletAssertRejected(fn () => walletRecord($this->student, $this->user, $type, 1000, ['note' => '   ']), 'note_required', 'note');
    }

    expect(WalletTransaction::query()->count())->toBe(3);
});

it('AC-4: refuses a downward correction beyond the balance', function () {
    walletRecord($this->student, $this->user, 'setor', 4000);

    walletAssertRejected(fn () => walletRecord($this->student, $this->user, 'koreksi_kurang', 4001, ['note' => 'Koreksi']), 'insufficient_balance', 'amount');

    expect(walletBalance($this->student))->toBe(4000);
});

it('AC-4: exposes direction and Indonesian labels per type', function () {
    expect(WalletTransactionType::Setor->isCredit())->toBeTrue()
        ->and(WalletTransactionType::KoreksiTambah->isCredit())->toBeTrue()
        ->and(WalletTransactionType::Tarik->isCredit())->toBeFalse()
        ->and(WalletTransactionType::KoreksiKurang->isCredit())->toBeFalse()
        ->and(collect(WalletTransactionType::cases())->map->value->all())->toBe(['setor', 'tarik', 'koreksi_tambah', 'koreksi_kurang']);

    expectTranslatedKeys([
        'pondok.wallet.errors.amount_invalid', 'pondok.wallet.errors.date_future', 'pondok.wallet.errors.note_invalid',
        'pondok.wallet.errors.note_required', 'pondok.wallet.errors.insufficient_balance', 'pondok.wallet.errors.student_unavailable',
        'pondok.wallet.errors.out_of_scope', 'pondok.wallet.errors.reason_invalid', 'pondok.wallet.errors.already_cancelled',
        'pondok.wallet.errors.balance_would_be_negative',
    ]);

    foreach (WalletTransactionType::cases() as $case) {
        expect($case->label())->not->toBeEmpty()->not->toContain('pondok.');
    }
});

it('AC-8: lets alumni and inactive students transact but refuses archived students', function () {
    foreach (['lulus', 'keluar', 'pindah'] as $status) {
        $student = walletStudent('SLTP', ['status' => $status]);

        walletRecord($student, $this->user, 'setor', 10000);
        walletRecord($student, $this->user, 'tarik', 4000);

        expect(walletBalance($student))->toBe(6000);
    }

    $archived = walletStudent('SLTP');
    walletRecord($archived, $this->user, 'setor', 10000);
    $archived->delete();

    walletAssertRejected(fn () => walletRecord($archived, $this->user, 'setor', 1000), 'student_unavailable');
    walletAssertRejected(fn () => walletRecord($archived, $this->user, 'tarik', 1000), 'student_unavailable');

    expect(WalletTransaction::query()->where('student_id', $archived->id)->count())->toBe(1);
});

it('AC-8: keeps the ledger rows when a student is archived and blocks permanent deletion', function () {
    walletRecord($this->student, $this->user, 'setor', 10000);
    $this->student->delete();

    expect(WalletTransaction::withoutUnitScope()->where('student_id', $this->student->id)->count())->toBe(1);

    expect(fn () => $this->student->forceDelete())->toThrow(QueryException::class);
});

it('AC-8: blocks permanently deleting a user that recorded a transaction', function () {
    walletRecord($this->student, $this->user, 'setor', 10000);

    expect(fn () => $this->user->forceDelete())->toThrow(QueryException::class);
});

it('AC-9: keeps the balance equal to the manual sum and never negative over a mixed sequence', function () {
    $steps = [
        ['setor', 100000, true], ['tarik', 30000, true], ['tarik', 80000, false], ['koreksi_tambah', 5000, true],
        ['tarik', 75000, true], ['koreksi_kurang', 1, false], ['setor', 2500, true], ['koreksi_kurang', 2500, true],
    ];
    $expected = 0;

    foreach ($steps as [$type, $amount, $ok]) {
        $note = ['note' => 'catatan'];

        if ($ok) {
            walletRecord($this->student, $this->user, $type, $amount, $note);
            $expected += WalletTransactionType::from($type)->isCredit() ? $amount : -$amount;
        } else {
            expect(fn () => walletRecord($this->student, $this->user, $type, $amount, $note))
                ->toThrow(ValidationException::class);
        }

        expect(walletBalance($this->student))->toBe($expected)->toBeGreaterThanOrEqual(0);
    }

    expect($expected)->toBe(0);
});

it('AC-9: refuses the second of two withdrawals that together exceed the balance', function () {
    walletRecord($this->student, $this->user, 'setor', 50000);
    walletRecord($this->student, $this->user, 'tarik', 40000);

    walletAssertRejected(fn () => walletRecord($this->student, $this->user, 'tarik', 40000), 'insufficient_balance', 'amount');

    expect(walletBalance($this->student))->toBe(10000);
});

it('AC-11: refuses a student outside the user\'s units, with or without a signed-in user', function () {
    $scoped = keuanganUser('bendahara', ['SD']);

    walletAssertRejected(fn () => walletRecord($this->student, $scoped, 'setor', 1000), 'out_of_scope');

    $this->actingAs(keuanganUser('bendahara'));
    walletAssertRejected(fn () => walletRecord($this->student, $scoped, 'setor', 1000), 'out_of_scope');

    expect(WalletTransaction::query()->count())->toBe(0);
});

it('AC-11: accepts a student inside the user\'s units and any student for an unscoped user', function () {
    walletRecord($this->student, keuanganUser('bendahara', ['SLTP']), 'setor', 1000);
    walletRecord($this->student, keuanganUser('bendahara'), 'setor', 1000);

    expect(walletBalance($this->student))->toBe(2000);
});

it('AC-11: a scoped user can still record for an alumni student inside the scope', function () {
    $alumni = walletStudent('SLTP', ['status' => 'lulus']);

    walletRecord($alumni, keuanganUser('bendahara', ['SLTP']), 'setor', 1000);

    expect(walletBalance($alumni))->toBe(1000);
});
