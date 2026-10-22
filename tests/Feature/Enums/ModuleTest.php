<?php

use App\Enums\Module;
use App\Models\Setting;

it('AC-1: lists exactly the nine modules with translated labels and descriptions', function () {
    expect(array_map(fn (Module $m): string => $m->value, Module::cases()))
        ->toBe(['akademik', 'asrama', 'keuangan', 'psb', 'dokumen', 'konten', 'prestasi', 'portal-wali', 'tahfidz']);

    expectTranslatedKeys(fn (): array => collect(Module::cases())->flatMap(fn (Module $m): array => [
        "pondok.modules.{$m->value}.label",
        "pondok.modules.{$m->value}.description",
    ])->all());

    foreach (Module::cases() as $module) {
        expect($module->label())->toBe(__("pondok.modules.{$module->value}.label"))
            ->and($module->description())->toBe(__("pondok.modules.{$module->value}.description"));
    }
});

it('AC-1: declares the dependency graph and derives dependents from it', function () {
    $keys = fn (array $modules): array => array_map(fn (Module $m): string => $m->value, $modules);
    $requires = [
        'akademik' => [], 'asrama' => ['akademik'], 'keuangan' => ['akademik'],
        'psb' => ['akademik', 'keuangan'], 'dokumen' => [], 'prestasi' => ['akademik'], 'konten' => [],
        'portal-wali' => ['akademik'], 'tahfidz' => ['akademik'],
    ];

    foreach (Module::cases() as $module) {
        expect($keys($module->requires()))->toEqualCanonicalizing($requires[$module->value], "requires {$module->value}");
    }

    expect($keys(Module::Keuangan->dependents()))->toBe(['psb'])
        ->and($keys(Module::Akademik->dependents()))->toEqualCanonicalizing(['asrama', 'keuangan', 'psb', 'prestasi', 'portal-wali', 'tahfidz'])
        ->and($keys(Module::Konten->dependents()))->toBe([])
        ->and($keys(Module::Dokumen->requires()))->toBe([]);
});

it('AC-1: isEnabled reads the setting and treats a missing row as off', function () {
    expect(Module::Konten->isEnabled())->toBeFalse();

    Setting::set('modules.konten.enabled', true);

    expect(Module::Konten->isEnabled())->toBeTrue();
});
