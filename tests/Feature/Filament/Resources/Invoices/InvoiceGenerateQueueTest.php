<?php

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Jobs\Keuangan\GenerateInvoicesJob;
use App\Models\AcademicYear;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->travelTo('2026-10-15 09:00:00');
    $this->f = tagihanFixture();
    Queue::fake();
});

function queuedToastText(): string
{
    return asramaNotifications()
        ->map(fn (array $notification): string => ($notification['status'] ?? '').'|'.($notification['title'] ?? '').' '.($notification['body'] ?? ''))
        ->implode("\n");
}

it('AC-3: queues the monthly generator once and only confirms with a toast', function () {
    tagihanStudent($this->f);
    $user = keuanganUser('bendahara');
    $this->actingAs($user);

    Livewire::test(ListInvoices::class)
        ->callAction('generate', ['period' => '2026-10-01'])
        ->assertHasNoFormErrors();

    Queue::assertPushed(GenerateInvoicesJob::class, 1);
    expect(Invoice::withoutUnitScope()->count())->toBe(0)
        ->and(queuedToastText())->toContain('success|')->toContain(__('pondok.invoice.generate_queued', ['period' => 'Oktober 2026']));

    app()->call([Queue::pushed(GenerateInvoicesJob::class)->first(), 'handle']);

    $invoice = Invoice::withoutUnitScope()->sole();
    expect($invoice->generated_by)->toBe($user->id)
        ->and($invoice->period->toDateString())->toBe('2026-10-01');
});

it('AC-4: queues the annual generator once and names the academic year', function () {
    tahunanFeeType($this->f);
    tagihanStudent($this->f);
    $user = keuanganUser('bendahara');
    $this->actingAs($user);

    Livewire::test(ListInvoices::class)
        ->callAction('generateAnnual', ['academic_year_id' => $this->f->year->id])
        ->assertHasNoFormErrors();

    Queue::assertPushed(GenerateInvoicesJob::class, 1);
    expect(Invoice::withoutUnitScope()->count())->toBe(0)
        ->and(queuedToastText())->toContain('success|')->toContain(__('pondok.invoice.generate_queued', ['period' => '2026/2027']));

    app()->call([Queue::pushed(GenerateInvoicesJob::class)->first(), 'handle']);

    expect(Invoice::withoutUnitScope()->sole()->generated_by)->toBe($user->id);
});

it('AC-3: explains where the result will appear', function () {
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListInvoices::class)->callAction('generate', ['period' => '2026-10-01']);

    expect(queuedToastText())->toContain(__('pondok.invoice.generate_queued_body'));
});

it('AC-5: keeps the prerequisites synchronous and dispatches nothing when they fail', function (string $action, string $field, Closure $arrange) {
    tahunanFeeType($this->f);
    $data = $arrange->call($this);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListInvoices::class)
        ->callAction($action, $data)
        ->assertHasFormErrors([$field]);

    Queue::assertNothingPushed();
})->with([
    'periode tanpa tahun ajaran' => ['generate', 'period', fn (): array => ['period' => '2030-01-01']],
    'bulan tagihan tahunan belum diatur' => ['generateAnnual', 'academic_year_id', function (): array {
        Setting::set('keuangan.annual_billing_month', null);

        return ['academic_year_id' => $this->f->year->id];
    }],
    'bulan tagihan tahunan di luar tahun ajaran' => ['generateAnnual', 'academic_year_id', function (): array {
        Setting::set('keuangan.annual_billing_month', 12);
        AcademicYear::query()->whereKey($this->f->year->id)->update(['ends_on' => '2026-09-30']);

        return ['academic_year_id' => $this->f->year->id];
    }],
]);

it('AC-6: a user without the generate permission cannot use either action', function () {
    $this->actingAs(User::factory()->create()->givePermissionTo(['view-any:invoice', 'view:invoice']));

    Livewire::test(ListInvoices::class)
        ->assertActionHidden('generate')
        ->assertActionHidden('generateAnnual');

    Queue::assertNothingPushed();
});

it('AC-6: roles without invoice access cannot open the list that holds the actions', function (string $role) {
    $this->actingAs(keuanganUser($role));

    Livewire::test(ListInvoices::class)->assertForbidden();

    Queue::assertNothingPushed();
})->with(['admin-konten', 'petugas-pos']);
