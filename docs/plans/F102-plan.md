# F102 konten-katalog-tema — Katalog tema resmi: pilih dan pasang dari dashboard

Issue: #133
Status rencana: APPROVED (setelah revisi 1)
PR ditujukan ke `dev` (cabang `feat/f102-katalog-tema`), memakai `Refs #133`, bukan `Closes`.

## Ringkasan & tujuan
Admin memilih tema dari katalog resmi (`themes.json` statis), paket diunduh lewat antrean, diverifikasi sha256 dan tanda tangan Ed25519, lalu muncul di Situs > Tampilan. Cakupan tema v1: blok beranda + header dan footer publik. Tema pihak luar tertutup. Portal tidak boleh gagal karena tema.

## Keputusan desain
- **Tanpa migration.** Status terpasang = isi folder + `theme.json`; status unduhan di `Setting` (`themes.install.<slug>`).
- **Lokasi kode tema:** `storage/app/themes/<slug>/` (aktif), `storage/app/themes/_previous/<slug>/` (satu versi sebelumnya, utuh termasuk `assets/`), `_staging/<uuid>/`. View didaftarkan sebagai namespace `theme` ke tema aktif (`View::addNamespace`) di service provider, hanya bila valid.
- **Format paket `.pondok-theme`:** 64 byte tanda tangan Ed25519 (detached, atas byte zip) + zip. Jalur katalog dan unggah manual memakai verifikator yang sama. Paket dibaca **sekali** ke memori (batas 5 MB), sha256 dan tanda tangan diverifikasi dari byte itu, lalu zip ditulis dari byte yang sama (tanpa baca ulang). Katalog (`slug, name, version, size, sha256, min_core, url, preview`) tidak dipercaya untuk keamanan; kuncinya tanda tangan. Manifest harus cocok dengan entri katalog; versi lebih rendah dari terpasang ditolak.
- **Isi zip:** `theme.json` (`slug, name, description, version, min_core`), `views/header.blade.php`, `views/footer.blade.php`, `views/blocks/<tipe>.blade.php` (opsional, minimal satu view), `assets/*` terkompilasi pengelola. Whitelist ekstensi seluruh paket: `blade.php, json, css, woff2, png, jpg, webp`; berkas lain menolak paket, jadi seluruh `assets/*` disalin apa adanya. Tanpa svg, js, html, php polos, symlink.
- **Aset di disk `public`** (bertahan di Docker, hanya volume storage yang persisten): `storage/app/public/themes/<slug>/<versi>/`, dilayani lewat `public/storage` atau `PUBLIC_STORAGE_ROOT`. Tanpa penyalinan ke `pondok.public_path`. URL via `Storage::disk('public')->url`. Layout menautkan CSS (`theme.css` bila ada) setelah `@vite`, sebelum `<style data-portal-theme>`; versi di path = cache busting.
- **Aturan aset versi lama:** saat update, aset publik versi lama dipertahankan (untuk rollback) dan aset versi yang lebih tua lagi dihapus. `RollbackTheme` menukar balik kode, memastikan aset versi itu ada (disalin ulang dari `_previous/assets` bila hilang), menghapus aset versi yang dibatalkan, dan **mengosongkan `_previous`** (hanya satu langkah mundur, tanpa maju lagi).
- **Cache Blade:** setelah tukar dan rollback, `touch()` semua `.blade.php` aktif agar kompilasi lama tidak dipakai.
- **Kontrak layout (sempit sengaja):** `layouts.public` inti tetap memiliki `<html>`, `<head>`, tautan lompat, `<main id="konten">{{ $slot }}`. Tema hanya mengganti header (termasuk navigasi) dan footer lewat `theme::header|footer`. Inti menghitung `$school`, `$navigation`, `$footer`, `$compactFooter` sekali lalu mengoper ke partial (footer inti dirapikan agar menerima `$footer`), jadi tema tanpa query sendiri. Header/footer inti dipindah ke `portal.partials.header|footer` dan jadi fallback. Tema boleh `@include('portal.partials.nav-links')`.
- **Cakupan tema (YAGNI):** tema aktif hanya di route `portal.*` dan `psb.info` (semua stateless, `SetPortalCacheHeaders`). Halaman bersesi (Livewire formulir PSB, PortalWali Login/ForgotPassword/ResetPassword) **tetap memakai chrome inti**; layout memutuskannya dari nama route. Tidak ada tema untuk halaman wali.
- **Denylist saat validasi paket** (penjaga kontrak halaman cache, bukan batas keamanan): `@livewire`, `<livewire`, `@livewireStyles`, `@csrf`, `csrf_token`, `@method`, `@auth`, `@guest`, `auth(`, `session(`, `old(`, `request()->user`, `$errors`, `@error`, `@vite`, `@php`, `@inject`, `@use`, `<script`, `{!!`, `<x-`, `@component`, `@each`, `@includeIf|When|First`, dan `@include`/`@extends` ke view di luar `theme::` dan `portal.partials.*`. Daftar tidak lengkap; jaring pengaman sebenarnya assert runtime: respons bertema tanpa `csrf-token` dan `Set-Cookie`.
- **Fallback render:** `RenderPortalChrome` merender `theme::header|footer` dalam try/catch; begitu pula render satu blok beranda (`RenderHomeBlock`): gagal → `themes.default.blocks.<tipe>` + `Log::warning` (dibatasi sekali per tipe per jam lewat `Cache::add`). Tema tidak valid → semua inti. "Valid" dicek murah per request: manifest terbaca, view ada, **berkas CSS yang dirujuk ada di disk public**, `min_core` <= versi inti (`VERSION`); tanpa hash ulang.
- **Beranda:** `theme.template` = slug (bawaan inti `default`, `centered` + terpasang). `blockView` memakai `theme::blocks.<tipe>` bila ada, lalu `themes.default`.
- **Cache portal (fakta kode):** `SetPortalCacheHeaders` memberi `Cache-Control: public, max-age` (`pondok.portal.cache_max_age`, 300) + ETag. Setelah ganti tema, tampilan basi paling lama ±5 menit, diterima dan dijelaskan di UI. Tema tidak boleh merusak header/ETag.
- **Izin:** memasang tema = mengeksekusi Blade, jadi permission baru `manage:themes` (pola `view:*` di `RolePermissionSeeder::PAGE_PERMISSIONS`, ditambahkan ke daftar itu) khusus `super-admin`; melihat/memilih tema terpasang tetap `view:school-profile`.
- **Klien HTTP:** `app/Services/ThemeCatalogClient` (satu-satunya pemakai `Http::`): timeout 10 dtk, batas katalog 256 KB dan paket 5 MB, katalog di-cache 1 jam, gagal tidak melempar ke UI.
- **Job:** `$timeout` 120 dtk, `$tries = 1`, `failed()` menulis status `failed`.

