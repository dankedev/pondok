<?php

use App\Livewire\Psb\RegistrationForm;
use App\Models\AcademicYear;
use App\Models\AdmissionWave;
use App\Models\Applicant;
use App\Models\Setting;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->f = pendaftaranFixture();
});

function pendaftaranApplicantCountAll(): int
{
    return Applicant::withoutGlobalScopes()->withTrashed()->count();
}

/**
 * Wizard baru, diisi sampai langkah 4, lalu dikirim setelah `$wait` detik (nol = langsung).
 *
 * @param  array<string, mixed>  $overrides
 */
function pendaftaranAttempt(array $overrides = [], int $wait = 5, array $state = []): Testable
{
    $component = pendaftaranFillWizard(Livewire::test(RegistrationForm::class), ['consent' => true, ...$overrides]);

    foreach ($state as $property => $value) {
        $component->set($property, $value);
    }

    if ($wait > 0) {
        test()->travel($wait)->seconds();
    }

    return $component->call('submit');
}

it('AC-21: renders the registration page for guests', function () {
    $this->get('/psb')->assertOk()
        ->assertSee(__('pondok.psb_public.title'))
        ->assertSee('Gelombang 1');
});

it('AC-21: returns 403 on every public page when the psb module is off', function () {
    Setting::set('modules.psb.enabled', false);
    $applicant = pendaftaranApplicant();
    $document = pendaftaranDocument($applicant, $this->f->kk);

    $this->get('/psb')->assertForbidden();
    $this->get('/psb/status')->assertForbidden();
    $this->get("/psb/berkas/{$document->id}")->assertForbidden();
});

it('AC-21: shows a closed card without the form when no wave is open', function () {
    $this->f->wave->update(['is_open' => false]);

    $this->get('/psb')->assertOk()
        ->assertSee(__('pondok.psb_public.closed_title'))
        ->assertDontSee(__('pondok.psb_public.next'))
        ->assertSee(route('psb.status'));
});

it('AC-21: hides a full wave and shows the closed card', function () {
    $this->f->wave->update(['quota' => 1]);
    pendaftaranApplicant();

    $this->get('/psb')->assertOk()->assertSee(__('pondok.psb_public.closed_title'));
});

it('AC-22: selects the only open wave automatically', function () {
    Livewire::test(RegistrationForm::class)->assertSet('admission_wave_id', $this->f->wave->id);
});

it('AC-22: lets the guardian choose when several waves are open', function () {
    $y2028 = AcademicYear::factory()->create(['name' => '2028/2029', 'starts_on' => '2028-07-01', 'ends_on' => '2029-06-30']);
    $inden = psbWave($y2028, 'Gelombang Inden', '2026-10-01', '2027-01-31');

    $component = Livewire::test(RegistrationForm::class)
        ->assertSee('Gelombang 1 — 2027/2028')
        ->assertSee('Gelombang Inden — 2028/2029');

    expect(blank($component->get('admission_wave_id')))->toBeTrue()
        ->and(AdmissionWave::query()->count())->toBe(2);

    $component->set('admission_wave_id', $inden->id)->assertSet('admission_wave_id', $inden->id);
});

it('AC-22: walks through the wizard step by step', function () {
    $data = pendaftaranData();
    $component = Livewire::test(RegistrationForm::class)->assertSet('step', 1);

    $component->call('next')->assertHasErrors(['admission_track_id'])->assertSet('step', 1);

    $component->set('unit_id', $data['unit_id'])->set('admission_track_id', $data['admission_track_id'])
        ->call('next')->assertHasNoErrors()->assertSet('step', 2);

    $component->set('name', 'Ahmad Fauzan')->call('back')->assertSet('step', 1)
        ->assertSet('unit_id', $data['unit_id'])->assertSet('admission_track_id', $data['admission_track_id']);

    $component->call('next')->assertSet('step', 2)->assertSet('name', 'Ahmad Fauzan');
});

it('AC-22: stays on step 2 and 3 while their fields are invalid', function () {
    $component = Livewire::test(RegistrationForm::class);
    $data = pendaftaranData();
    $component->set('unit_id', $data['unit_id'])->set('admission_track_id', $data['admission_track_id'])->call('next')->assertSet('step', 2);

    $component->call('next')->assertHasErrors(['name'])->assertSet('step', 2);

    foreach (['name', 'gender', 'birth_place', 'birth_date', 'nisn', 'previous_school'] as $field) {
        $component->set($field, $data[$field]);
    }
    $component->call('next')->assertSet('step', 3);

    $component->call('next')->assertHasErrors(['guardian_name'])->assertSet('step', 3);
});

it('AC-22: shows the track terms after choosing a track', function () {
    $this->f->reguler->update(['terms' => 'Syarat khusus jalur reguler']);

    Livewire::test(RegistrationForm::class)->assertDontSee('Syarat khusus jalur reguler')
        ->set('admission_track_id', $this->f->reguler->id)->assertSee('Syarat khusus jalur reguler');
});

