# F048 konten-pengaturan-halaman — Template halaman dan hapus konsep halaman sistem

Status rencana: APPROVED

Jalur: penuh (migration, perubahan Action yang mengubah data, policy). Sumber: `docs/audits/ui-ux-portal-konten.md` §2.7, §5.4, baris S4 §6. Depends on: F037 (halaman bebas), F044 (Kontak satu sumber). Dibutuhkan F038 (Info PSB sebagai halaman bertemplate).

## Ringkasan & tujuan
Semua halaman statis setara. Yang membedakan hanya `template`. Konsep "halaman sistem" (`SYSTEM_SLUGS`: profil, visi-misi, kontak) dihapus bersama kunci slug dan larangan hapus. Perilaku khusus slug `kontak` pindah ke template `contact`. Tautan "terkait" Profil/Visi & Misi yang tertanam di kode dihapus (audit §5.4). URL lama tidak berubah.

## Ruang lingkup
- Termasuk:
  - Kolom `pages.template` (`default` | `contact`), pilihan di form Halaman, validasi di Action.
  - Hapus halaman sistem: `StaticPage::SYSTEM_SLUGS`, `isSystem()`, kunci slug, larangan hapus, badge "Halaman sistem".
  - Ganti pengecekan `slug === 'kontak'` (portal, menu, sitemap) dengan `template === 'contact'`.
  - Hapus `ShowPublicStaticPage::RELATED` dan `GetPortalNavigation::markVisionUnderProfile`.
- Tidak termasuk (eksplisit, YAGNI):
  - `excerpt`, `featured_image_path/alt`, `meta_title`, `meta_description` untuk halaman. Bukti tidak ada pemakai: `portal/pages/show.blade.php` hanya mengirim `description` hasil `SummarizeHtml` dari isi (meta description otomatis sudah ada), tidak ada tampilan kartu/daftar halaman, tidak ada gambar di halaman, dan `og:image` memakai gambar bagikan Identitas. Ditambah bila ada fitur yang memakainya (mis. F038 atau kartu sosial).
  - Template `wide` (belum ada tata letak yang membedakannya), `is_featured` untuk posts/albums (blok beranda "unggulan" belum ada), meta untuk berita.
  - Template lain (mis. `psb-info`): ditambah F038 saat dibutuhkan.
  - Mengubah slug cadangan (`info-psb` tetap), mengubah modul/permission baru (memakai `update:static-page` yang ada).
  - Kode perbaikan data demo. Demo diulang dengan `migrate:fresh --seed`.

## Model data
Migration baru `2026_10_21_000000_add_template_to_pages_table`:
- `pages.template` string(20) not null default `'default'`, setelah `status`. Tanpa index (50 baris maks), tanpa enum DB (kompatibel SQLite/MySQL/Postgres); nilai valid dijaga di Action.
- Data nyata: `UPDATE pages SET template='contact' WHERE slug='kontak'`. Slug tidak disentuh. Idempotent karena kolom baru.
- `down()`: `dropColumn('template')`. Catat: template `contact` tidak bisa dipulihkan.
- Model `StaticPage`: `#[Fillable]` tambah `template`; konstanta `TEMPLATE_DEFAULT='default'`, `TEMPLATE_CONTACT='contact'`, `TEMPLATES=[...]`; method `isContact(): bool`. Hapus `SYSTEM_SLUGS` dan `isSystem()`. Soft delete tetap tidak ada; `MAX_PAGES` 50 tetap (komentar "termasuk halaman sistem" diperbarui).
- ERD (`docs/erd.md` blok `pages` + catatan aturan) dan PRD §3.1 diperbarui: tambah `template`, hapus kalimat halaman sistem.

## Action class
- `CreateStaticPage`: terima `template` opsional (bawaan `default`); tolak di luar `TEMPLATES` dengan galat `template_invalid` (key `template`). Disimpan di `fill`.
- `UpdateStaticPage`: `template` opsional (tidak dikirim = tidak berubah), validasi sama. Hapus cabang `slug_locked`; slug halaman mana pun divalidasi seperti halaman bebas (hanya bila berubah).
- `DeleteStaticPage`: hapus guard `page_system_locked`; pembersihan rujukan menu tetap. Docblock diperbarui.
- `ValidatesStaticPageInput`: tambah `pageTemplate(mixed): string`.
- `ShowPublicStaticPage`: `isContact = $page->isContact()`; hapus `related` dari payload; 404 bila contact tanpa data kontak (aturan F037/F044 tetap).
- `GetPortalNavigation` dan `BuildSitemap`: kondisi `slug === 'kontak'` menjadi `isContact()` (perlu `template` di `get([...])` menu). Hapus `markVisionUnderProfile` beserta pemanggilnya.
- `StaticPagePolicy::delete`: hanya cek permission `delete:static-page`.
- `StaticPageSeeder`: tiga baris awal (profil, visi-misi draf; kontak terbit) tetap dibuat agar instalasi baru tidak kosong, tetapi memakai konstanta privat seeder; `kontak` diberi `template=contact`. Seed ulang tetap `firstOrCreate`.

