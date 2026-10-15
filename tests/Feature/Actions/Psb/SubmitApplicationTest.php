<?php

use App\Actions\Psb\GenerateApplicantNumber;
use App\Actions\Psb\ResetApplicantToken;
use App\Actions\Psb\SubmitApplication;
use App\Models\AcademicYear;
use App\Models\AdmissionTrack;
use App\Models\Applicant;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->f = pendaftaranFixture();
});

function pendaftaranApplicantCount(): int
{
    return Applicant::withoutGlobalScopes()->withTrashed()->count();
}

it('AC-10: submits an application', function () {
    $result = pendaftaranSubmit(['consented_at' => '2020-01-01 00:00:00']);
    $applicant = Applicant::withoutGlobalScopes()->sole();

    expect($result['applicant']->is($applicant))->toBeTrue()
        ->and($applicant)
        ->registration_no->toBe('PSB-2027-0001')
        ->status->toBe('diajukan')
        ->name->toBe('Ahmad Fauzan')
        ->gender->toBe('L')
        ->birth_place->toBe('Bandung')
        ->nisn->toBe('0123456789')
        ->previous_school->toBe('SDIT Al-Fatih')
        ->guardian_name->toBe('Budi Santoso')
        ->guardian_phone->toBe('081234567890')
        ->email->toBe('budi@example.com')
        ->unit_id->toBe($this->f->sltp->id)
        ->admission_track_id->toBe($this->f->reguler->id)
        ->admission_wave_id->toBe($this->f->wave->id)
        ->and($applicant->birth_date->toDateString())->toBe('2014-05-12')
        ->and($applicant->consented_at->timestamp)->toBe(now()->timestamp)
        ->and($applicant->consented_at->timestamp)->not->toBe(strtotime('2020-01-01'));
});

it('AC-10: issues a one time token that is stored only as a hash', function () {
    $result = pendaftaranSubmit();
    $applicant = $result['applicant']->fresh();

    expect($result['token'])->toMatch(pendaftaranTokenPattern())
        ->and(Hash::check(ResetApplicantToken::normalizeToken($result['token']), $applicant->access_token_hash))->toBeTrue()
        ->and($applicant->access_token_hash)->not->toBe($result['token'])
        ->and($applicant->access_token_hash)->not->toContain(str_replace('-', '', $result['token']))
        ->and($applicant->toArray())->not->toHaveKey('access_token_hash')
        ->and($applicant->toJson())->not->toContain('access_token_hash');
});

it('AC-10: logs the creation without a causer and without personal data', function () {
    $applicant = pendaftaranSubmit()['applicant'];

    $activity = Activity::query()->where('subject_type', $applicant->getMorphClass())->where('subject_id', $applicant->id)->sole();

    expect($activity)->log_name->toBe('psb')->event->toBe('created')->causer_id->toBeNull()
        ->and($activity->properties->all())->toBe(['attributes' => ['status' => 'diajukan']]);
});

it('AC-10: issues a different token to every applicant', function () {
    $first = pendaftaranSubmit();
    $second = pendaftaranSubmit(['name' => 'Siti Aminah', 'guardian_phone' => '08111111111']);

    expect($first['token'])->not->toBe($second['token']);
});

it('AC-11a: retries when the registration number is already taken', function () {
    pendaftaranApplicant(['registration_no' => 'PSB-2027-0001']);
    $fake = pendaftaranFakeApplicantNumber(forced: ['PSB-2027-0001']);

    $applicant = pendaftaranSubmit(['name' => 'Siti Aminah'])['applicant'];

    expect($applicant->registration_no)->toBe('PSB-2027-0002')
        ->and($fake->calls)->toBe(2)
        ->and(pendaftaranApplicantCount())->toBe(2);
});

it('AC-11a: gives up after three number conflicts without leaving a row', function () {
    pendaftaranApplicant(['registration_no' => 'PSB-2027-0001']);
    pendaftaranFakeApplicantNumber(always: 'PSB-2027-0001');
    Log::spy();

    expect(fn () => pendaftaranSubmit(['name' => 'Siti Aminah']))->toThrow(RuntimeException::class)
        ->and(pendaftaranApplicantCount())->toBe(1);

    Log::shouldHaveReceived('error');
});

it('AC-11a: retries when the registration invoice number is already taken', function () {
    Invoice::factory()->create(['invoice_no' => 'INV-202610-00001']);
    pendaftaranFakeInvoiceNumber(forced: ['INV-202610-00001']);

    $applicant = pendaftaranSubmit()['applicant'];
    $invoice = Invoice::withoutUnitScope()->where('applicant_id', $applicant->id)->sole();

    expect($invoice->invoice_no)->toBe('INV-202610-00002')
        ->and(InvoiceItem::query()->where('invoice_id', $invoice->id)->count())->toBe(1);
});

