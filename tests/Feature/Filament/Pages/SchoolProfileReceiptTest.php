<?php

use App\Filament\Clusters\InstitutionProfile\Pages\Identity;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('AC-38: receipt reads the address saved on the school profile page', function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $f = tagihanFixture();
    $officer = keuanganUser('bendahara');
    $payment = bayarPaymentFor($f);

    $this->actingAs(keuanganUser('admin-sekolah'));
    Livewire::test(Identity::class)
        ->fillForm(['name' => 'Pondok Al-Khair', 'address' => 'Jl. Baru No. 99', 'phone' => '0812-0000-1111'])
        ->call('save')
        ->assertHasNoFormErrors();

    $text = bayarHtmlText($this->actingAs($officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent());

    expect($text)->toContain('Pondok Al-Khair')->toContain('Jl. Baru No. 99')->toContain('081200001111');
});
