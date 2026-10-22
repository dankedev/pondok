<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;

/*
| F055 AC-1. Migrasi: database/migrations/*_add_app_authentication_to_users_table.php
| dan database/migrations/*_mark_existing_users_email_verified.php.
*/

it('AC-1: adds the nullable MFA columns to users', function () {
    expect(Schema::hasColumns('users', ['app_authentication_secret', 'app_authentication_recovery_codes']))->toBeTrue();

    $user = User::factory()->create();

    expect(mfaRaw($user))->toBe(['secret' => null, 'codes' => null]);
});

it('AC-1: marks unverified users as verified and keeps existing verification dates', function () {
    $files = glob(database_path('migrations/*_mark_existing_users_email_verified.php'));
    expect($files)->toHaveCount(1);

    $unverified = User::factory()->unverified()->create();
    $verified = User::factory()->create(['email_verified_at' => '2024-03-01 08:00:00']);

    (require $files[0])->up();

    expect($unverified->fresh()->email_verified_at)->not->toBeNull()
        ->and($verified->fresh()->email_verified_at->toDateTimeString())->toBe('2024-03-01 08:00:00');
});
