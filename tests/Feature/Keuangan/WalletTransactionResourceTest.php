<?php

use App\Actions\Keuangan\VoidWalletTransaction;
use App\Filament\Pages\WalletBalances;
use App\Filament\Resources\WalletTransactions\Pages\ListWalletTransactions;
use App\Filament\Resources\WalletTransactions\WalletTransactionResource;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\UnitSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([UnitSeeder::class, RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $this->bendahara = keuanganUser('bendahara');
    $this->sltp = walletStudent('SLTP', ['name' => 'Ahmad Saku']);
    $this->sd = walletStudent('SD', ['name' => 'Budi Saku']);
});

/**
 * Mutasi lewat Action; `$student` bawaan santri SLTP.
 */
function walletTx(string $type, int $amount, $student = null, array $extra = []): WalletTransaction
{
    return walletRecord($student ?? test()->sltp, test()->bendahara, $type, $amount, $extra);
}

it('AC-10: lets bendahara and super-admin open the resource and the balance page', function (string $role) {
    $user = keuanganUser($role);

    $this->actingAs($user)->get('/admin/wallet-transactions')->assertOk();
    $this->actingAs($user)->get('/admin/wallet-balances')->assertOk();
})->with(['bendahara', 'super-admin']);

it('AC-10: forbids the resource and the balance page to other roles', function (string $role) {
    $user = keuanganUser($role);

    $this->actingAs($user)->get('/admin/wallet-transactions')->assertForbidden();
    $this->actingAs($user)->get('/admin/wallet-balances')->assertForbidden();
})->with(['admin-sekolah', 'kesantrian', 'admin-konten', 'wali']);

it('AC-10: records a transaction from the header action', function () {
    $this->actingAs($this->bendahara);

    Livewire::test(ListWalletTransactions::class)
        ->callAction('record', ['student_id' => $this->sltp->id, 'type' => 'setor', 'amount' => 75000, 'occurred_on' => '2026-10-15', 'note' => 'Titipan wali'])
        ->assertHasNoFormErrors();

    $stored = WalletTransaction::query()->firstOrFail();

    expect($stored->student_id)->toBe($this->sltp->id)
        ->and($stored->amount)->toBe(75000)
        ->and($stored->recorded_by)->toBe($this->bendahara->id)
        ->and(walletBalance($this->sltp))->toBe(75000);
});

it('AC-10: shows the Action error on the form and writes nothing', function () {
    $this->actingAs($this->bendahara);

    Livewire::test(ListWalletTransactions::class)
        ->callAction('record', ['student_id' => $this->sltp->id, 'type' => 'tarik', 'amount' => 1000, 'occurred_on' => '2026-10-15'])
        ->assertHasFormErrors(['amount']);

    expect(WalletTransaction::query()->count())->toBe(0);
});

it('AC-10: voids a transaction from the list with a required reason', function () {
    $deposit = walletTx('setor', 50000);
    $this->actingAs($this->bendahara);

    Livewire::test(ListWalletTransactions::class)
        ->callAction(TestAction::make('cancel')->table($deposit), ['reason' => ''])
        ->assertHasFormErrors(['reason']);

    Livewire::test(ListWalletTransactions::class)
        ->callAction(TestAction::make('cancel')->table($deposit), ['reason' => 'Salah input'])
        ->assertHasNoFormErrors();

    expect($deposit->fresh()->status)->toBe('batal')
        ->and($deposit->fresh()->cancel_reason)->toBe('Salah input')
        ->and(walletBalance($this->sltp))->toBe(0);
});

it('AC-10: hides cancel for voided rows and without the cancel permission', function () {
    $deposit = walletTx('setor', 50000);
    $voided = walletTx('setor', 1000);
    app(VoidWalletTransaction::class)->handle($voided, $this->bendahara, 'Salah');

    $this->actingAs($this->bendahara);

    Livewire::test(ListWalletTransactions::class)
        ->assertActionVisible(TestAction::make('cancel')->table($deposit))
        ->assertActionHidden(TestAction::make('cancel')->table($voided));

    $viewer = User::factory()->create()->givePermissionTo(['view-any:wallet-transaction', 'view:wallet-transaction']);
    $this->actingAs($viewer);

    Livewire::test(ListWalletTransactions::class)
        ->assertActionHidden(TestAction::make('cancel')->table($deposit))
        ->assertActionHidden('record');

    expect($deposit->fresh()->status)->toBe('berhasil');
});

it('AC-8: has no edit or delete actions and no edit route', function () {
    $deposit = walletTx('setor', 50000);
    $this->actingAs(keuanganUser('super-admin'));

    Livewire::test(ListWalletTransactions::class)
        ->assertActionDoesNotExist(TestAction::make('edit')->table($deposit))
        ->assertActionDoesNotExist(TestAction::make('delete')->table($deposit))
        ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());

    expect(array_keys(WalletTransactionResource::getPages()))->not->toContain('edit')->not->toContain('create');
    $this->get("/admin/wallet-transactions/{$deposit->id}/edit")->assertNotFound();
});

