<?php

use App\Actions\Psb\CheckApplicantCredentials;
use App\Actions\Psb\ResetApplicantToken;
use App\Models\Applicant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->f = pendaftaranFixture();
    $this->applicant = pendaftaranApplicant(['registration_no' => 'PSB-2027-0001']);
});

function pendaftaranCheck(string $number, string $token): ?Applicant
{
    return app(CheckApplicantCredentials::class)->handle($number, $token);
}

it('AC-24: finds the applicant with a normalized number and token', function (string $number, string $token) {
    expect(pendaftaranCheck($number, $token)?->id)->toBe($this->applicant->id);
})->with([
    'exact' => ['PSB-2027-0001', 'ABCD-EFGH-JK23'],
    'lowercase token without hyphens' => ['PSB-2027-0001', 'abcdefghjk23'],
    'lowercase number with spaces' => ['  psb-2027-0001 ', 'ABCD-EFGH-JK23'],
    'token with spaces' => ['PSB-2027-0001', ' abcd efgh jk23 '],
]);

it('AC-24: returns null for a wrong token, unknown number or deleted applicant', function (string $case) {
    $number = 'PSB-2027-0001';
    $token = 'ABCD-EFGH-JK23';

    match ($case) {
        'wrong token' => $token = 'ABCD-EFGH-JK24',
        'unknown number' => $number = 'PSB-2027-0999',
        'deleted' => $this->applicant->delete(),
        'empty token' => $token = '',
    };

    expect(pendaftaranCheck($number, $token))->toBeNull();
})->with(['wrong token', 'unknown number', 'deleted', 'empty token']);

it('AC-24: ignores the unit scope of a logged in admin', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(psbUser('admin-psb', ['SD']));

    expect(pendaftaranCheck('PSB-2027-0001', 'ABCD-EFGH-JK23')?->id)->toBe($this->applicant->id);
});

it('AC-25: always runs one hash check, also for an unknown number', function (string $number) {
    $hash = Mockery::mock(app('hash'))->makePartial();
    $hash->shouldReceive('check')->once()->passthru();
    Hash::swap($hash);

    pendaftaranCheck($number, 'ABCD-EFGH-JK23');
})->with(['PSB-2027-0001', 'PSB-2027-0999']);

it('AC-35: issues tokens in the documented alphabet and format', function () {
    foreach (range(1, 20) as $ignored) {
        expect(ResetApplicantToken::newToken())->toMatch(pendaftaranTokenPattern());
    }
});

it('AC-35: normalizes tokens', function () {
    expect(ResetApplicantToken::normalizeToken(' abcd-efgh jk23 '))->toBe('ABCDEFGHJK23');
});
