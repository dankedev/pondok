<?php

use App\Actions\Psb\CheckReRegistrationPayment;
use App\Models\Applicant;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Storage;

it('VERIFY F040: demo accepted_partial (30%) does not meet the minimum and reregistered_partial (60%) does', function () {
    Storage::fake('private');
    Storage::fake('public');
    config(['pondok.demo.password' => 'rahasia-uji-123']);
    $this->artisan('db:seed', ['--class' => DemoSeeder::class])->assertSuccessful();

    $check = fn (string $name): array => app(CheckReRegistrationPayment::class)
        ->handle(Applicant::withoutGlobalScopes()->where('name', $name)->firstOrFail());

    expect($check('Nashrullah Ramadhani')['meets'])->toBeFalse()
        ->and($check('Akbar Maulana Yusuf')['meets'])->toBeTrue()
        ->and($check('Dzakir Amali')['meets'])->toBeTrue();
});
