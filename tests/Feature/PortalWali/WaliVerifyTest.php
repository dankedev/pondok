<?php

use App\Actions\PortalWali\CreateGuardianPortalAccount;
use App\Filament\Resources\Guardians\Pages\EditGuardian;
use App\Livewire\PortalWali\ForgotPassword as PortalForgotPassword;
use App\Models\Applicant;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = waliBoot();
});

it('VERIFY: a student or invoice page of another wali is 404 even with a crafted id and never leaks its amounts', function () {
    $f = $this->f;
    $theirs = waliInvoice($f, $f->o1, ['total' => 123000]);
    $this->actingAs($f->wali);

    $this->get("/wali/santri/{$f->o1->id}")->assertNotFound()->assertDontSee('123.000');
    $this->get("/wali/tagihan/{$theirs->id}")->assertNotFound()->assertDontSee('123.000');
    $this->get("/wali/santri/{$f->s1->id}")->assertOk()->assertDontSee('123.000');
});

it('VERIFY: an invoice page is 404 after the invoice is cancelled or the student link is detached or archived', function (string $case) {
    $f = $this->f;
    $invoice = waliInvoice($f, $f->s1);
    $this->actingAs($f->wali)->get("/wali/tagihan/{$invoice->id}")->assertOk();

    match ($case) {
        'cancelled' => $invoice->forceFill(['status' => Invoice::STATUS_CANCELLED])->save(),
        'detached' => $f->s1->guardians()->detach($f->guardian->id),
        'archived' => $f->s1->delete(),
    };

    $this->get("/wali/tagihan/{$invoice->id}")->assertNotFound();
})->with(['cancelled', 'detached', 'archived']);

it('VERIFY: a module switched off mid-session closes the pages that depend on it', function (string $module, string $page) {
    $f = $this->f;
    $invoice = waliInvoice($f, $f->s1);
    $this->actingAs($f->wali);
    $path = $page === 'invoice' ? "/wali/tagihan/{$invoice->id}" : "/wali/santri/{$f->s1->id}";
    $this->get($path)->assertOk();

    Setting::set("modules.{$module}.enabled", false);

    $this->get($path)->assertNotFound();
})->with([
    'portal-wali on invoice' => ['portal-wali', 'invoice'],
    'keuangan on invoice' => ['keuangan', 'invoice'],
    'portal-wali on student' => ['portal-wali', 'student'],
]);

it('VERIFY: a guardian-owned document with the same id as a student never reaches the sibling wali', function () {
    $f = $this->f;
    $type = dokumenType(['name' => 'KTP Wali Rahasia', 'is_confidential' => false]);
    $guardian = Guardian::factory()->create(['id' => 900]);
    $f->s1->guardians()->attach($guardian->id, ['relation' => 'ibu', 'is_billing_contact' => false]);
    $document = dokumenCreate($guardian, $type);

    expect(waliText($f->wali, "/wali/santri/{$f->s1->id}"))->not->toContain('KTP Wali Rahasia');
    $this->get("/wali/dokumen/{$document->id}/unduh")->assertNotFound();
});

it('VERIFY: an unverified wali is sent to the email prompt instead of downloading a document', function () {
    $f = $this->f;
    $document = dokumenCreate($f->s1, dokumenType(['name' => 'Rapor', 'is_confidential' => false]));
    $f->wali->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($f->wali)->get("/wali/dokumen/{$document->id}/unduh")->assertRedirect(route('portal-wali.verification.notice'));
});

it('VERIFY: siblings with two walis are visible to both, each only for own students, invoice of applicant without student hidden', function () {
    $f = $this->f;
    $f->s1->guardians()->attach($f->otherGuardian->id, ['relation' => 'ibu', 'is_billing_contact' => false]);
    $applicantInvoice = Invoice::factory()->create(['student_id' => null, 'applicant_id' => Applicant::factory(), 'academic_year_id' => $f->year->id, 'total' => 777000, 'status' => Invoice::STATUS_UNPAID]);
    $own = waliInvoice($f, $f->s1);

    $other = waliText($f->other);
    $mine = waliText($f->wali, "/wali/santri/{$f->s1->id}");

    expect($other)->toContain('Ahmad Satu')->toContain('Citra Lain')->not->toContain('Budi Dua')
        ->and($mine)->toContain($own->invoice_no)->not->toContain($applicantInvoice->invoice_no);
    $this->actingAs($f->wali)->get("/wali/tagihan/{$applicantInvoice->id}")->assertNotFound();
});

it('VERIFY: a wali deactivated mid-session is rejected on the next request', function () {
    $this->actingAs($this->f->wali)->get('/wali')->assertOk();
    $this->f->wali->forceFill(['is_active' => false])->save();

    $this->get('/wali')->assertForbidden();
});

it('VERIFY: pages never leak sensitive student fields', function () {
    $f = $this->f;
    $f->s1->forceFill(['nisn' => '5544332211', 'address' => 'Jl. Pribadi 17', 'birth_place' => 'Kota Lahir Rahasia'])->save();

    foreach (['/wali', "/wali/santri/{$f->s1->id}"] as $path) {
        expect(waliText($f->wali, $path))->not->toContain('5544332211')->not->toContain('Jl. Pribadi 17')->not->toContain('Kota Lahir Rahasia');
    }
});

it('VERIFY: a staff user without the wali role cannot request a reset link through the wali portal', function () {
    Notification::fake();
    $staff = keuanganUser('bendahara');

    Livewire::test(PortalForgotPassword::class)->set('email', $staff->email)->call('send');

    Notification::assertNothingSentTo($staff);
});

it('VERIFY: a user with only the kesantrian role cannot run the create-account action', function () {
    $guardian = Guardian::factory()->create();
    Filament::setCurrentPanel('admin');
    $this->actingAs(keuanganUser('kesantrian'));

    Livewire::test(EditGuardian::class, ['record' => $guardian->id])->assertForbidden();
    expect($guardian->fresh()->user_id)->toBeNull();
});

it('VERIFY: the created account holds only the wali role and no extra permissions', function () {
    Notification::fake();
    $guardian = Guardian::factory()->create();

    $user = app(CreateGuardianPortalAccount::class)->handle($guardian, ' Baru@Contoh.test ', keuanganUser('admin-sekolah'));

    expect($user->email)->toBe('baru@contoh.test')
        ->and($user->getRoleNames()->all())->toBe(['wali'])
        ->and($user->getAllPermissions())->toBeEmpty()
        ->and(User::query()->where('id', '!=', $user->id)->where('email', 'baru@contoh.test')->exists())->toBeFalse();
});
