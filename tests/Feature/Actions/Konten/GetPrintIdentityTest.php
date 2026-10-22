<?php

use App\Actions\Konten\GetPrintIdentity;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/*
| F044: `GetPrintIdentity::handle(): array{name, address, phone}` memakai satu query settings.
*/

beforeEach(function () {
    kontenBoot();
    Setting::query()->where('key', 'like', 'school.%')->delete();
});

it('AC-2: returns the name, address and the print contact phone', function () {
    profilSet([
        'school.name' => "Ma'had Al-Kitab", 'school.address' => 'Jl. Pesantren No. 1',
        'school.contacts' => [profilContact(['key' => 'aaaa1111', 'phone' => '081200000001']), profilContact(['key' => 'bbbb2222', 'phone' => '0735000170', 'is_print' => true])],
    ]);

    expect(app(GetPrintIdentity::class)->handle())->toEqual(['name' => "Ma'had Al-Kitab", 'address' => 'Jl. Pesantren No. 1', 'phone' => '0735000170']);
});

it('AC-2: falls back to the app name and null address and phone', function () {
    Setting::set('school.contacts', [profilContact()]);

    expect(app(GetPrintIdentity::class)->handle())->toEqual(['name' => config('app.name'), 'address' => null, 'phone' => null]);
});

it('Review minor 3: picks the first print contact when several are marked', function () {
    Setting::set('school.contacts', [
        profilContact(['key' => 'aaaa1111', 'phone' => '081200000001']),
        profilContact(['key' => 'bbbb2222', 'phone' => '081200000002', 'is_print' => true]),
        profilContact(['key' => 'cccc3333', 'phone' => '081200000003', 'is_print' => true]),
    ]);

    expect(app(GetPrintIdentity::class)->handle()['phone'])->toBe('081200000002');
});

it('AC-2: reads settings with a single query', function () {
    Setting::set('school.contacts', [profilContact(['is_print' => true])]);
    app()->forgetScopedInstances();

    DB::enableQueryLog();
    app(GetPrintIdentity::class)->handle();

    expect(collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'settings')))->toHaveCount(1);
});
