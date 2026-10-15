<?php

use App\Actions\Psb\CheckApplicantCredentials;
use App\Actions\Psb\GenerateRegistrationInvoices;
use App\Filament\Resources\Applicants\Pages\ListApplicants;
use App\Filament\Resources\Applicants\Pages\ViewApplicant;
use App\Filament\Resources\Applicants\RelationManagers\DocumentsRelationManager;
use App\Models\Applicant;
use App\Models\Invoice;
use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->f = pendaftaranFixture();
    $this->applicant = pendaftaranApplicant(['registration_no' => 'PSB-2027-0001', 'name' => 'Ahmad Fauzan', 'guardian_name' => 'Budi Santoso', 'consented_at' => now()]);
});

/**
 * Kontrak nama: `ViewApplicant` header actions `generateInvoices`, `resetToken`, `delete`; relation manager
 * `DocumentsRelationManager` memakai aksi baris `download`, `markValid`, `reject` (form `note`); filter tabel
 * `unit`, `wave`, `status`, `track`.
 */
function pendaftaranView(Applicant $applicant): Testable
{
    return Livewire::test(ViewApplicant::class, ['record' => $applicant->id]);
}

function pendaftaranDocuments(Applicant $applicant): Testable
{
    return Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $applicant, 'pageClass' => ViewApplicant::class]);
}

function pendaftaranNotificationText(): string
{
    return asramaNotifications()->map(fn (array $notification): string => ($notification['title'] ?? '').' '.($notification['body'] ?? ''))->implode("\n");
}

it('AC-31: lets admin-psb and super-admin open the list and the detail', function (string $role) {
    $user = psbUser($role);

    $this->actingAs($user)->get('/admin/applicants')->assertOk();
    $this->actingAs($user)->get("/admin/applicants/{$this->applicant->id}")->assertOk();
})->with(['admin-psb', 'super-admin']);

it('AC-31: forbids the applicant pages to other roles', function (string $role) {
    $user = psbUser($role);

    $this->actingAs($user)->get('/admin/applicants')->assertForbidden();
    $this->actingAs($user)->get("/admin/applicants/{$this->applicant->id}")->assertForbidden();
})->with(['bendahara', 'admin-sekolah', 'admin-konten', 'kesantrian', 'musyrif', 'ustadz-tahfidz', 'petugas-pos']);

it('AC-31: sends guests to the login page', function () {
    $this->get('/admin/applicants')->assertRedirect('/admin/login');
});

it('AC-31: returns 403 and hides the menu when psb is disabled', function () {
    Setting::set('modules.psb.enabled', false);

    foreach (['admin-psb', 'super-admin'] as $role) {
        $this->actingAs(psbUser($role))->get('/admin/applicants')->assertForbidden();
    }

    expect(__('pondok.applicant.plural'))->not->toBe('pondok.applicant.plural');

    $response = $this->actingAs(psbUser('admin-psb'))->get('/admin')->assertOk()->assertDontSee('/admin/applicants');

    preg_match_all('#<nav\b.*?</nav>#is', $response->getContent(), $navs);

    expect($navs[0])->not->toBeEmpty()
        ->and(bayarHtmlText(implode(' ', $navs[0])))->not->toContain(__('pondok.applicant.plural'));
});

it('AC-31: shows the applicant menu to admin-psb when psb is enabled', function () {
    $this->actingAs(psbUser('admin-psb'))->get('/admin')->assertOk()
        ->assertSee(__('pondok.applicant.plural'))
        ->assertSee('/admin/applicants');
});

it('AC-32: shows a scoped admin only the applicants of the own unit', function () {
    $sd = pendaftaranApplicant(['registration_no' => 'PSB-2027-0002', 'unit_id' => $this->f->sd->id, 'name' => 'Anak SD']);
    $this->actingAs(psbUser('admin-psb', ['SLTP']));

    Livewire::test(ListApplicants::class)
        ->assertCanSeeTableRecords([$this->applicant])
        ->assertCanNotSeeTableRecords([$sd]);

    $this->get("/admin/applicants/{$this->applicant->id}")->assertOk();
    $this->get("/admin/applicants/{$sd->id}")->assertNotFound();
});

it('AC-32: offers only the units in scope in the unit filter', function () {
    $this->actingAs(psbUser('admin-psb', ['SLTP']));
    $sltpId = $this->f->sltp->id;

    Livewire::test(ListApplicants::class)->assertTableFilterExists('unit', fn ($filter): bool => array_keys($filter->getOptions()) === [$sltpId]);
});

