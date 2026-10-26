<?php

use App\Actions\Keuangan\RecordWalletTransaction;
use App\Actions\Keuangan\VoidWalletTransaction;
use App\Filament\Pages\WalletBalances;
use App\Filament\Resources\WalletTransactions\Pages\ListWalletTransactions;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\UnitSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed([UnitSeeder::class, RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $this->bendahara = keuanganUser('bendahara');
    $this->student = walletStudent('SLTP');
});

/**
 * @param  array<string, mixed>  $data
 */
function walletRawRecord(array $data, $student, $user): WalletTransaction
{
    return app(RecordWalletTransaction::class)->handle($student, $user, $data);
}

it('rejects loosely formatted amounts and writes nothing', function () {
    $cases = ['1.000', '1e3', '+500', '-500', ' ', '', '0x10', '１２３', 1000.0, 1000.5, true, null, [5]];

    foreach ($cases as $amount) {
        $rejected = false;

        try {
            walletRawRecord(['type' => 'setor', 'amount' => $amount, 'occurred_on' => '2026-10-15'], $this->student, $this->bendahara);
        } catch (ValidationException $exception) {
            $rejected = array_key_exists('amount', $exception->errors());
        }

        expect($rejected)->toBeTrue('Nominal harus ditolak: '.json_encode($amount));
    }

    expect(WalletTransaction::withoutUnitScope()->count())->toBe(0);
});

it('rejects an unknown type and a missing or garbage date instead of defaulting', function () {
    $bad = [
        'type tak dikenal' => ['type' => 'transfer', 'amount' => 1000, 'occurred_on' => '2026-10-15'],
        'tanggal kosong' => ['type' => 'setor', 'amount' => 1000],
        'tanggal sampah' => ['type' => 'setor', 'amount' => 1000, 'occurred_on' => 'bukan tanggal'],
    ];

    foreach ($bad as $label => $data) {
        $rejected = false;

        try {
            walletRawRecord($data, $this->student, $this->bendahara);
        } catch (ValidationException) {
            $rejected = true;
        }

        expect($rejected)->toBeTrue("Harus ditolak: {$label}");
    }

    expect(WalletTransaction::withoutUnitScope()->count())->toBe(0);
});

it('requires a real note for corrections, not whitespace, and counts note length in characters', function () {
    foreach (['koreksi_tambah', 'koreksi_kurang'] as $type) {
        $rejected = false;

        try {
            walletRawRecord(['type' => $type, 'amount' => 1000, 'occurred_on' => '2026-10-15', 'note' => "  \t "], $this->student, $this->bendahara);
        } catch (ValidationException $exception) {
            $rejected = array_key_exists('note', $exception->errors());
        }

        expect($rejected)->toBeTrue("Catatan kosong ditolak untuk {$type}");
    }

    $ok = walletRawRecord(['type' => 'setor', 'amount' => 1000, 'occurred_on' => '2026-10-15', 'note' => str_repeat('é', 255)], $this->student, $this->bendahara);

    expect(mb_strlen($ok->fresh()->note))->toBe(255);
});

it('keeps the balance exact with several maximum-size deposits', function () {
    foreach (range(1, 3) as $i) {
        walletRecord($this->student, $this->bendahara, 'setor', RecordWalletTransaction::MAX_AMOUNT);
    }

    walletRecord($this->student, $this->bendahara, 'tarik', 1);

    expect(walletBalance($this->student))->toBe((3 * RecordWalletTransaction::MAX_AMOUNT) - 1);
});

it('refuses a second void from a stale copy of the same row and logs exactly one activity per void', function () {
    $tx = walletRecord($this->student, $this->bendahara, 'setor', 5000);
    $stale = WalletTransaction::withoutUnitScope()->findOrFail($tx->id);

    app(VoidWalletTransaction::class)->handle($tx, $this->bendahara, 'Salah catat');

    expect(fn () => app(VoidWalletTransaction::class)->handle($stale, $this->bendahara, 'Lagi'))
        ->toThrow(ValidationException::class);

    $fresh = $tx->fresh();

    expect($fresh->cancel_reason)->toBe('Salah catat')
        ->and(Activity::query()->where('subject_type', WalletTransaction::class)->where('subject_id', $tx->id)->count())->toBe(2);
});

