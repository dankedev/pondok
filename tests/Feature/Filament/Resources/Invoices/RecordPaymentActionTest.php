<?php

use App\Actions\Keuangan\CancelPayment;
use App\Actions\Keuangan\RecordPayment;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\RelationManagers\PaymentsRelationManager;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery\MockInterface;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $this->f = tagihanFixture();
    $this->invoice = bayarInvoice($this->f);
});

/**
 * Notifikasi Filament pada request terakhir, satu baris per notifikasi.
 */
function recordPaymentNotifications(): string
{
    return asramaNotifications()
        ->map(fn (array $notification): string => ($notification['status'] ?? '').'|'.($notification['title'] ?? '').' '.($notification['body'] ?? ''))
        ->implode("\n");
}

it('AC-33: records a payment from the invoice page', function () {
    $user = keuanganUser('bendahara');
    $this->actingAs($user);

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->mountAction('recordPayment')
        ->assertActionDataSet(function (array $state): array {
            expect((int) $state['amount'])->toBe(1200000)
                ->and(CarbonImmutable::parse($state['paid_on'])->toDateString())->toBe('2026-10-15')
                ->and($state['method'])->toBe('tunai');

            return [];
        });

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->callAction('recordPayment', ['amount' => 500000, 'paid_on' => '2026-10-15', 'method' => 'tunai'])
        ->assertHasNoFormErrors();

    $payment = Payment::query()->sole();

    expect($payment->amount)->toBe(500000)
        ->and($payment->receipt_no)->toBe('KW-202610-00001')
        ->and($payment->received_by)->toBe($user->id)
        ->and($payment->method)->toBe('tunai')
        ->and(Invoice::withoutUnitScope()->find($this->invoice->id)->status)->toBe('sebagian')
        ->and(recordPaymentNotifications())->toContain('success|')->toContain('KW-202610-00001')->toContain('Rp 500.000');
});

it('AC-33: records a payment from the invoice list', function () {
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListInvoices::class)
        ->assertActionVisible(TestAction::make('recordPayment')->table($this->invoice))
        ->callAction(TestAction::make('recordPayment')->table($this->invoice), ['amount' => 1200000, 'paid_on' => '2026-10-15', 'method' => 'tunai'])
        ->assertHasNoFormErrors();

    expect(Payment::query()->sole()->amount)->toBe(1200000)
        ->and(Invoice::withoutUnitScope()->find($this->invoice->id)->status)->toBe('lunas');
});

it('AC-34: hides record payment for paid and cancelled invoices', function () {
    $f = $this->f;
    $paid = bayarInvoice($f, tagihanStudent($f, resident: true));
    bayarRecord($paid, 1200000);
    $cancelled = tagihanInvoice(tagihanStudent($f), $f->year, ['billing_group' => 'BATAL', 'status' => 'batal']);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListInvoices::class)
        ->assertActionVisible(TestAction::make('recordPayment')->table($this->invoice))
        ->assertActionHidden(TestAction::make('recordPayment')->table($paid))
        ->assertActionHidden(TestAction::make('recordPayment')->table($cancelled));

    Livewire::test(ViewInvoice::class, ['record' => $paid->id])->assertActionHidden('recordPayment');
    Livewire::test(ViewInvoice::class, ['record' => $cancelled->id])->assertActionHidden('recordPayment');
});

it('AC-34: hides record payment without the create payment permission', function () {
    $viewer = User::factory()->create()->givePermissionTo(['view-any:invoice', 'view:invoice']);
    $this->actingAs($viewer);

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->assertActionHidden('recordPayment')
        ->mountAction('recordPayment')
        ->assertActionNotMounted('recordPayment');
    Livewire::test(ListInvoices::class)->assertActionHidden(TestAction::make('recordPayment')->table($this->invoice));

    expect(Payment::query()->count())->toBe(0);
});