it('AC-32: shows every unit to an unscoped admin', function () {
    $sd = pendaftaranApplicant(['registration_no' => 'PSB-2027-0002', 'unit_id' => $this->f->sd->id]);
    $this->actingAs(psbUser('admin-psb'));

    Livewire::test(ListApplicants::class)->assertCanSeeTableRecords([$this->applicant, $sd]);
});

it('AC-32: searches by registration number and name and filters by status and wave', function () {
    $second = pendaftaranApplicant(['registration_no' => 'PSB-2027-0002', 'name' => 'Siti Aminah', 'status' => 'diverifikasi']);
    $otherWave = psbWave($this->f->y2027, 'Gelombang 2', '2027-01-01', '2027-03-31');
    $third = pendaftaranApplicant(['registration_no' => 'PSB-2027-0003', 'name' => 'Umar Faruq', 'admission_wave_id' => $otherWave->id]);
    $this->actingAs(psbUser('admin-psb'));

    Livewire::test(ListApplicants::class)
        ->searchTable('PSB-2027-0002')->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$this->applicant, $third])
        ->searchTable('Siti')->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$this->applicant, $third])
        ->searchTable('')
        ->filterTable('status', 'diajukan')->assertCanSeeTableRecords([$this->applicant, $third])->assertCanNotSeeTableRecords([$second])
        ->removeTableFilters()
        ->filterTable('wave', $otherWave->id)->assertCanSeeTableRecords([$third])->assertCanNotSeeTableRecords([$this->applicant, $second]);
});

it('AC-32: lists the newest applicants first', function () {
    $older = pendaftaranApplicant(['registration_no' => 'PSB-2027-0002', 'created_at' => now()->subDays(3)]);
    $newer = pendaftaranApplicant(['registration_no' => 'PSB-2027-0003', 'created_at' => now()->addDay()]);
    $this->actingAs(psbUser('admin-psb'));

    Livewire::test(ListApplicants::class)->assertCanSeeTableRecords([$newer, $this->applicant, $older], inOrder: true);
});

it('AC-32: shows a badge with the translated status', function (string $status, string $color) {
    $applicant = pendaftaranApplicant(['registration_no' => 'PSB-2027-0009', 'status' => $status]);
    $this->actingAs(psbUser('admin-psb'));

    expect($applicant->statusColor())->toBe($color)->and($applicant->statusLabel())->toBe(__("pondok.applicant.statuses.{$status}"))
        ->and(__("pondok.applicant.statuses.{$status}"))->not->toBe("pondok.applicant.statuses.{$status}");

    Livewire::test(ListApplicants::class)
        ->assertTableColumnFormattedStateSet('status', __("pondok.applicant.statuses.{$status}"), $applicant)
        ->assertTableColumnExists('status', fn ($column): bool => $column->isBadge(), $applicant);
})->with([
    'draft' => ['draft', 'gray'],
    'diajukan' => ['diajukan', 'gray'],
    'diverifikasi' => ['diverifikasi', 'info'],
    'diterima' => ['diterima', 'success'],
    'cadangan' => ['cadangan', 'warning'],
    'ditolak' => ['ditolak', 'danger'],
]);

it('AC-32: shows the empty state without applicants', function () {
    $this->applicant->forceDelete();
    $this->actingAs(psbUser('admin-psb'));

    Livewire::test(ListApplicants::class)->assertSee(__('pondok.applicant.empty'));
});

it('AC-32: does not list soft deleted applicants', function () {
    $this->applicant->delete();
    $this->actingAs(psbUser('admin-psb'));

    Livewire::test(ListApplicants::class)->assertCanNotSeeTableRecords([$this->applicant]);
});

it('AC-33: shows the applicant, invoices and missing documents', function () {
    app(GenerateRegistrationInvoices::class)->handle($this->applicant);
    $invoice = Invoice::withoutUnitScope()->where('applicant_id', $this->applicant->id)->sole();
    $this->actingAs(psbUser('admin-psb'));

    $text = tagihanPlainText(pendaftaranView($this->applicant));

    expect($text)->toContain('PSB-2027-0001')->toContain('Ahmad Fauzan')->toContain('Budi Santoso')
        ->toContain($invoice->invoice_no)->toContain('Rp 250.000')->toContain(__('pondok.invoice.statuses.belum'))
        ->toContain('Kartu Keluarga')->toContain('Akta kelahiran')
        ->toContain('15 Okt 2026, 10.00')
        ->not->toContain(__('pondok.applicant.completeness_ok'));
});

