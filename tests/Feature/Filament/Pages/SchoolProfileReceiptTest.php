<?php

use App\Filament\Pages\Settings\Contacts;
use App\Filament\Pages\Settings\Identity;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('AC-38 (F044): receipt reads the name from Identity and the address and print phone from Contacts', function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $f = tagihanFixture();
    $officer = keuanganUser('bendahara');
    $payment = bayarPaymentFor($f);

    $this->actingAs(keuanganUser('admin-sekolah'));
    Livewire::test(Identity::class)
        ->fillForm(['name' => 'Pondok Contoh'])
        ->call('save')
        ->assertHasNoFormErrors();
    Livewire::test(Contacts::class)
        ->fillForm([
            'address' => 'Jl. Baru No. 99',
            'contacts' => [['label' => 'Kantor', 'phone' => '0812-0000-1111', 'is_whatsapp' => false, 'is_print' => true]],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $text = bayarHtmlText($this->actingAs($officer)->get("/admin/receipts/{$payment->id}")->assertOk()->getContent());

    expect($text)->toContain('Pondok Contoh')->toContain('Jl. Baru No. 99')->toContain('081200001111');
});