it('AC-34: keeps invoices of other units out of reach', function () {
    $f = $this->f;
    $slta = tagihanInvoice(tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta), $f->year, ['billing_group' => 'SLTA', 'total' => 500000]);
    $slta->items()->create(['fee_type_id' => $f->spp->id, 'description' => 'SPP', 'base_amount' => 500000, 'discount_amount' => 0, 'amount' => 500000, 'rate_source' => 'jenjang']);
    $payment = Payment::factory()->create(['invoice_id' => $slta->id]);
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    $this->get("/admin/invoices/{$slta->id}")->assertNotFound();

    Livewire::test(ListInvoices::class)
        ->assertCanNotSeeTableRecords([$slta])
        ->assertCanSeeTableRecords([$this->invoice]);

    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $slta, 'pageClass' => ViewInvoice::class])
        ->assertCanNotSeeTableRecords([$payment]);

    expect(Payment::withoutUnitScope()->count())->toBe(1);
});

it('AC-35: shows payment errors on the form', function () {
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->callAction('recordPayment', ['amount' => 2000000, 'paid_on' => '2026-10-15', 'method' => 'tunai'])
        ->assertHasFormErrors(['amount'])
        ->assertActionMounted('recordPayment');

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->callAction('recordPayment', ['amount' => 0, 'paid_on' => '2026-10-15', 'method' => 'tunai'])
        ->assertHasFormErrors(['amount']);

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->callAction('recordPayment', ['amount' => 100000, 'paid_on' => '2026-10-16', 'method' => 'tunai'])
        ->assertHasFormErrors(['paid_on']);

    expect(Payment::query()->count())->toBe(0);
});

it('AC-35: shows a danger notification when the invoice was closed elsewhere', function () {
    $this->mock(RecordPayment::class, fn (MockInterface $mock) => $mock->shouldReceive('handle')->andThrow(
        ValidationException::withMessages(['invoice' => __('pondok.payment.errors.invoice_cancelled')]),
    ));
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->callAction('recordPayment', ['amount' => 100000, 'paid_on' => '2026-10-15', 'method' => 'tunai'])
        ->assertHasNoFormErrors();

    expect(recordPaymentNotifications())->toContain('danger|')->toContain(__('pondok.payment.errors.invoice_cancelled'))
        ->and(Payment::query()->count())->toBe(0);
});

it('AC-35: shows a danger notification when the receipt number cannot be issued', function () {
    $this->mock(RecordPayment::class, fn (MockInterface $mock) => $mock->shouldReceive('handle')->andThrow(
        new RuntimeException(__('pondok.payment.errors.receipt_number_conflict')),
    ));
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->callAction('recordPayment', ['amount' => 100000, 'paid_on' => '2026-10-15', 'method' => 'tunai'])
        ->assertActionMounted('recordPayment');

    expect(recordPaymentNotifications())->toContain('danger|')->toContain(__('pondok.payment.errors.receipt_number_conflict'));
});

it('AC-36: uploads transfer proof from the panel', function () {
    Storage::fake('private');
    Storage::fake('public');
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->callAction('recordPayment', ['amount' => 500000, 'paid_on' => '2026-10-15', 'method' => 'transfer', 'proof' => UploadedFile::fake()->image('bukti.jpg')])
        ->assertHasNoFormErrors();

    $payment = Payment::query()->sole();

    expect($payment->method)->toBe('transfer')
        ->and($payment->proof_path)->toStartWith('payment-proofs/2026/10/')
        ->and(Storage::disk('private')->exists($payment->proof_path))->toBeTrue()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('AC-36: shows the proof field only for transfers', function () {
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->mountAction('recordPayment')
        ->assertFormFieldHidden('proof')
        ->fillForm(['method' => 'transfer'])
        ->assertFormFieldVisible('proof')
        ->fillForm(['method' => 'tunai'])
        ->assertFormFieldHidden('proof');
});

it('AC-37: shows paid amounts and payments on the invoice', function () {
    $user = keuanganUser('bendahara');
    $first = bayarRecord($this->invoice, 500000, by: $user);
    $second = bayarRecord($this->invoice, 100000, by: $user);
    app(CancelPayment::class)->handle($second, $user, 'Salah');
    $this->actingAs($user);

    $detail = tagihanPlainText(Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id]));

    expect($detail)->toContain(__('pondok.payment.paid_amount'))
        ->and(__('pondok.payment.paid_amount'))->toBe('Terbayar')
        ->and($detail)->toContain('Rp 400.000')
        ->toContain('Rp 100.000');

    $manager = Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->invoice, 'pageClass' => ViewInvoice::class])
        ->assertCanSeeTableRecords([$second, $first], inOrder: true);
    $text = tagihanPlainText($manager);

    expect($text)->toContain($first->receipt_no)->toContain($second->receipt_no)
        ->toContain('Diterima')->toContain('Batal')
        ->toContain('15 Okt 2026')
        ->toContain('Tunai')
        ->toContain('Rp 500.000')
        ->toContain($user->name);
});