it('AC-33: shows the completeness message once every required document is valid', function () {
    pendaftaranDocument($this->applicant, $this->f->kk, ['status' => 'valid']);
    pendaftaranDocument($this->applicant, $this->f->akta, ['status' => 'valid', 'original_name' => 'akta.pdf']);
    $this->actingAs(psbUser('admin-psb'));

    expect(tagihanPlainText(pendaftaranView($this->applicant)))->toContain(__('pondok.applicant.completeness_ok'));
});

it('AC-33: does not count a pending document as complete', function () {
    pendaftaranDocument($this->applicant, $this->f->kk, ['status' => 'valid']);
    pendaftaranDocument($this->applicant, $this->f->akta, ['status' => 'menunggu', 'original_name' => 'akta.pdf']);
    $this->actingAs(psbUser('admin-psb'));

    expect(tagihanPlainText(pendaftaranView($this->applicant)))->not->toContain(__('pondok.applicant.completeness_ok'));
});

it('AC-33: marks a document valid from the relation manager', function () {
    $document = pendaftaranDocument($this->applicant, $this->f->kk);
    $user = psbUser('admin-psb');
    $this->actingAs($user);

    pendaftaranDocuments($this->applicant)
        ->assertCanSeeTableRecords([$document])
        ->assertActionVisible(TestAction::make('markValid')->table($document))
        ->callAction(TestAction::make('markValid')->table($document));

    expect($document->fresh())->status->toBe('valid')->verified_by->toBe($user->id)->and($document->fresh()->verified_at)->not->toBeNull();
});

it('AC-33: rejects a document only with a note', function () {
    $document = pendaftaranDocument($this->applicant, $this->f->kk);
    $this->actingAs(psbUser('admin-psb'));

    $component = pendaftaranDocuments($this->applicant)->assertActionVisible(TestAction::make('reject')->table($document));

    $component->callAction(TestAction::make('reject')->table($document), ['note' => ''])->assertHasFormErrors(['note']);

    expect($document->fresh())->status->toBe('menunggu')->note->toBeNull();

    $component->unmountAction()->callAction(TestAction::make('reject')->table($document), ['note' => 'Foto buram'])->assertHasNoFormErrors();

    expect($document->fresh())->status->toBe('ditolak')->note->toBe('Foto buram');
});

it('AC-33: hides the verification actions without the verify permission', function () {
    $document = pendaftaranDocument($this->applicant, $this->f->kk);
    $this->actingAs(dashboardUserWith(['view-any:applicant', 'view:applicant']));

    pendaftaranDocuments($this->applicant)
        ->assertActionHidden(TestAction::make('markValid')->table($document))
        ->assertActionHidden(TestAction::make('reject')->table($document));
});

it('AC-33: offers the download link of a document', function () {
    $document = pendaftaranDocument($this->applicant, $this->f->kk);
    $this->actingAs(psbUser('admin-psb'));

    pendaftaranDocuments($this->applicant)->assertActionVisible(TestAction::make('download')->table($document))
        ->assertSeeHtml("/admin/applicant-documents/{$document->id}");
});

it('AC-33: shows the empty state of documents', function () {
    $this->actingAs(psbUser('admin-psb'));

    pendaftaranDocuments($this->applicant)->assertSee(__('pondok.applicant_document.empty'));
});

it('AC-35: resets the token and shows the new one once', function () {
    $user = psbUser('admin-psb');
    $this->actingAs($user);

    pendaftaranView($this->applicant)->assertActionVisible('resetToken')->callAction('resetToken');

    expect(preg_match('/[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}/', pendaftaranNotificationText(), $match))->toBe(1);

    $check = app(CheckApplicantCredentials::class);

    expect($check->handle('PSB-2027-0001', 'ABCD-EFGH-JK23'))->toBeNull()
        ->and($check->handle('PSB-2027-0001', $match[0])?->id)->toBe($this->applicant->id);

    $activity = Activity::query()->where('event', 'token_reset')->sole();

    expect($activity)->log_name->toBe('psb')->causer_id->toBe($user->id)->subject_id->toBe($this->applicant->id)
        ->and(json_encode($activity->properties))->not->toContain('ABCD-EFGH-JK23')->not->toContain('ABCDEFGHJK23')
        ->not->toContain($match[0])->not->toContain(str_replace('-', '', $match[0]));
});

it('AC-35: hides the reset action without the permission', function () {
    $this->actingAs(dashboardUserWith(['view-any:applicant', 'view:applicant']));

    pendaftaranView($this->applicant)->assertActionHidden('resetToken');
});

