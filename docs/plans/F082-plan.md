# F082 Pintu masuk wali: pilihan Wali/Staf di tombol Masuk portal

Issue: #82

Jalur cepat (Blade, render hook Filament, teks `lang/id`). Tanpa migration, guard baru, atau perubahan aturan login: staf tetap ditolak di `/wali/masuk`, wali tetap 403 di `/admin`.

## Tujuan

Wali yang mengklik "Masuk" di portal publik sampai ke `/wali/masuk`, bukan tersesat ke login staf (Opsi A tinjauan URL login wali, disetujui user 22 Okt 2026).

## Perubahan

- `resources/views/portal/partials/nav-links.blade.php`: bila `Module::PortalWali` aktif, "Masuk" menjadi grup dua tautan, **Wali santri** (`portal-wali.login`) dan **Staf pondok** (`filament.admin.auth.login`). Desktop memakai `<details>` seperti grup menu (panel rata kanan); seluler memakai judul "Masuk" diikuti dua tautan berindentasi. Modul mati: satu tautan ke login admin seperti sekarang.
- `resources/views/filament/portal-wali-login-hint.blade.php` + render hook `PanelsRenderHook::AUTH_LOGIN_FORM_AFTER` di `AdminPanelProvider`: "Wali santri? Masuk lewat portal wali", hanya saat modul aktif.
- `resources/views/errors/403.blade.php` + `errors/layout.blade.php`: tombol sekunder "Buka portal wali" (`portal-wali.home`) bila user yang masuk aktif, berperan `wali`, dan modul aktif. Dicek dalam `rescue()` agar halaman error tetap tampil bila sesi/database bermasalah.
- `lang/id/pondok.php`: `portal.nav.login_wali`, `portal.nav.login_staff`, `portal_wali.admin_login_hint`, `portal_wali.admin_login_link`, `portal_wali.open_portal`.
- `tests/Feature/Http/Portal/PortalMenuTest.php`: modul portal-wali dimatikan di `beforeEach` (test menu lama tentang susunan menu, bukan pintu masuk).

## Kriteria penerimaan

1. Modul aktif: kedua nav header memuat `/wali/masuk` dan `/admin/login`.
2. Modul mati: header hanya memuat `/admin/login`.
3. Login admin memuat tautan `/wali/masuk` saat modul aktif, tidak saat mati.
4. Wali aktif membuka `/admin` → 403 dengan tombol ke `/wali`; staf non-wali yang mendapat 403 tidak melihatnya; modul mati → tanpa tombol.
5. Teks dari `lang/id`; dicek di 360px, 1024px, 1440px.
6. Test terdampak dan suite penuh lulus; Pint.
