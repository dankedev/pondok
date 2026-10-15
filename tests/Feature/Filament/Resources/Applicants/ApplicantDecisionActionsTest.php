<?php

use App\Actions\Psb\GenerateAcceptanceInvoices;
use App\Filament\Resources\Applicants\Pages\ListApplicants;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\FeeOverride;
use App\Models\FeeRate;
use App\Models\Setting;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->f = seleksiFixture();
});

/**
 * Semua aksi header keputusan dan penagihan penerimaan pada `ViewApplicant`.
 *
 * @var list<string>
 */
const SELEKSI_DECISION_ACTIONS = ['verify', 'accept', 'reserve', 'return', 'reject', 'reopen', 'cancelAcceptance'];

/**
 * Pastikan tepat aksi di `$visible` yang tampil di antara aksi keputusan.
 *
 * @param  list<string>  $visible
 */
function seleksiAssertDecisionActions(Testable $component, array $visible): void
{
    foreach (SELEKSI_DECISION_ACTIONS as $name) {
        in_array($name, $visible, true) ? $component->assertActionVisible($name) : $component->assertActionHidden($name);
    }
}

it('AC-24: shows each decision action only for the statuses it applies to', function (string $status, array $visible) {
    $applicant = seleksiApplicant($status);

    seleksiAssertDecisionActions(seleksiView($applicant, $this->f->user), $visible);
})->with([
    'draft' => ['draft', []],
    'diajukan' => ['diajukan', ['verify', 'reject']],
    'diverifikasi' => ['diverifikasi', ['accept', 'reserve', 'reject', 'return']],
    'cadangan' => ['cadangan', ['accept', 'reject']],
    'diterima' => ['diterima', ['cancelAcceptance']],
    'ditolak' => ['ditolak', []],
]);

it('AC-24: hides every decision action from a user without the decide permission', function (string $status) {
    $applicant = seleksiApplicant($status);
    $viewer = dashboardUserWith(['view-any:applicant', 'view:applicant']);

    seleksiAssertDecisionActions(seleksiView($applicant, $viewer), []);
})->with(['diajukan', 'diverifikasi', 'cadangan', 'diterima', 'ditolak']);

it('AC-24: keeps the applicant page closed to the treasurer', function () {
    $applicant = seleksiApplicant();

    $this->actingAs(psbUser('bendahara'))->get("/admin/applicants/{$applicant->id}")->assertForbidden();
});

it('AC-24: offers billing the acceptance only while a fee group has no active invoice', function () {
    $applicant = seleksiApplicant('diterima');

    seleksiView($applicant, $this->f->user)->assertActionVisible('generateAcceptanceInvoices');

    app(GenerateAcceptanceInvoices::class)->handle($applicant, $this->f->user);

    seleksiView($applicant, $this->f->user)->assertActionHidden('generateAcceptanceInvoices');
});

it('AC-25: accepts through the panel and shows the decision, invoices and scholarship', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    $component = seleksiView($applicant, $this->f->user);

    $component->assertActionVisible('accept')->callAction('accept', ['review_note' => 'Lulus tes tahfidz']);

    $notifications = seleksiNotificationText();
    $fresh = Applicant::withoutGlobalScopes()->find($applicant->id);

    expect($fresh)->status->toBe('diterima')->review_note->toBe('Lulus tes tahfidz')->reviewed_by->toBe($this->f->user->id)
        ->and($notifications)->toContain(seleksiText('pondok.applicant.accepted_with_invoices', ['count' => 2]))
        ->toContain(seleksiText('pondok.applicant.scholarship_applied', ['scholarship' => 'Beasiswa Tahfidz', 'count' => 2]));

    $text = tagihanPlainText(seleksiView($fresh));

    expect($text)->toContain(seleksiText('pondok.applicant.section_decision'))
        ->toContain($this->f->user->name)
        ->toContain('20 Nov 2026, 10.00')
        ->toContain('Lulus tes tahfidz')
        ->toContain(seleksiText('pondok.applicant.section_invoices'))
        ->toContain('Rp 250.000')
        ->toContain('Rp 3.250.000')
        ->toContain('Rp 300.000')
        ->toContain(seleksiText('pondok.applicant.section_overrides'))
        ->toContain('Beasiswa Tahfidz');

    foreach (seleksiInvoices($applicant) as $invoice) {
        expect($text)->toContain($invoice->invoice_no);
    }
});

