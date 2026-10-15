<?php

use App\Models\Applicant;
use App\Models\FeeOverride;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->f = seleksiFixture();
});

it('VERIFY: cancels zero total acceptance invoices and ends every scholarship override without deleting rows', function () {
    $applicant = seleksiApplicant('diverifikasi', 'YATIM');
    seleksiAccept($applicant, $this->f->user);
    $invoices = Invoice::withoutUnitScope()->count();
    $items = InvoiceItem::query()->count();
    $overrides = FeeOverride::query()->count();

    $result = seleksiRun('RejectApplicant', $applicant->fresh(), $this->f->user, 'Mundur');

    expect($result['cancelled_invoices'])->toBe(2)
        ->and($result['ended_overrides'])->toBe(3)
        ->and(Invoice::withoutUnitScope()->count())->toBe($invoices)
        ->and(InvoiceItem::query()->count())->toBe($items)
        ->and(FeeOverride::query()->count())->toBe($overrides)
        ->and(seleksiInvoices($applicant, true)->whereIn('billing_group', ['PSB_TERIMA', 'PSB_KITAB'])->pluck('status')->unique()->all())->toBe(['batal']);
});

it('VERIFY: cancels an acceptance after the finance module was switched off', function () {
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');
    seleksiAccept($applicant, $this->f->user);
    Setting::set('modules.keuangan.enabled', false);

    $result = seleksiRun('RejectApplicant', $applicant->fresh(), $this->f->user, 'Mundur');

    expect(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe('ditolak')
        ->and($result['cancelled_invoices'])->toBe(2);
});

it('VERIFY: rejecting twice leaves a single decision activity', function () {
    $applicant = seleksiApplicant('diajukan');
    seleksiRun('RejectApplicant', $applicant, $this->f->user, 'Tidak lengkap');
    $before = seleksiSnapshot($applicant);

    pendaftaranAssertRejected(fn () => seleksiRun('RejectApplicant', $applicant->fresh(), $this->f->user, 'Lagi'), 'invalid_transition', 'status');

    expect(seleksiSnapshot($applicant))->toBe($before)
        ->and(Activity::query()->where('description', __('pondok.applicant.activity_decided'))->where('subject_id', $applicant->id)->count())->toBe(1);
});

it('VERIFY: keeps discounted item amounts integral and consistent', function () {
    $this->f->tahfidz->update(['default_value' => 33]);
    $this->f->rates['UANG_PANGKAL']->update(['amount' => 5000001]);
    $applicant = seleksiApplicant('diverifikasi', 'TAHFIDZ');

    seleksiAccept($applicant, $this->f->user);

    $item = seleksiItems(seleksiInvoices($applicant, group: 'PSB_TERIMA')->sole())['UANG_PANGKAL'];

    expect($item->base_amount)->toBeInt()->and($item->amount)->toBeInt()
        ->and($item->discount_amount + $item->amount)->toBe($item->base_amount);
});

it('VERIFY: refuses to run decision actions by a user without the permission', function () {
    $viewer = dashboardUserWith(['view-any:applicant', 'view:applicant']);
    $applicant = seleksiApplicant('diverifikasi');

    seleksiView($applicant, $viewer)->assertActionHidden('accept');

    expect(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe('diverifikasi')
        ->and(seleksiOverrides($applicant))->toBeEmpty()
        ->and(seleksiInvoices($applicant)->whereIn('billing_group', ['PSB_TERIMA', 'PSB_KITAB']))->toBeEmpty();
});

it('VERIFY: refuses to reopen by admin-psb even when the action is called directly', function () {
    $applicant = seleksiApplicant('ditolak');

    seleksiView($applicant, $this->f->user)->assertActionHidden('reopen');

    expect(Applicant::withoutGlobalScopes()->find($applicant->id)->status)->toBe('ditolak');
});

it('VERIFY: counts only the own unit in the dashboard widget of a scoped admin', function () {
    seleksiSummaryApplicants(2, 'diajukan');
    seleksiSummaryApplicants(3, 'diterima', ['unit_id' => $this->f->sd->id]);

    $text = dashboardWidgetText(psbUser('admin-psb', ['SLTP']), 'psb');

    expect(seleksiStatValue($text, __('pondok.dashboard.psb.total')))->toBe(2)
        ->and(seleksiStatValue($text, __('pondok.applicant.statuses.diterima')))->toBe(0);
});