it('AC-11a: keeps the applicant when the invoice number always conflicts', function () {
    Invoice::factory()->create(['invoice_no' => 'INV-202610-00001']);
    pendaftaranFakeInvoiceNumber(always: 'INV-202610-00001');
    Log::spy();

    $applicant = pendaftaranSubmit()['applicant'];

    expect(Applicant::withoutGlobalScopes()->whereKey($applicant->id)->exists())->toBeTrue()
        ->and(Invoice::withoutUnitScope()->where('applicant_id', $applicant->id)->count())->toBe(0);

    Log::shouldHaveReceived('error');
});

it('AC-11: numbers applications per year of the target academic year', function () {
    $first = pendaftaranSubmit()['applicant'];
    $second = pendaftaranSubmit(['name' => 'Siti Aminah', 'guardian_phone' => '08111111111'])['applicant'];
    $y2028 = AcademicYear::factory()->create(['name' => '2028/2029', 'starts_on' => '2028-07-01', 'ends_on' => '2029-06-30']);
    $next = psbWave($y2028, 'Gelombang Inden', '2026-10-01', '2027-01-31');
    $inden = pendaftaranSubmit(['admission_wave_id' => $next->id])['applicant'];

    expect($first->registration_no)->toBe('PSB-2027-0001')
        ->and($second->registration_no)->toBe('PSB-2027-0002')
        ->and($inden->registration_no)->toBe('PSB-2028-0001');
});

it('AC-11: never reuses the number of a deleted applicant', function () {
    foreach (['0001', '0002', '0003'] as $sequence) {
        pendaftaranApplicant(['registration_no' => "PSB-2027-{$sequence}"]);
    }
    Applicant::withoutGlobalScopes()->where('registration_no', 'PSB-2027-0003')->first()->delete();

    expect(pendaftaranSubmit()['applicant']->registration_no)->toBe('PSB-2027-0004');
});

it('AC-11: continues numbering across units for a scoped admin and still saves', function () {
    $this->seed(RolePermissionSeeder::class);
    pendaftaranApplicant(['registration_no' => 'PSB-2027-0001', 'unit_id' => $this->f->slta->id]);
    pendaftaranApplicant(['registration_no' => 'PSB-2027-0002', 'unit_id' => $this->f->sltp->id]);
    $this->actingAs(psbUser('admin-psb', ['SD']));

    $applicant = pendaftaranSubmit()['applicant'];

    expect($applicant->registration_no)->toBe('PSB-2027-0003')
        ->and(Applicant::withoutGlobalScopes()->whereKey($applicant->id)->exists())->toBeTrue();
});

it('AC-11: refuses to number past 9999', function () {
    pendaftaranApplicant(['registration_no' => 'PSB-2027-9999']);

    expect(fn () => app(GenerateApplicantNumber::class)->handle($this->f->wave))->toThrow(LogicException::class);
});

it('AC-12: rejects invalid fields without a new row', function (Closure $case, string $reason, string $field) {
    $overrides = $case($this->f);
    $data = pendaftaranData($overrides['set'] ?? []);

    foreach ($overrides['unset'] ?? [] as $key) {
        unset($data[$key]);
    }

    pendaftaranAssertRejected(fn () => app(SubmitApplication::class)->handle($data), $reason, $field);

    expect(pendaftaranApplicantCount())->toBe(0);
})->with([
    'unit 999' => [fn () => ['set' => ['unit_id' => 999]], 'unit_not_found', 'unit_id'],
    'track 999' => [fn () => ['set' => ['admission_track_id' => 999]], 'track_not_found', 'admission_track_id'],
    'inactive track' => [function () {
        $track = AdmissionTrack::factory()->inactive()->create();

        return ['set' => ['admission_track_id' => $track->id]];
    }, 'track_inactive', 'admission_track_id'],
    'wave 999' => [fn () => ['set' => ['admission_wave_id' => 999]], 'wave_not_found', 'admission_wave_id'],
    'blank name' => [fn () => ['set' => ['name' => '  ']], 'applicant_name_invalid', 'name'],
    'gender X' => [fn () => ['set' => ['gender' => 'X']], 'gender_invalid', 'gender'],
    'blank birth place' => [fn () => ['set' => ['birth_place' => '']], 'birth_place_invalid', 'birth_place'],
    'birth date today' => [fn () => ['set' => ['birth_date' => '2026-10-15']], 'birth_date_invalid', 'birth_date'],
    'birth date wrong format' => [fn () => ['set' => ['birth_date' => '12-05-2014']], 'birth_date_invalid', 'birth_date'],
    'birth date impossible' => [fn () => ['set' => ['birth_date' => '2014-02-30']], 'birth_date_invalid', 'birth_date'],
    'nisn too short' => [fn () => ['set' => ['nisn' => '12345']], 'nisn_invalid', 'nisn'],
    'nisn with letters' => [fn () => ['set' => ['nisn' => '01234567ab']], 'nisn_invalid', 'nisn'],
    'blank previous school' => [fn () => ['set' => ['previous_school' => '']], 'previous_school_invalid', 'previous_school'],
    'blank guardian name' => [fn () => ['set' => ['guardian_name' => '']], 'guardian_name_invalid', 'guardian_name'],
    'phone too short' => [fn () => ['set' => ['guardian_phone' => '12345']], 'phone_invalid', 'guardian_phone'],
    'phone landline prefix' => [fn () => ['set' => ['guardian_phone' => '0712345678']], 'phone_invalid', 'guardian_phone'],
    'foreign phone' => [fn () => ['set' => ['guardian_phone' => '+1 555 1234']], 'phone_invalid', 'guardian_phone'],
    'bad email' => [fn () => ['set' => ['email' => 'budi@']], 'email_invalid', 'email'],
    'consent false' => [fn () => ['set' => ['consent' => false]], 'consent_required', 'consent'],
    'consent string zero' => [fn () => ['set' => ['consent' => '0']], 'consent_required', 'consent'],
    'consent missing' => [fn () => ['unset' => ['consent']], 'consent_required', 'consent'],
]);