it('AC-25: titles the invoice section Tagihan and shows the remaining amount', function () {
    $applicant = seleksiApplicant('diverifikasi');
    seleksiAccept($applicant, $this->f->user);
    bayarRecord(seleksiInvoices($applicant, group: 'PSB_TERIMA')->sole(), 1000000);

    $text = tagihanPlainText(seleksiView(Applicant::withoutGlobalScopes()->find($applicant->id), $this->f->user));

    expect(seleksiText('pondok.applicant.section_invoices'))->toBe('Tagihan')
        ->and($text)->toContain('Rp 5.750.000')
        ->toContain('Rp 1.000.000')
        ->toContain('Rp 4.750.000');
});

it('AC-25: hides the scholarship section when the applicant has no override', function () {
    $applicant = seleksiApplicant('diverifikasi', 'REGULER');

    seleksiView($applicant, $this->f->user)->assertActionVisible('accept')->callAction('accept');

    expect(tagihanPlainText(seleksiView(Applicant::withoutGlobalScopes()->find($applicant->id))))->not->toContain(seleksiText('pondok.applicant.section_overrides'));
});

it('AC-25: shows a custom override with its own label', function () {
    $applicant = seleksiApplicant('diterima');
    FeeOverride::factory()->forApplicant($applicant->id)->discount(100000)->create(['fee_type_id' => $this->f->kitab->id, 'scholarship_id' => null]);

    $text = tagihanPlainText(seleksiView($applicant, $this->f->user));

    expect($text)->toContain(seleksiText('pondok.applicant.override_custom'))->toContain('Kitab');
});

it('AC-25: notifies only that accepting works when the finance module is off', function () {
    $applicant = seleksiApplicant('diverifikasi');
    Setting::set('modules.keuangan.enabled', false);

    seleksiView($applicant, $this->f->user)->assertActionVisible('accept')->callAction('accept');

    expect(seleksiNotificationText())->toContain(seleksiText('pondok.applicant.accepted'))
        ->and(seleksiInvoices($applicant)->whereIn('billing_group', ['PSB_TERIMA', 'PSB_KITAB']))->toBeEmpty();
});

it('AC-25: warns about the fee types that have no rate', function () {
    FeeRate::query()->where('fee_type_id', $this->f->kitab->id)->delete();
    $applicant = seleksiApplicant();

    seleksiView($applicant, $this->f->user)->assertActionVisible('accept')->callAction('accept');

    expect(seleksiNotificationText())->toContain(seleksiText('pondok.applicant.missing_rates', ['fee_types' => 'Kitab']));
});