## Ruang lingkup
- Termasuk: katalog + status, pasang/perbarui/kembalikan/hapus, unggah manual, doctor, perintah penandatangan, header/footer/blok beranda tema, fallback.
- Tidak termasuk: tema berbayar/lisensi, pihak ketiga, marketplace, addon, statistik pemasangan (ditunda), CSS/JS tema untuk halaman isi, tema halaman wali/formulir PSB, pembaruan otomatis, `keygen`, rotasi kunci, pemulihan data demo.

## Model data
Tanpa tabel baru. `Setting`: `themes.install.<slug>` (`{status: queued|running|failed|done, message, updated_at}`); `theme.template` berisi slug. `config/pondok.php` → `themes`: `catalog_url`, `public_key` (base64, literal; privat tidak di repo), `max_package_bytes`, `max_files` (200), `max_unpacked_bytes` (20 MB).

## Action class (`app/Actions/Konten/`)
- `ListPortalThemes`: bawaan + terpasang (+ katalog) dengan status `belum|terpasang|pembaruan|tidak_cocok`; menggantikan `GetPortalTheme::TEMPLATES` (konstanta dihapus; `UpdatePortalTheme`, `Theme`, `GetPortalTheme` memakainya). Status job `queued|running` yang `updated_at`-nya > 5 menit dihitung `failed`.
- `InstallThemePackage(bytes|path, ?catalogEntry, actor)`: ukuran, sha256, tanda tangan, zip aman, ekstrak ke `_staging`, validasi manifest + denylist, salin aset ke disk public, tukar atomik (aktif → `_previous`; gagal di tengah memulihkan), `touch()` blade. Log aktivitas `theme_installed`.
- `RollbackTheme`, `RemoveTheme` (hapus folder + aset; `theme.*` dan konten tidak disentuh), `ResolveActiveTheme` (valid + alasan), `RenderPortalChrome`, `RenderHomeBlock`.
- Job `app/Jobs/Konten/InstallThemeFromCatalog` (unduh via client, `InstallThemePackage`, tulis status; satu job aktif per slug).
- Perintah `pondok:theme-sign {dir} --key-file= --out=`: validasi sama dengan pasang, zip deterministik, tanda tangan, cetak entri katalog (sha256, size).