it('AC-12: leaves consented_at empty when consent is refused', function () {
    try {
        pendaftaranSubmit(['consent' => false]);
    } catch (ValidationException) {
    }

    expect(Applicant::withoutGlobalScopes()->whereNotNull('consented_at')->count())->toBe(0);
});

it('AC-12: accepts empty optional fields as null', function () {
    $applicant = pendaftaranSubmit(['nisn' => ' ', 'email' => ''])['applicant']->fresh();

    expect($applicant->nisn)->toBeNull()->and($applicant->email)->toBeNull();
});

it('AC-12: normalizes only the requested fields', function () {
    $action = app(SubmitApplication::class);

    expect($action->normalize(['name' => ' Ahmad  Fauzan ', 'gender' => 'L'], ['name', 'gender']))
        ->toMatchArray(['name' => 'Ahmad Fauzan', 'gender' => 'L']);

    pendaftaranAssertRejected(fn () => $action->normalize(['name' => 'Ahmad', 'gender' => 'X', 'birth_date' => 'bukan tanggal'], ['name', 'gender']), 'gender_invalid', 'gender');
});

it('AC-12: reports every failing field of the requested subset at once', function () {
    try {
        app(SubmitApplication::class)->normalize(['name' => '', 'gender' => 'X'], ['name', 'gender']);
        test()->fail('ValidationException diharapkan.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toContain('name', 'gender');
    }
});

it('AC-13: rejects a wave that is closed or outside its dates', function (string $case) {
    if ($case === 'closed flag') {
        $this->f->wave->update(['is_open' => false]);
    } else {
        $this->travelTo(CarbonImmutable::parse('2027-01-01 10:00', 'Asia/Jakarta'));
    }

    pendaftaranAssertRejected(fn () => pendaftaranSubmit(), 'wave_not_accepting', 'admission_wave_id');

    expect(pendaftaranApplicantCount())->toBe(0);
})->with(['closed flag', 'after closing date']);

it('AC-13: rejects a full wave', function () {
    $this->f->wave->update(['quota' => 1]);
    pendaftaranApplicant(['name' => 'Orang Lain']);

    pendaftaranAssertRejected(fn () => pendaftaranSubmit(), 'wave_full', 'admission_wave_id');

    expect(pendaftaranApplicantCount())->toBe(1);
});

it('AC-13: rejects a duplicate application in the same wave', function () {
    pendaftaranApplicant(['name' => 'ahmad fauzan', 'birth_date' => '2014-05-12', 'guardian_phone' => '081234567890']);

    pendaftaranAssertRejected(fn () => pendaftaranSubmit(), 'duplicate_application', 'name');

    expect(pendaftaranApplicantCount())->toBe(1);
});

it('AC-13: does not treat a different birth date or phone as duplicate', function (string $field, string $value) {
    pendaftaranApplicant(['name' => 'Ahmad Fauzan', 'birth_date' => '2014-05-12', 'guardian_phone' => '081234567890']);

    pendaftaranSubmit([$field => $value]);

    expect(pendaftaranApplicantCount())->toBe(2);
})->with([['birth_date', '2014-05-13'], ['guardian_phone', '081299998888']]);

it('AC-13: accepts the same data in another wave or when the duplicate was deleted', function (string $case) {
    $existing = pendaftaranApplicant(['name' => 'Ahmad Fauzan', 'birth_date' => '2014-05-12', 'guardian_phone' => '081234567890']);
    $overrides = [];

    if ($case === 'other wave') {
        $overrides['admission_wave_id'] = psbWave($this->f->y2027, 'Gelombang 2', '2026-10-01', '2026-12-31')->id;
    } else {
        $existing->delete();
    }

    expect(pendaftaranSubmit($overrides)['applicant']->exists)->toBeTrue()
        ->and(pendaftaranApplicantCount())->toBe(2);
})->with(['other wave', 'deleted duplicate']);
