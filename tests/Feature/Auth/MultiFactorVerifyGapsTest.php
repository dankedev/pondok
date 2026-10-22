<?php

use App\Actions\Core\CreateSuperAdmin;
use App\Actions\Core\ResetUserMultiFactor;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\ApplicantDocument;
use App\Models\Document;
use App\Models\IssuedLetter;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
});

it('F055 verify: the verification prompt and logout stay reachable for an unverified user (no redirect loop)', function () {
    $this->actingAs(User::factory()->unverified()->create()->assignRole('super-admin'));

    $this->get(route('filament.admin.auth.email-verification.prompt'))->assertOk();
    $this->post(route('filament.admin.auth.logout'))->assertRedirect();
    $this->assertGuest();
});

it('F055 verify: an unverified user is redirected from resources, the profile and every custom panel route', function (string $route) {
    $this->actingAs(User::factory()->unverified()->create()->assignRole('super-admin'));
    $prompt = route('filament.admin.auth.email-verification.prompt');

    $url = match ($route) {
        'users' => '/admin/users',
        'profile' => route('filament.admin.auth.profile'),
        'dokumen.download' => route('filament.admin.dokumen.download', Document::factory()->create()),
        'dokumen.letters.print' => route('filament.admin.dokumen.letters.print', IssuedLetter::factory()->create()),
        'psb.applicant-document' => route('filament.admin.psb.applicant-document', ApplicantDocument::factory()->create()),
        'keuangan.payment-proof' => route('filament.admin.keuangan.payment-proof', Payment::factory()->create()),
        'keuangan.finance-export' => route('filament.admin.keuangan.finance-export', (string) Str::uuid()),
    };

    expect($this->get($url)->headers->get('Location'))->toBe($prompt);
})->with(['users', 'profile', 'dokumen.download', 'dokumen.letters.print', 'psb.applicant-document', 'keuangan.payment-proof', 'keuangan.finance-export']);

it('F055 verify: an inactive user with the correct TOTP code is still not signed in', function () {
    $user = User::factory()->create(['email' => 'off@pondok.sch.id', 'is_active' => false])->assignRole('super-admin');
    mfaEnable($user);
    $code = AppAuthentication::make()->getCurrentCode($user->fresh());

    $test = Livewire::test(Login::class)
        ->fillForm(['email' => 'off@pondok.sch.id', 'password' => 'password'])
        ->call('authenticate');

    $this->assertGuest();

    if ($test->get('userUndertakingMultiFactorAuthentication') !== null) {
        $test->set('data.multiFactor.app.code', $code)->call('authenticate');
    }

    $this->assertGuest();
});

it('F055 verify: a super-admin cannot change their own email through a forged form state', function () {
    $admin = User::factory()->create(['email' => 'saya@pondok.sch.id'])->assignRole('super-admin');
    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $admin->id])
        ->set('data.email', 'lain@pondok.sch.id')
        ->call('save');

    expect($admin->fresh())->email->toBe('saya@pondok.sch.id')->email_verified_at->not->toBeNull();
});

it('F055 verify: the updating hook clears verification only when the email changes alone', function () {
    $user = User::factory()->create(['email' => 'a@pondok.sch.id', 'email_verified_at' => '2024-03-01 08:00:00']);

    $user->update(['name' => 'Nama Lain']);
    expect($user->fresh()->email_verified_at)->not->toBeNull();

    $user->update(['email' => 'b@pondok.sch.id']);
    expect($user->fresh()->email_verified_at)->toBeNull();

    $user->forceFill(['email' => 'c@pondok.sch.id', 'email_verified_at' => '2025-01-01 00:00:00'])->save();
    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('F055 verify: enabling and resetting MFA never leaves secrets in the activity log', function () {
    $actor = User::factory()->create()->assignRole('super-admin');
    $target = User::factory()->create()->assignRole('bendahara');
    $mfa = mfaEnable($target);

    app(ResetUserMultiFactor::class)->handle($actor, $target);
    mfaEnable($target);
    app(ResetUserMultiFactor::class)->handle(null, $target->fresh());

    $dump = Activity::query()->get()->toJson();

    expect(Activity::query()->count())->toBe(2)
        ->and($dump)->not->toContain($mfa['secret'])
        ->and($dump)->not->toContain($mfa['codes'][0])
        ->and($dump)->not->toContain('app_authentication');
});

it('F055 verify: MFA columns are hidden from JSON and from the model attributes dump', function () {
    $user = User::factory()->create();
    mfaEnable($user);

    $json = $user->fresh()->toJson();

    expect($json)->not->toContain('app_authentication')->not->toContain('password');
});

it('F055 verify: the warning banner does not break the login page for guests', function () {
    $this->get('/admin/login')->assertOk()->assertDontSee(__('pondok.user.security.mfa_warning_title'));
});

it('F055 verify: the warning disappears for a bendahara after the MFA is reset-and-enabled and never targets inactive-role users', function () {
    $user = User::factory()->create()->assignRole('bendahara');
    expect($user->shouldBeWarnedToEnableMultiFactor())->toBeTrue();

    mfaEnable($user);
    expect($user->fresh()->shouldBeWarnedToEnableMultiFactor())->toBeFalse();

    expect(User::factory()->create()->shouldBeWarnedToEnableMultiFactor())->toBeFalse();
});

it('F055 verify: promoting an already verified account keeps its verification date', function () {
    User::factory()->create(['email' => 'lama@pondok.sch.id', 'email_verified_at' => '2024-03-01 08:00:00']);

    app(CreateSuperAdmin::class)->handle('Nama', 'lama@pondok.sch.id', 'rahasia-123');

    expect(User::query()->where('email', 'lama@pondok.sch.id')->sole()->email_verified_at->toDateString())->toBe('2024-03-01');
});

it('F055 verify: the CLI resets the only super-admin and leaves other users alone', function () {
    $only = User::factory()->create(['email' => 'satu@pondok.sch.id'])->assignRole('super-admin');
    $other = User::factory()->create();
    mfaEnable($only);
    mfaEnable($other);

    Artisan::call('pondok:reset-mfa', ['email' => 'satu@pondok.sch.id']);

    expect(mfaRaw($only)['secret'])->toBeNull()
        ->and(mfaRaw($other)['secret'])->not->toBeNull();
});