it('does not let a stale in-memory row mask a negative balance when voiding a deposit', function () {
    $deposit = walletRecord($this->student, $this->bendahara, 'setor', 10000);
    $stale = WalletTransaction::withoutUnitScope()->findOrFail($deposit->id);
    walletRecord($this->student, $this->bendahara, 'tarik', 9000);

    walletAssertRejected(
        fn () => app(VoidWalletTransaction::class)->handle($stale, $this->bendahara, 'Salah'),
        'balance_would_be_negative',
    );

    expect(walletBalance($this->student))->toBe(1000)
        ->and($deposit->fresh()->status)->toBe(WalletTransaction::STATUS_SUCCESS);
});

it('lets a view-only user see the list but not record or cancel', function () {
    $tx = walletRecord($this->student, $this->bendahara, 'setor', 5000);

    $viewer = User::factory()->create();
    $viewer->givePermissionTo(['view-any:wallet-transaction', 'view:wallet-transaction']);
    $this->actingAs($viewer);

    Livewire::test(ListWalletTransactions::class)
        ->assertActionHidden('record')
        ->assertActionHidden(TestAction::make('cancel')->table($tx));

    foreach ([
        fn () => Livewire::test(ListWalletTransactions::class)
            ->callAction('record', ['student_id' => $this->student->id, 'type' => 'setor', 'amount' => 1, 'occurred_on' => '2026-10-15']),
        fn () => Livewire::test(ListWalletTransactions::class)
            ->callAction(TestAction::make('cancel')->table($tx), ['reason' => 'Coba']),
    ] as $attempt) {
        try {
            $attempt();
        } catch (Throwable) {
            // Aksi tersembunyi atau tidak diizinkan: Livewire test menolak memanggilnya.
        }
    }

    expect(WalletTransaction::withoutUnitScope()->count())->toBe(1)
        ->and($tx->fresh()->status)->toBe(WalletTransaction::STATUS_SUCCESS);
});

it('cannot cancel a mutation of another unit through a forged table record', function () {
    $sd = walletStudent('SD');
    $foreign = walletRecord($sd, $this->bendahara, 'setor', 5000);

    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    try {
        Livewire::test(ListWalletTransactions::class)
            ->callAction(TestAction::make('cancel')->table($foreign), ['reason' => 'Coba']);
    } catch (Throwable) {
        // Baris di luar cakupan tidak ditemukan; yang penting datanya tidak berubah.
    }

    expect($foreign->fresh()->status)->toBe(WalletTransaction::STATUS_SUCCESS);
});

it('keeps a fully withdrawn student on the recap with zero balance and ignores voided rows', function () {
    walletRecord($this->student, $this->bendahara, 'setor', 5000);
    walletRecord($this->student, $this->bendahara, 'tarik', 5000);
    $voidedOnly = walletStudent('SLTP');
    $voided = walletRecord($voidedOnly, $this->bendahara, 'setor', 7000);
    app(VoidWalletTransaction::class)->handle($voided, $this->bendahara, 'Salah');

    $this->actingAs($this->bendahara);

    Livewire::test(WalletBalances::class)
        ->assertCanSeeTableRecords([$this->student])
        ->assertCanNotSeeTableRecords([$voidedOnly]);
});

it('does not run extra queries per row on the balance recap', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(WalletBalances::class);
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    };

    $this->actingAs($this->bendahara);
    walletRecord($this->student, $this->bendahara, 'setor', 1000);
    $few = $count();

    foreach (range(1, 6) as $i) {
        walletRecord(walletStudent('SLTP'), $this->bendahara, 'setor', 1000);
    }

    expect($count())->toBeLessThanOrEqual($few + 2);
});

it('does not run extra queries per mutation on the guardian portal page', function () {
    $f = waliBoot();
    $count = function () use ($f): int {
        $this->actingAs($f->wali);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get("/wali/santri/{$f->s1->id}")->assertOk();
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    };

    walletRecord($f->s1, $this->bendahara, 'setor', 1000);
    $few = $count();

    foreach (range(1, 8) as $i) {
        walletRecord($f->s1, $this->bendahara, 'setor', 1000);
    }

    expect($count())->toBeLessThanOrEqual($few);
});