it('AC-37: shows the empty state without payments', function () {
    $this->actingAs(keuanganUser('bendahara'));

    $text = tagihanPlainText(Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->invoice, 'pageClass' => ViewInvoice::class]));

    expect($text)->toContain('Belum ada pembayaran untuk tagihan ini.');
});

it('AC-37: lists only the payments of its own invoice', function () {
    $mine = bayarRecord($this->invoice, 100000);
    $other = bayarRecord(bayarInvoice($this->f, tagihanStudent($this->f, resident: true)), 100000);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->invoice, 'pageClass' => ViewInvoice::class])
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other]);
});

it('AC-38: cancels a payment from the invoice relation manager', function () {
    $payment = bayarRecord($this->invoice, 500000);
    $cancelled = bayarRecord($this->invoice, 100000);
    app(CancelPayment::class)->handle($cancelled, keuanganUser('bendahara'), 'Salah');
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->invoice, 'pageClass' => ViewInvoice::class])
        ->assertActionHidden(TestAction::make('cancel')->table($cancelled))
        ->callAction(TestAction::make('cancel')->table($payment), ['reason' => 'Salah input'])
        ->assertHasNoFormErrors();

    expect($payment->fresh()->status)->toBe('batal')
        ->and(Invoice::withoutUnitScope()->find($this->invoice->id)->paid_total)->toBe(0)
        ->and(recordPaymentNotifications())->toContain('success|');
});

it('AC-38: requires a reason and permission to cancel from the relation manager', function () {
    $payment = bayarRecord($this->invoice, 500000);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->invoice, 'pageClass' => ViewInvoice::class])
        ->callAction(TestAction::make('cancel')->table($payment), ['reason' => ''])
        ->assertHasFormErrors(['reason']);

    $viewer = User::factory()->create()->givePermissionTo(['view-any:invoice', 'view:invoice', 'view-any:payment', 'view:payment']);
    $this->actingAs($viewer);

    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->invoice, 'pageClass' => ViewInvoice::class])
        ->assertActionHidden(TestAction::make('cancel')->table($payment));

    expect($payment->fresh()->status)->toBe('diterima');
});

it('AC-27: offers no create, edit or delete on the relation manager', function () {
    $payment = bayarRecord($this->invoice, 500000);
    $this->actingAs(keuanganUser('super-admin'));

    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->invoice, 'pageClass' => ViewInvoice::class])
        ->assertActionDoesNotExist(TestAction::make('delete')->table($payment))
        ->assertActionDoesNotExist(TestAction::make('edit')->table($payment))
        ->assertActionDoesNotExist(TestAction::make('create')->table())
        ->assertActionDoesNotExist(TestAction::make('associate')->table())
        ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());
});

it('AC-47: has no raw lang keys', function () {
    bayarRecord($this->invoice, 500000);
    $this->actingAs(keuanganUser('bendahara'));

    $detail = Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id]);
    $modal = Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])->mountAction('recordPayment');
    $manager = Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->invoice, 'pageClass' => ViewInvoice::class]);
    $cancel = Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->invoice, 'pageClass' => ViewInvoice::class])
        ->mountAction(TestAction::make('cancel')->table(Payment::query()->first()));

    foreach ([$detail, $modal, $manager, $cancel] as $component) {
        expect(tagihanPlainText($component))->not->toContain('pondok.payment.')->not->toContain('pondok.receipt.')->not->toContain('pondok.invoice.');
    }
});
