<?php

use App\Filament\Resources\AdmissionRequirements\Pages\ManageAdmissionRequirements;
use App\Models\AdmissionRequirement;
use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;

const PSB_URLS = ['/admin/admission-waves', '/admin/admission-tracks', '/admin/admission-requirements'];

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
});

it('AC-23: lets admin-psb and super-admin manage psb master data', function (string $role) {
    $user = psbUser($role);

    foreach (PSB_URLS as $url) {
        $this->actingAs($user)->get($url)->assertOk();
    }
})->with(['admin-psb', 'super-admin']);

it('AC-23: forbids psb master pages to other roles', function (string $role) {
    $user = psbUser($role);

    foreach (PSB_URLS as $url) {
        $this->actingAs($user)->get($url)->assertForbidden();
    }
})->with(['bendahara', 'admin-sekolah', 'kesantrian', 'musyrif', 'admin-konten', 'ustadz-tahfidz', 'petugas-pos']);

it('AC-23: sends guests to the login page', function (string $url) {
    $this->get($url)->assertRedirect('/admin/login');
})->with(PSB_URLS);

it('AC-24: returns 403 and hides the menu when psb is disabled', function () {
    Setting::set('modules.psb.enabled', false);

    foreach (['super-admin', 'admin-psb'] as $role) {
        $user = psbUser($role);

        foreach (PSB_URLS as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }

    $response = $this->actingAs(psbUser('super-admin'))->get('/admin')->assertOk()
        ->assertDontSee(__('pondok.admission_wave.plural'))
        ->assertDontSee(__('pondok.admission_track.plural'))
        ->assertDontSee(__('pondok.admission_requirement.plural'));

    foreach (PSB_URLS as $url) {
        $response->assertDontSee($url);
    }
});

it('AC-24: keeps the dashboard of admin-psb working without the psb menu when psb is disabled', function () {
    Setting::set('modules.psb.enabled', false);

    $this->actingAs(psbUser('admin-psb'))->get('/admin')->assertOk()
        ->assertDontSee(__('pondok.admission_wave.plural'))
        ->assertDontSee('/admin/admission-waves');
});

it('AC-24: shows the psb menu to admin-psb when psb is enabled', function () {
    expect(__('pondok.navigation.psb'))->not->toBe('pondok.navigation.psb')
        ->and(__('pondok.admission_wave.plural'))->not->toBe('pondok.admission_wave.plural')
        ->and(__('pondok.admission_track.plural'))->not->toBe('pondok.admission_track.plural')
        ->and(__('pondok.admission_requirement.plural'))->not->toBe('pondok.admission_requirement.plural');

    $response = $this->actingAs(psbUser('admin-psb'))->get('/admin')->assertOk()
        ->assertSee(__('pondok.navigation.psb'))
        ->assertSee(__('pondok.admission_wave.plural'))
        ->assertSee(__('pondok.admission_track.plural'))
        ->assertSee(__('pondok.admission_requirement.plural'));

    foreach (PSB_URLS as $url) {
        $response->assertSee($url);
    }
});

it('AC-24: hides the psb menu from roles without psb permissions', function () {
    $this->actingAs(psbUser('bendahara'))->get('/admin')->assertOk()
        ->assertDontSee(__('pondok.admission_wave.plural'))
        ->assertDontSee('/admin/admission-waves');
});

it('AC-25: limits a scoped admin-psb to the requirements of the own unit and all units', function () {
    $f = psbFixture();
    $all = AdmissionRequirement::factory()->create(['unit_id' => null, 'document_type_id' => $f->types['KK']->id, 'name' => 'KK semua']);
    $sltp = AdmissionRequirement::factory()->create(['unit_id' => $f->sltp->id, 'document_type_id' => $f->types['AKTA']->id, 'name' => 'Akta SLTP']);
    $sd = AdmissionRequirement::factory()->create(['unit_id' => $f->sd->id, 'document_type_id' => $f->types['RAPOR']->id, 'name' => 'Rapor SD']);
    $this->actingAs(psbUser('admin-psb', ['SLTP']));

    Livewire::test(ManageAdmissionRequirements::class)
        ->assertCanSeeTableRecords([$all, $sltp])
        ->assertCanNotSeeTableRecords([$sd])
        ->assertActionHidden(TestAction::make('edit')->table($all))
        ->assertActionHidden(TestAction::make('delete')->table($all))
        ->assertActionVisible(TestAction::make('edit')->table($sltp))
        ->assertActionVisible(TestAction::make('delete')->table($sltp))
        ->mountAction('create')
        ->assertSchemaComponentExists('unit_id', checkComponentUsing: fn ($component): bool => array_keys($component->getOptions()) === [$f->sltp->id]);
});

it('AC-25: refuses a scoped admin-psb who tries table actions on all-units and other-unit requirements', function () {
    $f = psbFixture();
    $all = AdmissionRequirement::factory()->create(['unit_id' => null, 'document_type_id' => $f->types['KK']->id, 'name' => 'KK semua']);
    $sd = AdmissionRequirement::factory()->create(['unit_id' => $f->sd->id, 'document_type_id' => $f->types['RAPOR']->id, 'name' => 'Rapor SD']);
    $this->actingAs(psbUser('admin-psb', ['SLTP']));

    Livewire::test(ManageAdmissionRequirements::class)
        ->mountAction(TestAction::make('delete')->table($all))
        ->assertActionNotMounted(TestAction::make('delete')->table($all))
        ->mountAction(TestAction::make('edit')->table($all))
        ->assertActionNotMounted(TestAction::make('edit')->table($all));

    foreach ([[$all, 'delete'], [$all, 'edit'], [$sd, 'delete'], [$sd, 'edit']] as [$record, $action]) {
        try {
            Livewire::test(ManageAdmissionRequirements::class)->callAction(TestAction::make($action)->table($record), $action === 'edit' ? psbRequirementData($f, ['unit_id' => $f->sltp->id, 'name' => 'Diubah']) : []);
        } catch (Throwable) {
        }
    }

    expect(AdmissionRequirement::query()->count())->toBe(2)
        ->and($all->fresh()->name)->toBe('KK semua')
        ->and($sd->fresh()->name)->toBe('Rapor SD');
});

it('AC-25: lets an unscoped admin-psb see every requirement', function () {
    $f = psbFixture();
    $all = AdmissionRequirement::factory()->create(['unit_id' => null, 'document_type_id' => $f->types['KK']->id]);
    $sltp = AdmissionRequirement::factory()->create(['unit_id' => $f->sltp->id, 'document_type_id' => $f->types['AKTA']->id]);
    $sd = AdmissionRequirement::factory()->create(['unit_id' => $f->sd->id, 'document_type_id' => $f->types['RAPOR']->id]);
    $this->actingAs(psbUser('admin-psb'));

    Livewire::test(ManageAdmissionRequirements::class)
        ->assertCanSeeTableRecords([$all, $sltp, $sd])
        ->assertActionVisible(TestAction::make('edit')->table($all))
        ->assertActionVisible(TestAction::make('delete')->table($all));
});

it('AC-31: translates every psb text key', function (string $key) {
    expect(__($key))->not->toBe($key)->and(trim(__($key)))->not->toBe('');
})->with([
    ...collect([
        'academic_year_not_found', 'name_required', 'wave_name_taken', 'date_invalid', 'closes_before_opens', 'quota_invalid',
        'wave_dates_overlap', 'code_format', 'track_code_taken', 'scholarship_not_found', 'scholarship_inactive', 'unit_not_found',
        'all_units_requires_unscoped', 'document_type_not_found', 'document_type_not_for_student', 'document_type_inactive',
        'sort_order_invalid', 'requirement_exists',
    ])->mapWithKeys(fn (string $reason): array => [$reason => ["pondok.psb.errors.{$reason}"]])->all(),
    'keuangan wave_not_found' => ['pondok.keuangan.errors.wave_not_found'],
    'keuangan wave_rate_for_non_psb_fee' => ['pondok.keuangan.errors.wave_rate_for_non_psb_fee'],
    'navigation' => ['pondok.navigation.psb'],
    'wave empty' => ['pondok.admission_wave.empty'],
    'track empty' => ['pondok.admission_track.empty'],
    'requirement empty' => ['pondok.admission_requirement.empty'],
    'all waves' => ['pondok.fee_rate.all_waves'],
]);

it('AC-31: words name_required so it fits both a missing and a too long name', function () {
    $message = Str::lower(__('pondok.psb.errors.name_required'));

    expect($message)->toContain('wajib')->and($message)->toMatch('/maksimal|panjang|karakter/');
});

it('AC-40: translates every applicant text key', function (string $key) {
    expect(__($key))->toBeString()->not->toBe($key)->not->toBeEmpty();
})->with([
    'pondok.applicant.label',
    'pondok.applicant.plural',
    'pondok.applicant.registration_no',
    'pondok.applicant.consented_at',
    'pondok.applicant.name',
    'pondok.applicant.nisn',
    'pondok.applicant.gender',
    'pondok.applicant.birth_place',
    'pondok.applicant.birth_date',
    'pondok.applicant.previous_school',
    'pondok.applicant.guardian_name',
    'pondok.applicant.guardian_phone',
    'pondok.applicant.email',
    'pondok.applicant.unit',
    'pondok.applicant.wave',
    'pondok.applicant.track',
    'pondok.applicant.status',
    'pondok.applicant.registered_at',
    'pondok.applicant.section_registration',
    'pondok.applicant.section_student',
    'pondok.applicant.section_guardian',
    'pondok.applicant.section_invoices',
    'pondok.applicant.section_completeness',
    'pondok.applicant.no_invoices',
    'pondok.applicant.completeness_ok',
    'pondok.applicant.empty',
    'pondok.applicant.invoice_item_description',
    'pondok.applicant.cancel_reason_deleted',
    'pondok.applicant.generate_invoices',
    'pondok.applicant.generate_invoices_description',
    'pondok.applicant.invoices_generated',
    'pondok.applicant.invoices_none_generated',
    'pondok.applicant.reset_token',
    'pondok.applicant.reset_token_description',
    'pondok.applicant.token_reset_notice',
    'pondok.applicant.delete',
    'pondok.applicant.delete_description',
    'pondok.applicant.deleted',
    'pondok.applicant.statuses.draft',
    'pondok.applicant.statuses.diajukan',
    'pondok.applicant.statuses.diverifikasi',
    'pondok.applicant.statuses.diterima',
    'pondok.applicant.statuses.cadangan',
    'pondok.applicant.statuses.ditolak',
    'pondok.applicant_document.label',
    'pondok.applicant_document.plural',
    'pondok.applicant_document.requirement',
    'pondok.applicant_document.original_name',
    'pondok.applicant_document.size_kb',
    'pondok.applicant_document.uploaded_at',
    'pondok.applicant_document.status',
    'pondok.applicant_document.note',
    'pondok.applicant_document.download',
    'pondok.applicant_document.mark_valid',
    'pondok.applicant_document.reject',
    'pondok.applicant_document.reject_note',
    'pondok.applicant_document.empty',
    'pondok.applicant_document.not_uploaded',
    'pondok.applicant_document.statuses.menunggu',
    'pondok.applicant_document.statuses.valid',
    'pondok.applicant_document.statuses.ditolak',
    'pondok.psb_public.title',
    'pondok.psb_public.opens_badge',
    'pondok.psb_public.closed_title',
    'pondok.psb_public.closed_body',
    'pondok.psb_public.next',
    'pondok.psb_public.back',
    'pondok.psb_public.submit',
    'pondok.psb_public.saving',
    'pondok.psb_public.consent',
    'pondok.psb_public.prepare_documents',
    'pondok.psb_public.required',
    'pondok.psb_public.optional',
    'pondok.psb_public.success_title',
    'pondok.psb_public.token_warning',
    'pondok.psb_public.copy_token',
    'pondok.psb_public.copied',
    'pondok.psb_public.fee_total',
    'pondok.psb_public.no_fee',
    'pondok.psb_public.payment_hint',
    'pondok.psb_public.check_status',
    'pondok.psb_public.status_title',
    'pondok.psb_public.login_submit',
    'pondok.psb_public.logout',
    'pondok.psb_public.invoices_title',
    'pondok.psb_public.documents_title',
    'pondok.psb_public.uploads_locked_unpaid',
    'pondok.psb_public.uploads_closed',
    'pondok.psb_public.upload',
    'pondok.psb_public.upload_hint',
    'pondok.psb_public.uploaded',
    'pondok.psb_public.steps.choice',
    'pondok.psb_public.steps.student',
    'pondok.psb_public.steps.guardian',
    'pondok.psb_public.steps.review',
    'pondok.psb_public.status_help.draft',
    'pondok.psb_public.status_help.diajukan',
    'pondok.psb_public.status_help.diverifikasi',
    'pondok.psb_public.status_help.diterima',
    'pondok.psb_public.status_help.cadangan',
    'pondok.psb_public.status_help.ditolak',
    'pondok.psb.errors.track_not_found',
    'pondok.psb.errors.track_inactive',
    'pondok.psb.errors.wave_not_found',
    'pondok.psb.errors.wave_not_accepting',
    'pondok.psb.errors.wave_full',
    'pondok.psb.errors.applicant_name_invalid',
    'pondok.psb.errors.gender_invalid',
    'pondok.psb.errors.birth_place_invalid',
    'pondok.psb.errors.birth_date_invalid',
    'pondok.psb.errors.nisn_invalid',
    'pondok.psb.errors.previous_school_invalid',
    'pondok.psb.errors.guardian_name_invalid',
    'pondok.psb.errors.phone_invalid',
    'pondok.psb.errors.email_invalid',
    'pondok.psb.errors.consent_required',
    'pondok.psb.errors.duplicate_application',
    'pondok.psb.errors.spam_rejected',
    'pondok.psb.errors.too_many_registrations',
    'pondok.psb.errors.too_many_attempts',
    'pondok.psb.errors.credentials_invalid',
    'pondok.psb.errors.applicant_closed',
    'pondok.psb.errors.uploads_closed',
    'pondok.psb.errors.registration_fee_unpaid',
    'pondok.psb.errors.requirement_not_applicable',
    'pondok.psb.errors.document_already_valid',
    'pondok.psb.errors.file_type_invalid',
    'pondok.psb.errors.file_too_large',
    'pondok.psb.errors.document_status_invalid',
    'pondok.psb.errors.note_required',
    'pondok.psb.errors.applicant_has_payments',
    'pondok.psb.errors.applicant_converted',
    'pondok.psb.errors.requirement_in_use',
    'pondok.psb.errors.number_conflict',
    'pondok.psb.errors.unit_not_found',
    'pondok.keuangan.errors.billing_group_frequency_conflict',
    'pondok.keuangan.errors.applicant_not_found',
    'pondok.invoice.applicant_owner',
    'pondok.payment.applicant_owner',
    'pondok.receipt.registration_no',
]);
