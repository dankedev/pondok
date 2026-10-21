<?php

use App\Enums\Module;
use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;

it('AC-12: the app works with all modules disabled', function () {
    portalBoot();
    $this->seed(RolePermissionSeeder::class);

    $link = fn (string $type, string $label): array => ['type' => $type, 'label' => $label];
    profilRawSet('portal.navigation_primary', [$link('home', 'Beranda'), $link('posts', 'Berita'), $link('albums', 'Galeri'), $link('videos', 'Video'), $link('psb.info', 'Info PSB'), $link('letters.verify', 'Verifikasi')]);
    profilRawSet('portal.navigation_footer', [$link('posts', 'Berita'), $link('psb.status', 'Status PSB'), $link('letters.verify', 'Verifikasi')]);
    profilRawSet('portal.header_button', $link('psb.register', 'Daftar'));
    blkSet([
        blk('hero', ['title' => 'Selamat datang']),
        blk('cta', ['heading' => 'Ajakan', 'variant' => 'light', 'primary' => $link('posts', 'Baca berita')]),
        blk('feed', ['source' => 'posts']),
        blk('feed', ['source' => 'videos']),
    ]);

    foreach (Module::cases() as $module) {
        Setting::set("modules.{$module->value}.enabled", false);
    }

    $admin = kontenUser('super-admin');
    $this->get('/admin/login')->assertOk();

    expect(bayarHtmlText(dashboardGet($admin)->getContent()))->toContain('Modul belum tersedia');

    foreach (['users', 'units', 'academic-years', 'staff', 'settings/identity', 'settings/modules'] as $path) {
        $this->actingAs($admin)->get("/admin/{$path}")->assertOk();
    }

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toMatch('~href="(?:https?://[^/"]+)?/(?:berita|galeri|video|psb|info-psb|verifikasi-surat)(?:[/?#][^"]*)?"~');
});