it('AC-22: lists the documents to prepare for the chosen unit on the last step', function () {
    $component = pendaftaranFillWizard(Livewire::test(RegistrationForm::class), ['consent' => true]);
    $text = tagihanPlainText($component);

    expect($text)->toContain('Kartu Keluarga')->toContain('Akta kelahiran')->toContain('Rapor')->not->toContain('Ijazah');
});

it('AC-22: requires consent on the last step', function () {
    $component = pendaftaranFillWizard(Livewire::test(RegistrationForm::class), ['consent' => false]);
    $this->travel(5)->seconds();

    $component->call('submit')->assertHasErrors(['consent']);

    expect(pendaftaranApplicantCountAll())->toBe(0);
});

it('AC-22: submits a valid application and shows the number and token once', function () {
    $component = pendaftaranAttempt();
    $text = tagihanPlainText($component);
    $applicant = Applicant::withoutGlobalScopes()->sole();

    expect($applicant)->registration_no->toBe('PSB-2027-0001')
        ->and($applicant->consented_at)->not->toBeNull()
        ->and($text)->toContain('PSB-2027-0001')->toContain('Rp 250.000')
        ->and(preg_match('/[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}/', $text, $match))->toBe(1);

    $component->assertSeeHtml(route('psb.status'));

    $fresh = Livewire::test(RegistrationForm::class)->assertSet('step', 1)->assertDontSee($match[0]);

    expect(tagihanPlainText($fresh))->not->toContain($match[0]);
});

it('AC-22: tells the guardian when there is no registration fee', function () {
    $this->f->formulir->rates()->delete();

    $component = pendaftaranAttempt();

    expect(tagihanPlainText($component))->toContain(__('pondok.psb_public.no_fee'));
});

it('AC-22: shows a domain failure as a message instead of saving', function () {
    pendaftaranAttempt();

    $component = pendaftaranAttempt();

    expect(pendaftaranApplicantCountAll())->toBe(1)
        ->and(pendaftaranSeeMessage($component, 'duplicate_application'))->toBeTrue();
});

it('AC-23: rejects a filled honeypot without saving anything', function () {
    $component = pendaftaranAttempt(state: ['website' => 'http://spam.example']);

    expect(pendaftaranApplicantCountAll())->toBe(0)
        ->and(pendaftaranSeeMessage($component, 'spam_rejected'))->toBeTrue();
});

it('AC-23: rejects a submission that comes too quickly after the page loaded', function () {
    $component = pendaftaranAttempt(wait: 0);

    expect(pendaftaranApplicantCountAll())->toBe(0)
        ->and(pendaftaranSeeMessage($component, 'spam_rejected'))->toBeTrue();
});

it('AC-23: accepts a submission after the minimum fill time', function () {
    pendaftaranAttempt(wait: 4);

    expect(pendaftaranApplicantCountAll())->toBe(1);
});

it('AC-23: rate limits submissions per ip at ten attempts per hour', function () {
    $person = fn (string $name, string $phone) => ['name' => $name, 'guardian_phone' => $phone];

    pendaftaranAttempt(state: ['website' => 'x']);
    pendaftaranAttempt(state: ['website' => 'x']);
    pendaftaranAttempt(wait: 0);
    pendaftaranAttempt(wait: 0);
    pendaftaranAttempt(['consent' => false]);
    pendaftaranAttempt(['consent' => false]);
    pendaftaranAttempt($person('Siti Aminah', '081200000001'));
    pendaftaranAttempt($person('Siti Aminah', '081200000001'));
    pendaftaranAttempt($person('Umar Faruq', '081200000002'));

    expect(pendaftaranApplicantCountAll())->toBe(2);

    foreach (range(1, 4) as $ignored) {
        pendaftaranFillWizard(Livewire::test(RegistrationForm::class), ['consent' => true]);
    }

    $tenth = pendaftaranAttempt(state: ['website' => 'x']);

    expect(pendaftaranSeeMessage($tenth, 'spam_rejected'))->toBeTrue()
        ->and(pendaftaranSeeMessage($tenth, 'too_many_registrations'))->toBeFalse();

    $eleventh = pendaftaranAttempt($person('Ali Hasan', '081200000003'));

    expect(pendaftaranSeeMessage($eleventh, 'too_many_registrations'))->toBeTrue()
        ->and(pendaftaranApplicantCountAll())->toBe(2);

    $this->travel(61)->minutes();
    pendaftaranAttempt($person('Ali Hasan', '081200000003'));

    expect(pendaftaranApplicantCountAll())->toBe(3);
});

it('AC-23: does not call the action once the limit is reached', function () {
    RateLimiter::increment('psb-register:127.0.0.1', 3600, 10);

    $component = pendaftaranAttempt();

    expect(pendaftaranSeeMessage($component, 'too_many_registrations'))->toBeTrue()
        ->and(pendaftaranApplicantCountAll())->toBe(0);
});

it('AC-23: ignores the forwarded for header with the default proxy configuration', function () {
    Route::get('/_psb-ip', fn () => response(request()->ip()));

    expect(config('pondok.trusted_proxies'))->toBeEmpty();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->withHeader('X-Forwarded-For', '198.51.100.9')
        ->get('/_psb-ip')->assertOk()->assertSee('203.0.113.5')->assertDontSee('198.51.100.9');
});