it('AC-11: scopes mutations to the user\'s units, archived students included', function () {
    $own = walletTx('setor', 50000);
    $archivedStudent = walletStudent('SLTP', ['name' => 'Santri Arsip']);
    $archived = walletTx('setor', 10000, $archivedStudent);
    $archivedStudent->delete();
    $other = walletTx('setor', 20000, $this->sd);

    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    Livewire::test(ListWalletTransactions::class)
        ->assertCanSeeTableRecords([$own, $archived])
        ->assertCanNotSeeTableRecords([$other]);

    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListWalletTransactions::class)->assertCanSeeTableRecords([$own, $archived, $other]);
});

it('AC-11: scopes the record action student options and refuses a forged student', function () {
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    Livewire::test(ListWalletTransactions::class)
        ->callAction('record', ['student_id' => $this->sd->id, 'type' => 'setor', 'amount' => 1000, 'occurred_on' => '2026-10-15']);

    expect(WalletTransaction::withoutUnitScope()->count())->toBe(0);
});

it('AC-11: scopes the balance recap to the user\'s units, archived students included', function () {
    walletTx('setor', 50000);
    $archivedStudent = walletStudent('SLTP', ['name' => 'Santri Arsip']);
    walletTx('setor', 10000, $archivedStudent);
    $archivedStudent->delete();
    walletTx('setor', 20000, $this->sd);

    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    $component = Livewire::test(WalletBalances::class)
        ->assertCanSeeTableRecords([$this->sltp, $archivedStudent])
        ->assertCanNotSeeTableRecords([$this->sd]);

    expect(tagihanPlainText($component))->toContain('Rp 60.000');

    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(WalletBalances::class)->assertCanSeeTableRecords([$this->sltp, $archivedStudent, $this->sd]);
});

it('AC-12: lists only students with a successful transaction and shows their balance', function () {
    $zero = walletStudent('SLTP', ['name' => 'Saldo Nol']);
    walletTx('setor', 30000, $zero);
    walletTx('tarik', 30000, $zero);
    $onlyVoided = walletStudent('SLTP', ['name' => 'Hanya Batal']);
    $voided = walletTx('setor', 5000, $onlyVoided);
    app(VoidWalletTransaction::class)->handle($voided, $this->bendahara, 'Salah');
    $never = walletStudent('SLTP', ['name' => 'Belum Pernah']);

    walletTx('setor', 70000);
    walletTx('setor', 9999, $this->sltp, ['note' => 'akan batal']);
    $toVoid = WalletTransaction::query()->where('amount', 9999)->firstOrFail();
    app(VoidWalletTransaction::class)->handle($toVoid, $this->bendahara, 'Salah');
    walletTx('setor', 50000, $this->sd);

    $this->actingAs($this->bendahara);

    $component = Livewire::test(WalletBalances::class)
        ->assertCanSeeTableRecords([$this->sltp, $this->sd, $zero])
        ->assertCanNotSeeTableRecords([$onlyVoided, $never]);

    $text = tagihanPlainText($component);

    expect($text)->toContain('Rp 70.000')->toContain('Rp 50.000')->toContain('Rp 120.000')->not->toContain('Rp 79.999');
});

it('AC-12: searches by name and the footer total follows the filtered rows', function () {
    walletTx('setor', 70000);
    walletTx('setor', 50000, $this->sd);
    $this->actingAs($this->bendahara);

    $component = Livewire::test(WalletBalances::class)->searchTable('Ahmad');

    $component->assertCanSeeTableRecords([$this->sltp])->assertCanNotSeeTableRecords([$this->sd]);

    expect(tagihanPlainText($component))->toContain('Rp 70.000')->not->toContain('Rp 120.000');

    $byNis = Livewire::test(WalletBalances::class)->searchTable($this->sd->nis);

    $byNis->assertCanSeeTableRecords([$this->sd])->assertCanNotSeeTableRecords([$this->sltp]);
});

it('AC-12: orders the recap by balance', function () {
    walletTx('setor', 70000);
    walletTx('setor', 90000, $this->sd);
    $this->actingAs($this->bendahara);

    Livewire::test(WalletBalances::class)
        ->sortTable('balance', 'desc')
        ->assertCanSeeTableRecords([$this->sd, $this->sltp], inOrder: true);
});

it('AC-14: closes the resource and the page when the keuangan module is off, keeping the data', function () {
    walletTx('setor', 50000);
    Setting::set('modules.keuangan.enabled', false);
    $user = keuanganUser('super-admin');

    $this->actingAs($user)->get('/admin/wallet-transactions')->assertForbidden();
    $this->actingAs($user)->get('/admin/wallet-balances')->assertForbidden();

    expect(WalletTransactionResource::canViewAny())->toBeFalse()
        ->and(WalletTransaction::withoutUnitScope()->count())->toBe(1);
});

it('AC-14: registers the resource and page to the keuangan module', function () {
    expect(WalletTransactionResource::getModule())->toBe('keuangan')
        ->and(WalletBalances::getModule())->toBe('keuangan');
});

it('AC-10: does not run extra queries per row on the mutation list', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ListWalletTransactions::class);
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    };

    $this->actingAs($this->bendahara);
    walletTx('setor', 1000);
    $few = $count();

    foreach (range(1, 6) as $i) {
        walletTx('setor', 1000, walletStudent('SLTP'));
    }

    expect($count())->toBeLessThanOrEqual($few + 2);
});
