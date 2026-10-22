<?php

use App\Actions\PortalWali\CreateGuardianPortalAccount;
use App\Filament\Resources\Guardians\Pages\EditGuardian;
use App\Filament\Wali\Pages\ShowInvoice;
use App\Filament\Wali\Pages\ShowStudent;
use App\Models\Applicant;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = waliBoot();
    Filament::setCurrentPanel('wali');
});

it('VERIFY: tampering studentId in Livewire to another wali student never yields that student', function () {
    $f = $this->f;
    waliInvoice($f, $f->o1, ['total' => 123000]);
    $this->actingAs($f->wali);

    $component = Livewire::test(ShowStudent::class, ['student' => $f->s1->id]);

    expect(fn () => $component->set('studentId', $f->o1->id))->toThrow(CannotUpdateLockedPropertyException::class);
    $component->call('$refresh')->assertOk()->assertDontSee('123.000');
});

it('VERIFY: tampering invoiceId in Livewire to another wali invoice is rejected', function () {
    $f = $this->f;
    $mine = waliInvoice($f, $f->s1);
    $theirs = waliInvoice($f, $f->o1);
    $this->actingAs($f->wali);

    $component = Livewire::test(ShowInvoice::class, ['invoice' => $mine->id]);

    expect(fn () => $component->set('invoiceId', $theirs->id))->toThrow(CannotUpdateLockedPropertyException::class);
    $component->call('$refresh')->assertOk()->assertSee($mine->invoice_no)->assertDontSee($theirs->invoice_no);
});

it('VERIFY: an open invoice page fails after the invoice is cancelled or the student link is detached', function (string $case) {
    $f = $this->f;
    $invoice = waliInvoice($f, $f->s1);
    $this->actingAs($f->wali);
    $component = Livewire::test(ShowInvoice::class, ['invoice' => $invoice->id]);

    match ($case) {
        'cancelled' => $invoice->forceFill(['status' => Invoice::STATUS_CANCELLED])->save(),
        'detached' => $f->s1->guardians()->detach($f->guardian->id),
        'archived' => $f->s1->delete(),
    };

    $component->call('$refresh')->assertNotFound();
})->with(['cancelled', 'detached', 'archived']);

it('VERIFY: an open Livewire page stops serving when the module is switched off mid-session', function (string $module, string $page) {
    $f = $this->f;
    $invoice = waliInvoice($f, $f->s1);
    $this->actingAs($f->wali);
    $component = $page === 'invoice'
        ? Livewire::test(ShowInvoice::class, ['invoice' => $invoice->id])
        : Livewire::test(ShowStudent::class, ['student' => $f->s1->id]);

    Setting::set("modules.{$module}.enabled", false);

    $html = rescue(fn () => $component->call('$refresh')->html(), fn () => '', report: false);

    expect($html)->not->toContain($invoice->invoice_no);
})->with([
    'portal-wali on invoice' => ['portal-wali', 'invoice'],
    'keuangan on invoice' => ['keuangan', 'invoice'],
    'keuangan on student' => ['keuangan', 'student'],
    'portal-wali on student' => ['portal-wali', 'student'],
]);

it('VERIFY: a guardian-owned document with the same id as a student never reaches the sibling wali', function () {
    $f = $this->f;
    $type = dokumenType(['name' => 'KTP Wali Rahasia', 'is_confidential' => false]);
    $guardian = Guardian::factory()->create(['id' => 900]);
    $f->s1->guardians()->attach($guardian->id, ['relation' => 'ibu', 'is_billing_contact' => false]);
    $document = dokumenCreate($guardian, $type);

    expect(waliText($f->wali, "/wali/santri/{$f->s1->id}"))->not->toContain('KTP Wali Rahasia');
    $this->get("/wali/documents/{$document->id}/download")->assertNotFound();
});

it('VERIFY: an unverified wali is sent to the email prompt instead of downloading a document', function () {
    $f = $this->f;
    $document = dokumenCreate($f->s1, dokumenType(['name' => 'Rapor', 'is_confidential' => false]));
    $f->wali->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($f->wali)->get("/wali/documents/{$document->id}/download")->assertRedirect();
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

it('VERIFY: wali without MFA enabled is never blocked by an MFA setup redirect', function () {
    $this->actingAs($this->f->wali)->get('/wali')->assertOk();
    $this->get("/wali/santri/{$this->f->s1->id}")->assertOk();
});

it('VERIFY: pages never leak sensitive student fields', function () {
    $f = $this->f;
    $f->s1->forceFill(['nisn' => '5544332211', 'address' => 'Jl. Pribadi 17', 'birth_place' => 'Kota Lahir Rahasia'])->save();

    foreach (['/wali', "/wali/santri/{$f->s1->id}"] as $path) {
        expect(waliText($f->wali, $path))->not->toContain('5544332211')->not->toContain('Jl. Pribadi 17')->not->toContain('Kota Lahir Rahasia');
    }
});

it('VERIFY: a staff user without the wali role cannot request a reset link through the wali panel', function () {
    Notification::fake();
    $staff = keuanganUser('bendahara');

    Livewire::test(RequestPasswordReset::class)->fillForm(['email' => $staff->email])->call('request');

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