## UI (Filament / Blade)
Baca `docs/design-guide.md` (bagian form admin, status, bahasa, checklist §0) dan `/anti-ui-slop` bila tersedia sebelum menyentuh UI.
- `StaticPageResource` form: `Select` template (opsi: Biasa, Kontak) dengan helper satu kalimat ("Kontak menampilkan kartu kontak dari Kontak & sosial di bawah isi."); slug tidak lagi `disabled`/helper "terkunci". Tabel: kolom "Jenis" (badge sistem) diganti kolom "Template" (badge `gray` hanya untuk `contact`, kosong untuk biasa). Aksi hapus tampil untuk semua halaman, modal konfirmasi lama dipakai.
- `portal/pages/show.blade.php`: hapus blok `$related`; kartu kontak mengikuti `$isContact` (sudah ada). Tampilan lain tidak berubah.
- `lang/id/pondok.php`: tambah `static_page.template`, `static_page.templates.default|contact`, `static_page.template_helper`, `konten.errors.template_invalid`; hapus `slug_helper_locked`, `kind`, `system_badge`, `konten.errors.slug_locked`, `konten.errors.page_system_locked`. Komentar/helper `slug_helper_create` tetap.

## Permission & cakupan
Tidak ada permission baru; `update:static-page` dan `delete:static-page` yang ada. Super-admin tidak lagi perlu pengecualian. Tidak ada `unit_user`. Saklar `modules.konten.enabled` tetap menggerbang resource dan portal.

## Kriteria penerimaan (bisa dites)
- AC-1 Migrasi: skema lama berisi `pages` profil, visi-misi, kontak, dan satu halaman bebas → jalankan migrasi → kolom `template` ada, `kontak` = `contact`, lainnya `default`, semua slug dan jumlah baris tak berubah; `down()` menghapus kolom.
- AC-2 Instalasi baru: `StaticPageSeeder` membuat `kontak` ber-template `contact`, dua lainnya `default`; seed ulang tidak menimpa template/isi/status yang sudah diubah.
- AC-3 `CreateStaticPage` tanpa `template` menyimpan `default`; dengan `contact` menyimpan `contact`; nilai lain (`wide`, kosong string, array) ditolak `template_invalid` dan tidak membuat baris.
- AC-4 `UpdateStaticPage`: mengubah `template` berhasil; tidak mengirim `template` berarti tidak berubah; nilai tidak valid ditolak dan halaman tak berubah.
- AC-5 Slug `profil`, `visi-misi`, dan `kontak` bisa diubah lewat `UpdateStaticPage` (mis. `kontak` menjadi `hubungi-kami`) dengan validasi slug yang sama seperti halaman bebas (format, cadangan, bentrok tetap ditolak); halaman template `contact` tetap tampil di slug baru.
- AC-6 `DeleteStaticPage` berhasil untuk `profil`, `visi-misi`, dan `kontak`, dan tetap melepas rujukan menu utama dan tombol header (perilaku F043 tidak berubah); `/profil` setelahnya 404.
- AC-7 Portal: halaman `template=contact` (slug apa pun) terbit dengan data kontak → isi halaman + kartu kontak tampil; tanpa data kontak → 404; halaman `default` ber-slug `kontak` tidak menampilkan kartu kontak dan tidak 404 karena data kontak kosong.
- AC-8 Menu portal dan sitemap: halaman `contact` disembunyikan saat data kontak kosong dan tampil saat ada; halaman `default` ber-slug `kontak` diperlakukan seperti halaman biasa (tampil walau data kontak kosong, di sitemap bila berisi).
- AC-9 Halaman `profil` dan `visi-misi` tidak lagi memuat tautan "terkait" di isi halaman, dan membuka `/visi-misi` tidak menandai item Profil aktif di menu.
- AC-10 Policy: pengguna ber-permission `delete:static-page` boleh menghapus halaman mana pun; tanpa permission tidak (super-admin dan admin-konten, ditolak untuk peran lain).
- AC-11 Filament: form Edit halaman `profil` menampilkan slug yang bisa diubah (tidak disabled) dan Select template berisi tepat dua opsi berbahasa Indonesia; menyimpan template tersimpan; tabel Halaman tidak lagi menampilkan teks "Halaman sistem" dan aksi Hapus tersedia untuk semua baris.
- AC-12 Terjemahan: key baru ada dan tidak jatuh ke nama key; key lama yang dihapus tidak lagi dipakai kode (`SYSTEM_SLUGS`, `isSystem` tidak ada di `app/`, `database/`, `resources/`).

