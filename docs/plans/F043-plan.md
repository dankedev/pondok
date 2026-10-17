# F043 konten-navigasi-footer — Navigasi portal sebagai daftar tautan dan footer generik

Status rencana: APPROVED (review #1, 2026-10-17)

## Ringkasan & tujuan
Paket 1 audit (`docs/audits/ui-ux-portal-konten.md` §5.2, §5.5, S1, S3, keputusan user §9). Menu F037 (daftar semua halaman + saklar, batas 8 yang memotong diam-diam) dan tautan cepat footer F036 diganti **satu model daftar tautan**. Footer dibuat generik tanpa field kutipan. Kontak satu sumber dan susunan menu admin ada di F044.

## Ruang lingkup
- Termasuk: model tautan + pemilih tujuan; menu utama dengan submenu satu tingkat; navigasi footer; tombol header opsional; aksi "Tambahkan ke menu utama" di edit halaman; footer generik + varian ringkas (route `psb.*` dan 404); migrasi data settings; data demo; pembaruan `docs/erd.md` (key settings).
- Tidak termasuk (eksplisit): route/halaman Info PSB (F038; tujuan `psb.info` disiapkan tetapi tidak dirender sampai route ada); blok Kutipan/Ajakan beranda (paket 2, isi kutipan hanya diarsipkan); tautan "Masuk" header (tetap seperti sekarang); `/berita/kategori/{slug}` (kategori memakai `/berita?kategori=`); ikon sosial; pemindahan halaman admin ke grup Situs (F044); kontak/telepon kantor (F044).

## Model data
Tanpa perubahan skema tabel. Key `settings` (JSON) baru, dibaca lewat `GetSchoolProfile::KEYS` (tetap satu query):

| Key | Isi | Nilai null / tidak ada |
|---|---|---|
| `portal.navigation_primary` | list Item (maks. 8 akar; grup maks. 8 anak) | menu bawaan F037 (lihat K3) |
| `portal.navigation_footer` | list Item tanpa grup (maks. 12) | `[psb.status, letters.verify]` |
| `portal.header_button` | satu Item tanpa grup, atau `false` = tanpa tombol | `{type: psb.register}` |
| `portal.footer_text` | `{title: ?string ≤60, body: string ≤300}` teks polos | tidak dirender |
| `portal.footer_show_contacts`, `portal.footer_show_social` | bool | `true` |
| `portal.archived_footer_quote` | `{arabic, translation, source}` (tidak dibaca aplikasi) | — |

**Item** = `{type, label: ?string, page_id?, category_id?, url?, children?}`. `type` ∈ `home`, `page`, `posts`, `category`, `albums`, `videos`, `psb.info`, `psb.register`, `psb.status`, `letters.verify`, `url`, `group` (grup hanya di akar menu utama; `children` 1–8 item non-grup). `label` wajib untuk `url` dan `group` (≤30 menu/tombol, ≤60 footer); selain itu opsional dengan fallback: judul halaman, nama kategori, atau label `lang/id` (`pondok.portal.nav.*`).
Key yang dihapus: `portal.menu_items`, `portal.menu_show_register`, `portal.footer_links`, `portal.footer_quote_arabic|translation|source`. `portal.footer_description` tetap.

**Migrasi** `database/migrations/2026_10_19_000000_move_portal_navigation_settings.php` (pola `move_school_profile_settings`: `DB::table`, JSON di-decode sendiri, nilai rusak = kosong, satu transaksi):
- `menu_items` berupa array → `navigation_primary` = item yang **tampil** menurut `mergedItems()` lama (dedup, maks. 8 tampil, bawaan yang belum tercantum ditambahkan selama < 8), dipetakan `home→home`, `news→posts`, `gallery→albums`, `videos→videos`, `psb_info→psb.info`, `page→page` (label dibawa; page_id yang tidak ada di `pages` dibuang). Logika lama disalin ke migrasi, tidak memanggil kelas aplikasi. `menu_items` null → key baru tidak ditulis (bawaan).
- `menu_show_register === false` → `header_button = false`; selain itu tidak ditulis.
- `footer_links` berisi → `navigation_footer` = tautan lama (url/page) + `psb.status` + `letters.verify`; null → tidak ditulis.
- Salah satu field kutipan terisi → `archived_footer_quote`.
- Key lama dihapus. Key baru yang sudah ada tidak ditimpa (idempotent; jalan kedua tanpa perubahan).
- `down()`: kebalikan yang wajar (lossy, didokumentasikan): item `home/posts/albums/videos/psb.info/page` (anak grup diratakan) → `menu_items` visible (maks. 8); footer `url/page` → `footer_links` (maks. 8); `header_button === false` → `menu_show_register = false`; arsip → tiga key kutipan; key baru dihapus.

## Action class
- `app/Actions/Konten/GetPortalNavigation` (ditulis ulang) — input profil `GetSchoolProfile`; output `{primary: list<Link|Group>, headerButton: ?Link, footer: list<Link>}`; `Link = {label, url, active}`, `Group = {label, children: list<Link>, active}`. Item rusak dibuang per item saat baca. Ketersediaan: `home` selalu; `page` = modul konten + halaman terbit (+ aturan `kontak` butuh data kontak F037); `posts/albums/videos/category` = modul konten + minimal satu item publik (kategori: ada, `type=post`, punya berita terbit); `psb.*` = modul psb + `Route::has`; `letters.verify` = modul dokumen + route; `url` = aman (`isSafeAbsoluteUrl`/`isSafeInternalPath`). Kategori → `route('portal.posts.index', ['kategori' => slug])`. Penanda aktif F037 (termasuk Visi-misi → Profil) dipertahankan; grup aktif bila salah satu anak aktif. Konstanta katalog tujuan dan batas ada di sini.
- `app/Actions/Konten/UpdatePortalNavigation` (menggantikan `UpdatePortalMenu`, yang dihapus) — input `{primary, footer, header_button}` + actor; validasi penuh sebelum menulis (tipe, rujukan `page_id`/`category_id` harus ada — draf boleh, URL aman ≤255, label, batas, grup bersarang/di footer/kosong ditolak); `[]` disimpan `[]`; log `portal_navigation_updated` lewat `SavesProfileSettings`.
- `app/Actions/Konten/UpdatePortalFooter` (diubah) — `footer_description`, `footer_text` (body kosong → null, title tanpa body ditolak), dua saklar; log `portal_footer_updated`. Field kutipan dan tautan dihapus dari input.
- `app/Actions/Konten/AddPageToPrimaryNavigation` (baru) — input `StaticPage`, actor; bila halaman sudah dirujuk di menu utama (akar atau anak) → hasil `already_listed` tanpa menulis; bila akar sudah 8 → `ValidationException`; selain itu menambah `{type: page, page_id, label: null}` di akhir (menu null dimaterialisasi dulu dari bawaan).
- `app/Actions/Konten/DeleteStaticPage` (diubah) — `REFERENCE_KEYS` diganti: buang rujukan halaman dari primary (akar dan anak; grup yang jadi kosong ikut dibuang), footer, dan `header_button` (→ `false`).

## UI (Filament / Blade)
- Halaman cluster `Menu` diganti `Navigation` (slug `navigation`, label "Navigasi"): section Menu utama (Repeater: Select tujuan, field kondisional halaman/kategori/URL, label; Repeater anak bila tujuan "Grup submenu"), Tombol header (pilihan "Tanpa tombol" + tujuan + label), Navigasi footer. Form diisi dari daftar efektif (bawaan dimaterialisasi). Galat Action dipetakan ke baris repeater bersarang. Opsi Info PSB diberi helper "tampil setelah halaman Info PSB tersedia".
- Halaman `Footer`: section Deskripsi, Teks pendek (judul, isi), Tampilkan (kontak, media sosial). Section kutipan dan tautan dihapus.
- `EditStaticPage`: header action "Tambahkan ke menu utama" (tampil bila `view:school-profile`), notifikasi sukses / "sudah ada di menu" / galat batas.
- Blade: `portal/partials/nav-links.blade.php` (grup = `<details>`/`<summary>` di desktop, judul + anak berindentasi di seluler; tombol header memakai gaya tombol Daftar saat ini), `portal/partials/footer.blade.php` (tile putih logo mobile→desktop, nama, deskripsi, kontak dan sosial sesuai saklar, kolom navigasi footer berjudul "Tautan", teks pendek, hak cipta; tidak ada tautan sistem tersembunyi), `layouts/public.blade.php` prop `compactFooter` (otomatis untuk `psb.*`, diset di `errors/404`). Footer ringkas: nama, navigasi footer sebaris, hak cipta.
- Teks baru di `lang/id/pondok.php` (`school_profile.navigation_*`, `portal.nav.*` untuk label bawaan tujuan, pesan galat). Design guide §0, §6 (target sentuh 44px, `rounded-full` item nav, `rounded-xl` menu lipat, token warna), §10.

## Permission & cakupan
Tidak ada permission baru. Halaman Navigasi/Footer dan aksi halaman memakai `view:school-profile` (super-admin, admin-sekolah, admin-konten) seperti F036/F037. Tanpa `unit_user`. Tidak terikat saklar modul; ketersediaan tujuan mengikuti `modules.konten|psb|dokumen.enabled`.

## Kriteria penerimaan (bisa dites)
- **AC-1** Migrasi menu: untuk (a) menu kustom dengan item tersembunyi, label halaman, dan bawaan yang belum tercantum, (b) menu > 8 tampil hasil data lama → setelah migrasi `navigation_primary` hanya berisi item tampil, dan header portal menampilkan label, URL, dan urutan yang sama dengan sebelum migrasi; `menu_items` null → key baru tidak dibuat dan header tetap sama.
- **AC-2** Migrasi footer, tombol, kutipan: tautan lama + `psb.status` + `letters.verify` berurutan; `menu_show_register=false` → tanpa tombol; kutipan masuk `archived_footer_quote`; semua key lama terhapus; jalan kedua tidak mengubah apa pun dan tidak menimpa key baru yang sudah ada; `down()` memulihkan key lama (item yang tidak terpetakan dibuang).
- **AC-3** Bawaan: tanpa key navigasi → menu bawaan F037, footer berisi Cek status pendaftaran dan Verifikasi keaslian surat (bila modulnya aktif), tombol Daftar ke `psb.register`; `navigation_primary=[]` → tidak ada item menu; `header_button=false` → tanpa tombol walau PSB aktif.
- **AC-4** Simpan valid: setiap tipe tujuan (termasuk grup dengan anak, halaman draf, URL `https://` dan path `/berita`) tersimpan ternormalisasi sesuai urutan input; satu activity log `portal_navigation_updated` berisi key yang berubah; simpan ulang tanpa perubahan tanpa log.
- **AC-5** Simpan tidak valid → `ValidationException` pada field yang tepat dan settings tidak berubah: tipe tak dikenal; `page_id`/`category_id` tidak ada; URL `javascript:`, `data:`, `//host`, > 255; label kosong untuk `url`/`group`; label terlalu panjang; grup bersarang, grup tanpa anak, grup di footer/tombol; akar > 8, anak > 8, footer > 12 (pesan menyebut batas; tidak ada pemotongan).
- **AC-6** Ketersediaan & label: item tidak dirender bila modul terkait mati, halaman draf/terhapus, `kontak` tanpa data kontak, kategori hilang/tanpa berita terbit, daftar berita/galeri/video kosong, atau route `psb.info` belum ada (dan tampil ketika route `psb.info` didaftarkan dalam test); grup tanpa anak tersedia tidak dirender; label kosong memakai fallback; kategori menuju `/berita?kategori=<slug>`.
- **AC-7** Header: urutan desktop dan seluler sama; grup dirender sebagai `<details>` berisi tautan anak; tautan aktif ber-`aria-current="page"` dan grup induknya ditandai aktif; tombol header memakai label/tujuan tersimpan dan tidak dirender bila tujuannya tidak tersedia; label dan URL di-escape.
- **AC-8** Query: menyusun navigasi menambah paling banyak 3 query (halaman, kategori, cek isi daftar) dan 0 query bila hanya tujuan sistem/URL; profil + navigasi tetap memakai satu query settings.
- **AC-9** Aksi "Tambahkan ke menu utama": menambah halaman di akhir menu utama (menu bawaan dimaterialisasi lebih dulu sehingga item lain tetap); halaman yang sudah ada (akar atau anak) tidak digandakan; menu penuh → notifikasi galat tanpa menulis; aksi tidak tampil/403 untuk peran tanpa `view:school-profile`.
- **AC-10** Hapus halaman membuang rujukannya dari menu utama (akar dan anak; grup kosong ikut terbuang), footer, dan tombol header.
- **AC-11** Footer generik: logo, nama, deskripsi, kontak, sosial, navigasi footer, teks pendek, dan hak cipta tampil sesuai isian; saklar kontak/sosial off menyembunyikannya; tidak ada markup kutipan walau `archived_footer_quote` terisi; bagian kosong tidak dirender; teks pendek di-escape dengan baris baru dipertahankan.
- **AC-12** Footer ringkas pada `/psb`, `/psb/status`, dan 404: hanya nama, navigasi footer, dan hak cipta (tanpa deskripsi, kontak, sosial, teks pendek); halaman portal lain memakai footer penuh.
- **AC-13** Admin: halaman Navigasi dan Footer render untuk super-admin, admin-sekolah, admin-konten (403 untuk peran lain); form terisi dari settings (termasuk bawaan); simpan lewat form memanggil Action dan galat baris bersarang tampil di field yang benar.
- **AC-14** Tanpa konten bawaan & demo: instalasi baru tanpa DemoSeeder tidak punya `footer_text` dan tidak menampilkan kutipan; DemoSeeder (dua kali, idempotent) mengisi menu utama dengan satu grup halaman profil, footer, dan tombol header berisi contoh fiktif tanpa kutipan.

## Rencana test
| AC | File | Test yang diusulkan |
|---|---|---|
| 1–2 | `tests/Feature/Database/Migrations/MovePortalNavigationSettingsTest.php` | `migrates visible menu items keeping header output`, `keeps default menu when menu_items is null`, `migrates footer links button and quote archive`, `is idempotent`, `rolls back to legacy keys` |
| 3, 6, 8 | `tests/Feature/Actions/Konten/GetPortalNavigationTest.php` | `uses defaults when keys are missing`, `hides unavailable targets` (dataset), `falls back labels`, `query budget` |
| 4–5 | `tests/Feature/Actions/Konten/UpdatePortalNavigationTest.php` | `saves every target type`, `rejects invalid items` (dataset), `rejects lists over the limit` |
| 9–10 | `tests/Feature/Actions/Konten/AddPageToPrimaryNavigationTest.php`, `DeleteStaticPageTest.php` (tambah) | `appends page once`, `rejects when full`, `removes page references from navigation` |
| 7, 11–12 | `tests/Feature/Http/Portal/PortalNavigationTest.php`, `PortalFooterTest.php` | `renders groups and header button`, `renders generic footer`, `uses compact footer on psb and 404` |
| 13, 9 (UI) | `tests/Feature/Filament/Pages/NavigationPageTest.php`, `FooterPageTest.php` | `access per role`, `fills and saves`, `maps nested errors`, `add to menu action` |
| 14 | `tests/Feature/Http/Portal/NoBuiltInContentTest.php`, `tests/Feature/Database/Seeders/DemoSeederTest.php` (tambah) | `no quote on fresh install`, `demo navigation idempotent` |

Test lama yang mengunci perilaku usang ditulis ulang oleh red-tester di fase RED (bukan green-coder): `UpdatePortalMenuTest`, `UpdatePortalFooterTest`, `PortalMenuTest`, `InstitutionMenuTest`, `InstitutionFooterContactTest`, `F037VerifyGapsTest`, `F037ActionVerifyGapsTest`, `InstitutionVerifyGapsTest`, `KontenVerifyGapsTest`, `GetSchoolProfileTest`, `AddStatusToPagesTableTest`. Penghapusan file test utuh (mis. `UpdatePortalMenuTest`) perlu persetujuan user.

## Urutan implementasi
**Paket A — data + Action** (hijau: `MovePortalNavigationSettingsTest`, `GetPortalNavigationTest`, `UpdatePortalNavigationTest`, `AddPageToPrimaryNavigationTest`, `DeleteStaticPageTest`, `UpdatePortalFooterTest`, `GetSchoolProfileTest`)
1. Migrasi + test migrasi. 2. `GetSchoolProfile::KEYS` dan bentuk `footer`. 3. `GetPortalNavigation` baru. 4. `UpdatePortalNavigation` (hapus `UpdatePortalMenu`). 5. `UpdatePortalFooter`. 6. `AddPageToPrimaryNavigation`. 7. `DeleteStaticPage`.

**Paket B — portal Blade** (hijau: `PortalNavigationTest`, `PortalFooterTest`, `NoBuiltInContentTest`, test portal lama yang ditulis ulang)
8. `nav-links` + layout (grup, tombol header). 9. `footer` generik + prop `compactFooter` + 404 + PSB. 10. Teks `lang/id`. Cek 360/1024/desktop.

**Paket C — admin + demo + dokumen** (hijau: `NavigationPageTest`, `FooterPageTest`, `DemoSeederTest`, `KontenSeedersTest`)
11. Halaman `Navigation` (ganti `Menu`) dan `Footer`. 12. Aksi di `EditStaticPage`. 13. `DemoData`/`DemoContent` (menu bergrup, footer tanpa kutipan). 14. `docs/erd.md` key settings + PRD baris menu. 15. Pint.

## Risiko & pertanyaan terbuka
- **Q1 (user):** isi footer ringkas. Usulan bawaan: nama + navigasi footer + hak cipta (tanpa kontak). Alternatif: sertakan kontak agar pendaftar PSB mudah bertanya.
- **Q2 (user):** item menu yang tersembunyi di F037 tidak dimigrasikan (tidak pernah tampil; halaman tetap bisa dipilih lagi). Usulan: diterima.
- **Q3:** batas akar menu 8 (bukan 7 seperti audit) supaya menu lama 8 item termigrasi tanpa kehilangan. Usulan: 8.
- Risiko: menu 8 item + grup di 1024px bisa sesak; header tetap memakai menu lipat di bawah `lg` (seperti sekarang).
- Risiko: logo di footer navy; dipakai tile putih agar logo gelap tetap terbaca.
- Risiko: cek "daftar kosong" menambah query; dibatasi AC-8 dan hanya dijalankan bila tujuan itu dipakai.
- Minor: `markVisionUnderProfile` tetap ada; bisa dihapus di paket berikut bila grup submenu sudah menggantikannya.

## Riwayat revisi
- 17 Okt 2026: draf awal (product-manager, sesi orchestrator-bc5d2383).

## Review #1 — 2026-10-17

Verdict: APPROVED

Pemeriksaan: migrasi memakai pola `move_school_profile_settings` (DB::table, JSON di-decode sendiri, satu transaksi) dan urutan footer lama (tautan admin lalu `psb.status`, `letters.verify`, lihat `resources/views/portal/partials/footer.blade.php`) sudah sama dengan hasil migrasi. `Setting::get` mengembalikan `false` dan `[]` apa adanya (`?? $default` hanya untuk null), jadi `header_button = false` dan `navigation_primary = []` aman dibedakan dari "tidak ada". Batas 8 (bukan 7), tujuan tambahan `home` dan `psb.register`, serta bawaan tanpa baris settings masuk akal dan selaras dengan keputusan user (tanpa konten bawaan, kutipan hanya diarsipkan).

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | Model data (baris `navigation_primary`) | Rujukan "lihat K3" tidak ada di dokumen. Menu bawaan memakai halaman `profil` dan `kontak` menurut slug (`DEFAULT_ORDER` di kode lama), sedangkan Item baru merujuk `page_id`. Cara bawaan di-resolve (runtime per slug) dan dimaterialisasi (form Navigasi, aksi Tambahkan; halaman tidak ada dilewati) belum tertulis. | Ganti "lihat K3" dengan pernyataan: bawaan = `home, page:profil, posts, albums, videos, psb.info, page:kontak`, halaman dicari per slug saat baca, dan saat dimaterialisasi hanya halaman yang ada. Masukkan ke test `uses defaults when keys are missing`. |
| 2 | minor | Model data (label) | Fallback label halaman di menu/tombol harus tetap `Str::limit(title, 30)` seperti `GetPortalNavigation::pageLink` lama, dan di footer `title` tanpa batas (kode footer lama). Rencana hanya menulis "judul halaman". Bila beda, AC-1 (header sama persis setelah migrasi) bisa gagal pada judul panjang. | Tulis aturan fallback per area (menu 30 karakter, footer apa adanya) dan uji di AC-1. |
| 3 | minor | Action `AddPageToPrimaryNavigation` / UI | Otorisasi hanya lewat visibilitas action Filament; Action tidak memeriksa `view:school-profile` (konsisten dengan Action profil lain, tetapi halaman edit halaman bisa diakses peran yang punya izin halaman saja). | Di callback action Filament, `abort_unless(can('view:school-profile'), 403)` seperti `InstitutionProfilePage::save`, dan uji pemanggilan oleh peran tanpa izin (AC-9). |
| 4 | minor | Action `GetPortalNavigation` | Penanda `active` untuk tipe `category`, `url`, dan `psb.*` tidak ditentukan; tautan ganda ke tujuan yang sama diperbolehkan tanpa aturan. | Putuskan saat implementasi: kategori aktif bila `request('kategori')` sama; `url` aktif bila path sama. Duplikat dibiarkan (admin yang mengatur), cukup dicatat. |
| 5 | minor | Ukuran | Panjang baris wajar, 14 AC, tetapi AC-1/2/5/6 berisi banyak asersi gabungan, dan 11 file test lama harus ditulis ulang. Masih dalam batas jalur penuh. | Red-tester memecah per kasus di dalam test (dataset), jangan menambah AC baru. |
| 6 | minor | Risiko Q1 | Footer ringkas tanpa kontak mungkin menyulitkan calon pendaftar PSB. | Biarkan bawaan usulan; user memutuskan, mudah diubah di Blade. |

Tidak ada blocker maupun major.