## UI (Filament)
- Page `Site/ThemeCatalog` (slug `site/theme-catalog`, `getModule()` Konten, grup Situs): kartu tema (pratinjau, versi, status), tombol Pasang/Perbarui/Kembalikan/Hapus, unggah manual (`FileUpload` disk lokal sementara), `wire:poll.5s` saat job berjalan, pesan "antrean berjalan paling lama ±1 menit" dan "perubahan tampil di portal hingga ±5 menit".
- `Site/Theme`: Radio memakai `ListPortalThemes`, tema tidak cocok nonaktif dengan alasan; tautan ke katalog. Teks di `lang/id/pondok.php`. Ikuti `docs/design-guide.md` (§0, status, state kosong/gagal); kontras dan fokus layout tema dicek pengelola saat menandatangani (bukan test otomatis).

## Permission & cakupan
`manage:themes` baru (super-admin) di `RolePermissionSeeder`. Page baru `getModule()` Konten; tanpa scope `unit_user`, route baru, atau jadwal baru.

## Kriteria penerimaan
- **AC-1** Katalog (client di-fake): `super-admin` melihat kartu berstatus belum / terpasang / pembaruan / tidak cocok (`min_core` > inti, Pasang nonaktif). `admin-sekolah` 403 pada Pasang/Hapus/Unggah; peran tanpa `view:school-profile` 403 pada page.
- **AC-2** Katalog timeout, 500, JSON rusak, atau > 256 KB: page 200, daftar terpasang tetap tampil + pesan; tanpa exception.
- **AC-3** Klik Pasang memasukkan `InstallThemeFromCatalog` ke antrean `database`, status `queued`; menjalankan job memasang dan status `done`; klik ganda untuk slug berjalan tidak membuat job kedua; gagal menulis `failed` + pesan Indonesia (juga lewat `failed()`/timeout); status `running`/`queued` > 5 menit ditampilkan `failed` dan Pasang bisa diulang.
- **AC-4** Ditolak tanpa mengubah folder tema, aset, maupun `theme.*`: sha256 salah, tanda tangan salah/terpotong/kunci lain, slug/versi manifest ≠ katalog, versi lebih rendah dari terpasang, ukuran > batas. Unggah manual lewat `InstallThemePackage` yang sama: paket bertanda tangan valid terpasang; tanpa tanda tangan atau zip polos ditolak dengan pesan Indonesia.
- **AC-5** Ekstraksi menolak: `../`, path absolut, `\`, byte kontrol, symlink, ekstensi di luar whitelist (`.php`, `.svg`, `.js`, `.html`, tanpa ekstensi), > 200 file, total terekstrak > 20 MB (termasuk header zip yang berbohong soal ukuran). Tidak ada file di luar `_staging`; `_staging` dibersihkan.
- **AC-6** Validasi manifest/kontrak: `theme.json` valid; slug `^[a-z0-9-]{2,40}$` bukan `default`/`centered`; semver; `min_core`; minimal satu view; setiap pola denylist di header/footer/blok menolak paket (data provider per pola), baik saat pasang maupun `pondok:theme-sign`.
- **AC-7** Atomik: kegagalan disimulasikan di langkah salin aset dan langkah tukar → tema lama dan asetnya utuh, tanpa folder setengah jadi. Update sukses menyimpan versi lama di `_previous` (satu). `RollbackTheme`: keluaran render portal berikutnya memakai **view versi lama** (bukan cache terkompilasi), aset versi lama ada, aset versi dibatalkan terhapus, `_previous` kosong. `RemoveTheme` menghapus folder + aset; `theme.*` dan data konten tidak berubah.
- **AC-8** Aset di `storage/app/public/themes/<slug>/<versi>/` (seluruh `assets/*`, hanya ekstensi whitelist), tidak ada penulisan ke `pondok.public_path`; portal menautkan CSS tema (URL disk public, versi di path) setelah `@vite`, sebelum `<style data-portal-theme>`; tanpa tema, keluaran head sama dengan sekarang.
- **AC-9** `ListPortalThemes` = bawaan + terpasang. `UpdatePortalTheme` menerima slug terpasang, menolak slug tak dikenal dan tema tidak cocok versi (tidak tersimpan). Tema aktif dengan blok `hero`: beranda memakainya; blok tak disediakan memakai `themes.default`.
- **AC-10** Tema dengan header + footer: `portal.home`, berita, dan `psb.info` merender header/footer tema di dalam `<head>`, tautan lompat, dan `<main id="konten">` inti; `<head>` identik dengan tanpa tema selain tautan CSS; tema hanya-header memakai footer inti. Respons bertema tanpa `csrf-token`, aset Livewire, atau `Set-Cookie`, dengan `Cache-Control` public dan ETag utuh seperti tanpa tema. Jumlah query sama dengan layout inti. Halaman PortalWali (masuk, lupa/atur password) dan formulir PSB Livewire tetap chrome inti walau tema aktif.
- **AC-11** Tema aktif hilang, manifest rusak, view wajib hilang, **berkas CSS hilang dari disk public**, atau `min_core` > inti: `portal.home`, berita, `psb.info` tetap 200 dengan header/footer/blok/tautan bawaan inti (tanpa tautan CSS tema); `Log::warning` tercatat.
- **AC-12** Satu view blok tema melempar exception (render): halaman 200, blok itu memakai `themes.default.blocks.<tipe>`, blok lain tetap versi tema; `Log::warning` tercatat sekali untuk beberapa request dalam satu jam. Begitu pula header tema yang melempar → header inti.
- **AC-13** `sodium` ada di `REQUIRED_EXTENSIONS` (dan `ext-sodium` di `composer.json`, bila disetujui); check `theme_catalog` di `CheckSystemHealth::CHECKS`: `ok` bila katalog terbaca, `warning` bila tidak terjangkau, `skipped` bila modul Konten mati; `pondok:doctor` menampilkannya.
- **AC-14** `pondok:theme-sign` dengan kunci uji menghasilkan `.pondok-theme` yang lolos `InstallThemePackage` (config `public_key` = kunci uji) dan entri katalog dengan sha256 dan size benar; gagal jelas bila berkas kunci tidak ada atau tema melanggar AC-5/AC-6; zip deterministik (dua kali sign = byte sama).
- **AC-15** `ModuleComplianceTest` lulus (page di Konten); `manage:themes` hanya untuk `super-admin`; semua teks baru lewat `lang/id`; Konten dimatikan menyembunyikan page tanpa menghapus data tema.

## Rencana test
- `tests/Feature/Konten/ThemePackageTest.php`: AC-4 sampai AC-7 (helper `makeThemePackage()` dengan kunci uji di trait/`tests/Pest.php`).
- `tests/Feature/Konten/ThemeCatalogPageTest.php`: AC-1 sampai AC-3, AC-15 (Livewire, `Http::fake`, `Queue::fake`).
- `tests/Feature/Konten/ThemeRuntimeTest.php`: AC-8 sampai AC-12; perbarui `PortalThemeTest` yang memakai `TEMPLATES`.
- `tests/Feature/Core/SystemHealthThemeTest.php` (sesuaikan lokasi test doctor): AC-13. `tests/Feature/Console/ThemeSignCommandTest.php`: AC-14.

## Urutan implementasi
1. **Paket A, data + Action (hijau: ThemePackageTest, ThemeSignCommandTest):** config, `ThemeCatalogClient`, verifikator, `InstallThemePackage`, `RollbackTheme`, `RemoveTheme`, `pondok:theme-sign`, permission seeder.
2. **Paket B, runtime (hijau: ThemeRuntimeTest, PortalThemeTest, test PSB/portal yang ada):** `ResolveActiveTheme`, namespace view, `ListPortalThemes` menggantikan `TEMPLATES`, ekstrak header/footer inti, `RenderPortalChrome`, `RenderHomeBlock` + fallback per blok, tautan CSS, pembatasan route.
3. **Paket C, Filament + doctor (hijau: ThemeCatalogPageTest, SystemHealthThemeTest, ModuleComplianceTest):** job antrean, page `ThemeCatalog`, perubahan `Theme`, lang/id, `sodium` + check `theme_catalog`, `docs/rilis.md` (kunci, menandatangani, pedoman CSS).

## Risiko & pertanyaan terbuka
- **Blade = kode PHP.** Keamanan bertumpu pada kunci privat; kebocorannya berarti eksekusi kode di semua instalasi. Kunci tidak di repo/CI; rotasi di luar v1.
- **Replay paket lama** bertanda tangan valid (anti-rollback hanya membandingkan dengan yang terpasang): risiko diterima di v1.
- **CSS tema** hanya utilitas yang dikompilasi pengelola; pedoman masuk `docs/rilis.md`.
- Antrean lewat cron: pemasangan tertunda ±1 menit; tampilan portal basi ≤5 menit (dijelaskan di UI).
- **Butuh keputusan user:** (1) `manage:themes` hanya `super-admin` atau juga `admin-sekolah`? (2) Menambah `ext-sodium` ke `composer.json` mengubah dependensi/lock: setuju? (3) URL katalog resmi dan pemegang kunci privat. (4) `centered` bawaan tetap di inti: setuju?
- Beda dengan issue: kriteria 13-15 digabung ke AC-10/11/6; kontras/fokus jadi checklist manual; statistik ditunda; tema tidak berlaku di halaman wali dan formulir PSB. Body issue perlu diperbarui.

## Review #1 (plan-reviewer, CHANGES_REQUESTED → direvisi satu kali)
| # | Temuan | Keputusan |
|---|---|---|
| 1 | Aset ke `public_path` tidak bertahan di Docker | Aset ke disk `public`; `ResolveActiveTheme` cek CSS ada; AC-8, AC-11 |
| 2 | Tanpa fallback per blok | `RenderHomeBlock` try/catch + `Log::warning` sekali/jam; paket B; AC-12 |
| 3 | Cache Blade setelah tukar/rollback | `touch()` blade; AC-7 assert keluaran versi lama |
| 4 | Status job menggantung | > 5 menit = failed, `$timeout`, `failed()`; AC-3 |
| 5 | Konsumen layout lain (PortalWali, psb) | Tema hanya `portal.*` + `psb.info`; halaman bersesi chrome inti; AC-10 |
| 6 | Klaim cache keliru | Dikoreksi (public max-age 300 + ETag); assert header di AC-10 |
| 7 | Baca paket berulang | Sekali ke memori; replay dicatat sebagai risiko diterima |
| 8 | Denylist kurang | Diperluas + assert runtime tanpa csrf/cookie |
| 9 | Whitelist aset | Salin seluruh `assets/*`; ekstensi di luar whitelist menolak paket |
| 10 | Aturan aset lama | Ditulis; `_previous` dikosongkan setelah rollback |
| 11 | AC padat | Dipecah (15 AC); permission ikut pola `PAGE_PERMISSIONS`; `ext-sodium` tetap terbuka |

## Riwayat revisi
- Revisi 1: menyikapi Review #1 (lihat tabel). Unggah manual digabung ke AC-4; AC-12 baru (fallback blok).