## Rencana test
Ubah test yang ada (hanya yang berubah perilaku), tambah sedikit:
- AC-1: `tests/Feature/Database/Migrations/AddTemplateToPagesTableTest.php` (pola `AddStatusToPagesTableTest`).
- AC-2: ubah `tests/Feature/Database/Seeders/KontenSeedersTest.php`.
- AC-3/4/5/6: ubah `tests/Feature/Actions/Konten/{Create,Update,Delete}StaticPageTest.php`; hapus/ubah kasus `slug_locked` dan `page_system_locked` di `F037ActionVerifyGapsTest.php`.
- AC-7/8/9: ubah `tests/Feature/Http/Portal/FreeStaticPageTest.php` (related, kontak), `PortalMenuTest.php`, `SeoTest.php`/`F037VerifyGapsTest.php` (sitemap, kontak, `SYSTEM_SLUGS` di baris 118/198), `GetPortalNavigationTest.php`.
- AC-10/11/12: ubah `tests/Feature/Filament/Resources/StaticPages/StaticPageResourceTest.php` dan `KontenTranslationsTest.php`.
- Pembersih: `tests/Pest.php` (`portalBoot`), `NoBuiltInContentTest`, factory `StaticPageFactory::system()` diganti `contact()`.
Jangan menambah test di luar daftar ini.

## Urutan implementasi
Paket A: data + Action (hijau: AddTemplateToPagesTableTest, KontenSeedersTest, `Actions/Konten/*StaticPage*Test`, GetPortalNavigationTest)
1. Migration `template` + konstanta/`isContact()` di model, factory `contact()`.
2. `CreateStaticPage`/`UpdateStaticPage`/`ValidatesStaticPageInput` (template, buka slug), `DeleteStaticPage`, policy.
3. Hapus `SYSTEM_SLUGS`/`isSystem()`; perbarui seeder, `DemoContent` (cek `$model === null` tanpa SYSTEM_SLUGS: kontak demo diberi `template=contact` bila baris dibuat/diubah lewat Action).
Paket B: portal (hijau: FreeStaticPageTest, PortalMenuTest, SeoTest, F037VerifyGapsTest, PortalRoutingTest)
4. `ShowPublicStaticPage`, `GetPortalNavigation`, `BuildSitemap`, view `pages/show`. Hapus `RELATED` dan `markVisionUnderProfile`.
Paket C: Filament + dokumen (hijau: StaticPageResourceTest, KontenTranslationsTest)
5. Form/tabel `StaticPageResource`, `lang/id`.
6. Perbarui `docs/erd.md`, `docs/prd.md`, komentar docblock. Pint. Cek tampilan form Halaman dan `/kontak` di 360px dan 1024px (checklist §0).

## Risiko & pertanyaan terbuka
- Risiko: banyak test lama mengasumsikan halaman sistem (±15 berkas); pembaruan harus selektif, bukan test baru.
- Risiko: lembaga yang sudah menghapus/menimpa halaman `kontak` sebelum migrasi: hanya baris ber-slug `kontak` yang diubah; lainnya biasa. Sederhana dan cukup.
- Pertanyaan 1 (user): apakah `StaticPageSeeder` tetap membuat tiga halaman awal (profil, visi-misi, kontak) di instalasi baru? Usulan: tetap, agar admin punya titik awal dan `kontak` langsung ber-template contact; alternatif: instalasi tanpa halaman (lebih generik, tetapi menu bawaan dan test `NoBuiltInContentTest` ikut berubah).
- Pertanyaan 2 (user): halaman `contact` boleh dibuat lebih dari satu? Usulan: boleh (tanpa batas unik), karena kasusnya tak merugikan dan tanpa kode tambahan.
- Pertanyaan 3: menghapus tautan "terkait" Profil/Visi & Misi diikuti audit; admin menulis tautan sendiri di isi. Setuju?

## Riwayat revisi
(belum ada)

## Review #1 — APPROVED (0 blocker, 0 major, 6 minor)

Catatan minor untuk implementasi:
1. Menu bawaan (`GetPortalNavigation::DEFAULT_PRIMARY`: `page:profil`, `page:kontak`) tetap terikat slug; diterima sebagai titik awal (YAGNI), tidak ada AC tambahan.
2. Daftar test terdampak hanya petunjuk awal: `grep` `SYSTEM_SLUGS|isSystem|slug_locked|page_system_locked|'kontak'|Halaman sistem` di `tests/` (mis. `DemoSeederTest`, `NavigationPageTest`, `F044VerifyGapsTest`, `AddPageToPrimaryNavigationTest`) dan sesuaikan hanya yang rusak.
3. `DemoContent.php:47` cukup menjadi `$model === null`; demo bergantung pada `StaticPageSeeder` untuk `kontak` ber-template `contact`.
4. Pertanyaan terbuka 1–3 diputuskan user/orkestrator sebelum RED (rekomendasi: seeder tetap, `contact` boleh lebih dari satu, tautan terkait dihapus).
5. Gabungkan varian input AC-3 jadi satu dataset; AC-12 jangan menguji string kode, cukup key lang baru.
6. Perbarui docblock usang (`DeleteStaticPage`, `StaticPage.php:13`, `UpdateStaticPage`, `StaticPageSeeder`) dan ERD baris ±799 & ±826, PRD baris 43.

## Keputusan user — 2026-10-17

1. `StaticPageSeeder` tetap membuat tiga halaman awal (profil, visi-misi, kontak) dengan isi kosong.
2. Lebih dari satu halaman boleh ber-template `contact`.
3. Tautan "terkait" Profil ↔ Visi & Misi dihapus; admin menulis tautan sendiri di isi halaman.
