<?php

use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Auth\Notifications\VerifyEmailChange;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
});

it('AC-10: redirects an unverified user to the verification prompt on panel and custom routes', function () {
    $user = User::factory()->unverified()->create()->assignRole('super-admin');
    $prompt = route('filament.admin.auth.email-verification.prompt');

    $this->actingAs($user);

    $this->get('/admin')->assertRedirect($prompt);
    $this->get(route('filament.admin.keuangan.receipt', Payment::factory()->create()))->assertRedirect($prompt);
});

it('AC-10: lets a verified user through', function () {
    $this->actingAs(User::factory()->create()->assignRole('super-admin'));
    $prompt = route('filament.admin.auth.email-verification.prompt');

    $this->get('/admin')->assertOk();
    expect($this->get(route('filament.admin.keuangan.receipt', Payment::factory()->create()))->headers->get('Location'))->not->toBe($prompt);
});

it('AC-13: a profile email change is sent for verification and ends verified once confirmed', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'lama@pondok.sch.id', 'email_verified_at' => '2024-03-01 08:00:00'])->assignRole('super-admin');
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->fillForm(['email' => 'baru@pondok.sch.id', 'currentPassword' => 'password'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->email)->toBe('lama@pondok.sch.id');

    $url = null;
    Notification::assertSentOnDemand(VerifyEmailChange::class, function (VerifyEmailChange $notification) use (&$url): bool {
        $url = $notification->url;

        return true;
    });

    $this->get($url);

    $user->refresh();

    expect($user->email)->toBe('baru@pondok.sch.id')
        ->and($user->email_verified_at)->not->toBeNull();
});