it('AC-25: reports a billing failure without undoing the acceptance', function () {
    $applicant = seleksiApplicant();
    pendaftaranFakeInvoiceNumber(always: seleksiInvoices($applicant)->first()->invoice_no);

    seleksiView($applicant, $this->f->user)->assertActionVisible('accept')->callAction('accept');

    expect(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe('diterima')
        ->and(seleksiNotificationText())->toContain(seleksiText('pondok.applicant.billing_failed'));
});

it('AC-25: asks for a note when reserving, returning or rejecting', function (string $action, string $status) {
    $applicant = seleksiApplicant($status);

    seleksiView($applicant, $this->f->user)
        ->assertActionVisible($action)
        ->callAction($action, ['review_note' => ''])
        ->assertHasActionErrors(['review_note']);

    expect(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe($status);
})->with([
    'reserve' => ['reserve', 'diverifikasi'],
    'return' => ['return', 'diverifikasi'],
    'reject' => ['reject', 'diajukan'],
    'cancel acceptance' => ['cancelAcceptance', 'diterima'],
]);

it('AC-25: verifies through the panel and refuses while documents are incomplete', function () {
    $incomplete = seleksiApplicant('diajukan');
    ApplicantDocument::query()->where('applicant_id', $incomplete->id)->where('admission_requirement_id', $this->f->akta->id)->update(['status' => 'menunggu']);

    $component = seleksiView($incomplete, $this->f->user)->assertActionVisible('verify')->callAction('verify');

    expect(Applicant::withoutGlobalScopes()->find($incomplete->id)->status)->toBe('diajukan')
        ->and(seleksiSeeMessage($component, 'documents_incomplete'))->toBeTrue();

    $ready = seleksiApplicant('diajukan');

    seleksiView($ready, $this->f->user)->assertActionVisible('verify')->callAction('verify');

    expect(Applicant::withoutGlobalScopes()->find($ready->id)->status)->toBe('diverifikasi');
});

it('AC-25: refuses to cancel an acceptance with a payment and shows why', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    seleksiView($applicant, $this->f->user)->assertActionVisible('accept')->callAction('accept');
    bayarRecord(seleksiInvoices($applicant, group: 'PSB_TERIMA')->sole(), 100000);

    $component = seleksiView(Applicant::withoutGlobalScopes()->find($applicant->id), $this->f->user)
        ->assertActionVisible('cancelAcceptance')
        ->callAction('cancelAcceptance', ['review_note' => 'Mengundurkan diri']);

    expect(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe('diterima')
        ->and(seleksiSeeMessage($component, 'acceptance_has_payments'))->toBeTrue();
});

it('AC-20: rejects through the panel and cancels the unpaid registration invoice', function () {
    $applicant = seleksiApplicant('diajukan', complete: false);

    seleksiView($applicant, $this->f->user)->assertActionVisible('reject')->callAction('reject', ['review_note' => 'Tidak memenuhi syarat']);

    expect(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe('ditolak')
        ->and(seleksiInvoices($applicant, true)->sole()->status)->toBe('batal');
});

it('AC-20: cancels an acceptance through the panel', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    seleksiView($applicant, $this->f->user)->assertActionVisible('accept')->callAction('accept');

    seleksiView(Applicant::withoutGlobalScopes()->find($applicant->id), $this->f->user)
        ->assertActionVisible('cancelAcceptance')
        ->callAction('cancelAcceptance', ['review_note' => 'Mengundurkan diri']);

    expect(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe('ditolak')
        ->and(seleksiInvoices($applicant)->whereIn('billing_group', ['PSB_TERIMA', 'PSB_KITAB']))->toBeEmpty();
});

it('AC-26: offers the acceptance billing again after the finance module is turned on', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    Setting::set('modules.keuangan.enabled', false);
    seleksiAccept($applicant, $this->f->user);

    seleksiView(Applicant::withoutGlobalScopes()->find($applicant->id), $this->f->user)->assertActionHidden('generateAcceptanceInvoices');

    Setting::set('modules.keuangan.enabled', true);

    $component = seleksiView(Applicant::withoutGlobalScopes()->find($applicant->id), $this->f->user);

    $component->assertActionVisible('generateAcceptanceInvoices')->callAction('generateAcceptanceInvoices');

    expect(seleksiInvoices($applicant)->whereIn('billing_group', ['PSB_TERIMA', 'PSB_KITAB']))->toHaveCount(2)
        ->and(seleksiOverrides($applicant))->toHaveCount(2)
        ->and(seleksiNotificationText())->toContain(seleksiText('pondok.applicant.acceptance_invoices_generated', ['count' => 2]));

    $component->assertActionHidden('generateAcceptanceInvoices');
});

it('AC-26: hides the acceptance billing without the permission, with the module off and outside the accepted status', function () {
    $accepted = seleksiApplicant('diterima');
    $viewer = dashboardUserWith(['view-any:applicant', 'view:applicant', 'decide:applicant']);

    seleksiView($accepted, $viewer)->assertActionHidden('generateAcceptanceInvoices');

    seleksiView($accepted, $this->f->user)->assertActionVisible('generateAcceptanceInvoices');

    foreach (['diajukan', 'diverifikasi', 'cadangan', 'ditolak'] as $status) {
        seleksiView(seleksiApplicant($status), $this->f->user)->assertActionHidden('generateAcceptanceInvoices');
    }

    Setting::set('modules.keuangan.enabled', false);

    seleksiView($accepted, $this->f->user)->assertActionHidden('generateAcceptanceInvoices');
});

it('AC-26: tells when every fee type was skipped', function () {
    FeeRate::query()->whereIn('fee_type_id', [$this->f->uangPangkal->id, $this->f->seragam->id, $this->f->kitab->id])->delete();
    $applicant = seleksiApplicant('diterima');

    seleksiView($applicant, $this->f->user)->assertActionVisible('generateAcceptanceInvoices')->callAction('generateAcceptanceInvoices');

    expect(seleksiNotificationText())->toContain(seleksiText('pondok.applicant.acceptance_invoices_none_generated'))
        ->and(seleksiInvoices($applicant)->whereIn('billing_group', ['PSB_TERIMA', 'PSB_KITAB']))->toBeEmpty();
});

it('AC-27: hides an applicant of another unit from a scoped admin and lets the own unit decide', function () {
    $other = seleksiApplicant('diverifikasi', unit: 'SD');
    $own = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    $scoped = psbUser('admin-psb', ['SLTP']);

    $this->actingAs($scoped)->get("/admin/applicants/{$other->id}")->assertNotFound();
    $this->actingAs($scoped)->get("/admin/applicants/{$own->id}")->assertOk();

    seleksiView($own, $scoped)->assertActionVisible('accept')->callAction('accept');

    $overrides = seleksiOverrides($own);

    expect(Applicant::withoutGlobalScopes()->find($own->id)->status)->toBe('diterima')
        ->and($overrides)->toHaveCount(2)
        ->and($overrides->pluck('approved_by')->unique()->all())->toBe([$scoped->id])
        ->and(Applicant::withoutGlobalScopes()->find($other->id)->status)->toBe('diverifikasi');
});

it('AC-33: lets only a user with the reopen permission restore a rejected applicant', function () {
    $applicant = seleksiApplicant('ditolak');
    $admin = psbUser('super-admin');

    $component = seleksiView($applicant, $admin)->assertActionVisible('reopen');

    $component->callAction('reopen', ['review_note' => 'Salah tolak']);

    expect(Applicant::withoutGlobalScopes()->find($applicant->id))->status->toBe('diajukan')->review_note->toBe('Salah tolak')->reviewed_by->toBe($admin->id);
});

it('AC-33: keeps the reopen action from admin-psb and from other statuses', function () {
    $rejected = seleksiApplicant('ditolak');

    seleksiView($rejected, $this->f->user)->assertActionHidden('reopen');

    $admin = psbUser('super-admin');

    foreach (['diajukan', 'diverifikasi', 'cadangan', 'diterima'] as $status) {
        seleksiView(seleksiApplicant($status), $admin)->assertActionHidden('reopen');
    }
});

it('AC-33: shows the reopen action to a user who got both permissions or only the reopen permission', function (array $permissions) {
    $applicant = seleksiApplicant('ditolak');

    seleksiView($applicant, dashboardUserWith($permissions))->assertActionVisible('reopen');
})->with([
    'decide and reopen' => [['view-any:applicant', 'view:applicant', 'decide:applicant', 'reopen:applicant']],
    'reopen only' => [['view-any:applicant', 'view:applicant', 'reopen:applicant']],
]);

it('AC-25: offers a decision time column that is hidden by default in the list', function () {
    $this->actingAs($this->f->user);

    $table = Livewire::test(ListApplicants::class)->assertTableColumnExists('reviewed_at')->instance()->getTable();

    expect($table->getColumn('reviewed_at')->isToggleable())->toBeTrue()
        ->and($table->getColumn('reviewed_at')->isToggledHiddenByDefault())->toBeTrue();
});

it('AC-25: offers no decision actions in the list rows or in bulk', function () {
    $applicant = seleksiApplicant('diverifikasi');
    $this->actingAs($this->f->user);

    $table = Livewire::test(ListApplicants::class)->assertCanSeeTableRecords([$applicant])->instance()->getTable();

    expect($table->getFlatBulkActions())->toBeEmpty();

    foreach (['accept', 'reject', 'reserve', 'verify'] as $name) {
        expect($table->getAction($name))->toBeNull();
    }
});