it('AC-36: deletes an applicant and cancels the unpaid invoice', function () {
    app(GenerateRegistrationInvoices::class)->handle($this->applicant);
    $invoice = Invoice::withoutUnitScope()->where('applicant_id', $this->applicant->id)->sole();
    $this->actingAs(psbUser('admin-psb'));

    pendaftaranView($this->applicant)->assertActionVisible('delete')->callAction('delete');

    $activity = Activity::query()->where('subject_type', $invoice->getMorphClass())->where('subject_id', $invoice->id)->where('event', 'updated')->latest('id')->first();

    expect(Applicant::withoutUnitScope()->whereKey($this->applicant->id)->exists())->toBeFalse()
        ->and(Applicant::withoutUnitScope()->withTrashed()->whereKey($this->applicant->id)->exists())->toBeTrue()
        ->and($invoice->fresh()->status)->toBe('batal')
        ->and($activity->properties['reason'])->toBe(__('pondok.applicant.cancel_reason_deleted'))
        ->and(app(CheckApplicantCredentials::class)->handle('PSB-2027-0001', 'ABCD-EFGH-JK23'))->toBeNull();

    Livewire::test(ListApplicants::class)->assertCanNotSeeTableRecords([$this->applicant]);
});

it('AC-36: deletes an applicant from the list', function () {
    $this->actingAs(psbUser('admin-psb'));

    Livewire::test(ListApplicants::class)
        ->assertActionVisible(TestAction::make('delete')->table($this->applicant))
        ->callAction(TestAction::make('delete')->table($this->applicant));

    expect(Applicant::withoutUnitScope()->whereKey($this->applicant->id)->exists())->toBeFalse();
});

it('AC-36: refuses to delete an applicant that already paid', function () {
    pendaftaranPayRegistration($this->applicant);
    $this->actingAs(psbUser('admin-psb'));

    pendaftaranView($this->applicant)->assertActionVisible('delete')->callAction('delete');

    expect(Applicant::withoutUnitScope()->whereKey($this->applicant->id)->exists())->toBeTrue()
        ->and(Invoice::withoutUnitScope()->where('applicant_id', $this->applicant->id)->where('status', 'batal')->count())->toBe(0);
});

it('AC-36: hides the delete action without the permission', function () {
    $this->actingAs(dashboardUserWith(['view-any:applicant', 'view:applicant']));

    pendaftaranView($this->applicant)->assertActionHidden('delete');
});

it('AC-37: shows the invoice action only when there is no active registration invoice', function (string $case, bool $visible) {
    $user = psbUser('admin-psb');

    match ($case) {
        'no invoice' => null,
        'has invoice' => app(GenerateRegistrationInvoices::class)->handle($this->applicant),
        'finance off' => Setting::set('modules.keuangan.enabled', false),
        'rejected' => $this->applicant->forceFill(['status' => 'ditolak'])->save(),
        'no permission' => $user = dashboardUserWith(['view-any:applicant', 'view:applicant']),
    };

    $this->actingAs($user);
    $component = pendaftaranView($this->applicant);

    $visible ? $component->assertActionVisible('generateInvoices') : $component->assertActionHidden('generateInvoices');
})->with([
    'no invoice' => ['no invoice', true],
    'has invoice' => ['has invoice', false],
    'finance off' => ['finance off', false],
    'rejected' => ['rejected', false],
    'no permission' => ['no permission', false],
]);

it('AC-37: creates the missing registration invoice for the logged in user', function () {
    $user = psbUser('admin-psb');
    $this->actingAs($user);

    pendaftaranView($this->applicant)->assertActionVisible('generateInvoices')->callAction('generateInvoices');

    $invoice = Invoice::withoutUnitScope()->where('applicant_id', $this->applicant->id)->sole();

    expect($invoice->generated_by)->toBe($user->id)->and($invoice->total)->toBe(250000);

    pendaftaranView($this->applicant)->assertActionHidden('generateInvoices');
});

it('AC-37: tells the user when no rate is set', function () {
    $this->f->formulir->rates()->delete();
    $this->actingAs(psbUser('admin-psb'));

    pendaftaranView($this->applicant)->assertActionVisible('generateInvoices')->callAction('generateInvoices');

    expect(Invoice::withoutUnitScope()->where('applicant_id', $this->applicant->id)->count())->toBe(0)
        ->and(pendaftaranNotificationText())->toContain(__('pondok.applicant.invoices_none_generated'));
});
