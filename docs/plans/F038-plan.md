# F038 psb-info-publik — Halaman Info PSB publik (halaman bertemplate `psb-info`)

Issue: #14
Status rencana: REVISED
Jalur: penuh (data PSB dan tarif publik, kontak, template halaman). Sumber: `docs/audits/ui-ux-portal-konten.md` §6; revisi ruang lingkup disetujui user 20 Okt 2026.

## Ringkasan & tujuan
Info PSB = halaman statis ber-template `psb-info` (F048). Teks bebas admin (syarat umum, agenda, langkah daftar, fasilitas) ditulis di isi halaman biasa. Empat bagian otomatis dari data modul dirender template: status gelombang, syarat berkas per jenjang, biaya, kontak PSB. Tidak ada field teks khusus.

## Keputusan desain
1. **Mesin blok F045 tidak dipakai di halaman** (area blok hanya `home`/`footer`; area `page` butuh kolom, Builder, Normalize baru). "Blok generik" dipenuhi isi halaman (rich text). Empat bagian otomatis = partial Blade tetap. Tanpa tipe blok, tanpa migration (YAGNI).
2. **Route `psb.info`.** `GetPortalNavigation::system()` memakai `Route::has('psb.info')`. Grup portal bernama `portal.` (nama jadi `portal.psb.info`) dan grup `psb.` berprefiks `psb/`, jadi route didaftarkan **berdiri sendiri** di `routes/web.php`, setelah grup `psb.` dan **sebelum** grup `Route::name('portal.')` (yang memuat `{page}`): `Route::get('info-psb', ShowPsbInfo::class)->name('psb.info')` dengan `withoutMiddleware([...])` dan `middleware([ForcePortalRootUrl, SetPortalSecurityHeaders, SetPortalCacheHeaders, EnsureModuleIsEnabled::class.':psb,404'])` persis seperti grup portal (daftar `withoutMiddleware` disalin dari grup itu). Tidak bergantung modul konten.
3. **Halaman teks opsional.** Route selalu merender bagian otomatis; isi halaman tampil bila ada halaman slug `info-psb` bertemplate `psb-info` berstatus terbit. Halaman terbit menentukan `h1` (judulnya) **meski body kosong**; tanpa halaman, `h1` = label menu `psb_info`. Tanpa teks bawaan.
4. **Slug dikunci** (Create/Update):
   - Template akhir `psb-info`: Action **tidak memanggil** `ResolveStaticPageSlug` (yang menolak `info-psb` sebagai cadangan). Slug = `info-psb` (input slug diabaikan), lalu cek unik langsung `StaticPage::where('slug','info-psb')` (update: `whereKeyNot` diri sendiri) → `slug_taken`. Berlaku juga untuk halaman lain yang diubah ke `psb-info` (slugnya berganti ke `info-psb`). `UniqueConstraintViolationException` tetap → `slug_taken`.
   - Template diubah **dari** `psb-info` ke template lain: slug selalu divalidasi lewat `ResolveStaticPageSlug` (walau sama dengan yang tersimpan). Kandidat = `slug` input bila dikirim, selain itu `info-psb`; maka `info-psb` tertolak `slug_reserved` sampai admin mengisi slug baru (slug kosong = dibuat dari judul, perilaku lama).
   - Template lain dengan slug `info-psb` tetap `slug_reserved`.
5. **Kontak PSB** = item `school.contacts` bertanda baru `is_psb` (toggle sejajar `is_print` F044). Kontak dihapus otomatis hilang; bisa lebih dari satu.
6. **Brosur PDF di luar lingkup.**
7. **Sitemap & navigasi.** `BuildSitemap`: entri `route('psb.info')` ditambah satu kali di blok psb (bersama `psb.register`, sebelum return saat konten mati); loop halaman biasa mengecualikan halaman bertemplate `psb-info`. `GetPortalNavigation`: item `page:` yang menunjuk halaman bertemplate `psb-info` selalu ditolak (tujuannya sudah item `psb.info`); penanda active `psb.info` = `routeIs('psb.info')` (pola `system()` yang ada).
8. **Query (opsi KISS).** Tetap memakai `ResolveFeeRate` per (jenjang × jenis biaya), tanpa query massal (tidak menduplikasi aturan tarif). `openForRegistration()` tetap dipakai; `isFull()` menjalankan satu `count` per gelombang **berkuota** yang sedang berlangsung, diterima karena gelombang berkuota yang berlangsung bersamaan hanya sedikit. Bagian lain (gelombang mendatang, syarat + `unit`, kontak, halaman) query konstan dengan eager load.

## Ruang lingkup
- Termasuk: template `psb-info`; route `psb.info`; Action `GetPsbInfo`; view dan partial; tanda `is_psb`; navigasi/sitemap; lang `id`; demo (isi halaman fiktif opsional, sesuai manhaj); `docs/prd.md`, `docs/erd.md` (key `school.contacts`).
- Tidak termasuk (YAGNI): brosur PDF, tipe blok baru, blok di halaman lain, kuota/sisa kuota, jalur dan beasiswa, tombol Daftar khusus, teks bawaan, perbaikan data demo lama, perubahan skema, optimasi `isFull`/`ResolveFeeRate` massal.

## Model data
Tanpa migration. `pages.template` nilai baru `psb-info` (string, divalidasi Action). `school.contacts[].is_psb` bool (item lama tanpa kunci = `false`). Tanpa uang baru (hanya membaca `fee_rates.amount`).

## Action class
- `app/Actions/Konten/GetPsbInfo` (baru, murni baca) — output `{page: ?StaticPage, bodyHtml: ?HtmlString, waves, requirements, fees, contacts}`; tiap bagian `[]` bila kosong.
  - `waves`: hasil `AdmissionWave::openForRegistration(today)` berstatus "Dibuka", ditambah satu query `is_open` dengan `opens_on` > hari ini (with `academicYear`) berstatus "Akan dibuka". Tutup/lewat/penuh tidak tampil.
  - `requirements`: `admission_requirements` with `unit`, dikelompokkan per jenjang (`unit_id` null = "Semua jenjang" di depan), urut `sort_order`; nama + wajib/opsional. Tanpa `unit_user`.
  - `fees`: hanya bila `Setting::moduleEnabled('keuangan')` dan ada gelombang dibuka; bila tidak, tidak ada query ke `fee_rates`. Tahun ajaran dan `admission_wave_id` = gelombang dibuka pertama. Satu query jenjang + satu query `fee_types` aktif (`saat_daftar`, `saat_diterima`, `bulanan`), lalu `ResolveFeeRate` per pasangan. Tanpa tarif dilewati; semua kosong → `[]`.
  - `contacts`: item ber-`is_psb` (label, nama, telepon via `FormatPhoneForDisplay`, tautan WhatsApp bila `is_whatsapp`).
  - `page`/`bodyHtml`: halaman slug `info-psb`, template `psb-info`, terbit; isi disanitasi ulang (`SanitizeContentHtml`) seperti `ShowPublicStaticPage`; body kosong → `bodyHtml` null, `page` tetap.
- `UpdateSchoolContacts` (diubah) — `'is_psb' => filter_var($item['is_psb'] ?? false, FILTER_VALIDATE_BOOLEAN)`, pola sama `is_print`/`is_whatsapp`; tanpa batas jumlah. `GetSchoolProfile` membawa `is_psb`.
- `CreateStaticPage`, `UpdateStaticPage` (diubah) — sesuai Keputusan 4.
- `GetPortalNavigation`, `BuildSitemap` (diubah) — sesuai Keputusan 7.
- `app/Models/StaticPage` — `TEMPLATE_PSB_INFO`, masuk `TEMPLATES`, `isPsbInfo()`, konstanta `PSB_INFO_SLUG = 'info-psb'`.

## UI (Filament / Blade)
- `StaticPageResource`: opsi template "Info PSB" (`lang/id`), kolom daftar menampilkan template; helper: slug otomatis `info-psb`, bagian otomatis muncul di bawah isi.
- `app/Filament/Pages/Settings/Contacts.php`: Toggle `is_psb` per kontak, helper singkat.
- `app/Http/Controllers/Portal/ShowPsbInfo.php` → `resources/views/portal/psb/info.blade.php` (`x-layouts::public`, `bare`, `max-w-3xl` seperti `pages/show`). Urutan: breadcrumb, `h1` (Keputusan 3), gelombang, isi halaman, syarat, biaya, kontak. Bagian kosong tidak dirender; semuanya kosong → `x-ui.empty-state`. `h2` per bagian, daftar semantik, rupiah `Rp 1.500.000`, tanggal Indonesia, badge status (teks + warna token), target 44px, fokus `ring-accent-400`, 360px tanpa overflow. Tombol "Daftar" ke `psb.register` hanya bila ada gelombang dibuka.
- Wajib: `docs/design-guide.md` §0, §6, §10 dan skill `/anti-ui-slop`. Teks di `lang/id/pondok.php` (`portal.psb_info.*`, `static_page.templates.psb-info`, `school_profile.contact_is_psb*`).

## Permission & cakupan
Tidak ada permission baru (`update:static-page`, `view:school-profile` yang ada). Halaman publik tanpa login. Saklar `modules.psb.enabled` (route, menu, navigasi, sitemap) dan `modules.keuangan.enabled` (biaya). Tanpa `unit_user`.

## Kriteria penerimaan (bisa dites)
- **AC-1** Template & slug: Create `psb-info` dengan slug apa pun → slug `info-psb`; halaman kedua `psb-info` (create, atau update halaman lain ke `psb-info`) → `slug_taken`; update halaman `default` berslug `profil` ke `psb-info` → slug `info-psb`; update halaman `psb-info` ke `default` tanpa slug baru → `slug_reserved`, dengan slug `brosur` → tersimpan `brosur`; template `default` + slug `info-psb` → `slug_reserved`; template tak dikenal → `template_invalid`.
- **AC-2** Route: `route('psb.info')` berakhir `/info-psb`; GET → 200 saat psb aktif tanpa halaman maupun data; psb mati → 404 walau halaman terbit; konten mati → tetap 200; respons tanpa `Set-Cookie` dan header keamanan/cache sama seperti `/` (pola `PortalHeadersTest`); item menu `psb_info` ada dengan URL = `route('psb.info')` dan `active` true di halaman itu, hanya saat psb aktif.
- **AC-3** Instalasi baru (tanpa halaman, gelombang, syarat, tarif, kontak `is_psb`): satu `h1` dari label lang, `empty-state`, tanpa `h2` bagian, tanpa teks konten bawaan.
- **AC-4** Isi halaman: `info-psb`/`psb-info` terbit → judul jadi `h1` dan isi tersanitasi (`<script>`, `onerror`, `javascript:` tidak aktif); terbit dengan body kosong → judul tetap `h1`, tanpa blok isi; draf → judul dan isi tidak dipakai.
- **AC-5** Gelombang: berlangsung → "Dibuka" (nama, tahun ajaran, rentang tanggal); `is_open` dengan `opens_on` mendatang → "Akan dibuka"; penuh, `is_open` false, atau lewat → tidak tampil; tanpa gelombang → bagian hilang; tombol Daftar (`psb.register`) hanya bila ada yang dibuka.
- **AC-6** Syarat berkas: per jenjang, "Semua jenjang" lebih dulu, urut `sort_order`, wajib/opsional dibedakan dengan teks; kosong → bagian hilang; tidak terpengaruh `unit_user`.
- **AC-7** Biaya: keuangan aktif + gelombang dibuka → per jenjang jenis biaya aktif (`saat_daftar`, `saat_diterima`, `bulanan`) dengan nominal `ResolveFeeRate` untuk tahun ajaran dan gelombang dibuka pertama (tarif khusus gelombang menang atas umum); format rupiah bulat.
- **AC-8** Biaya tersembunyi: tanpa tarif, jenis nonaktif, frekuensi `tahunan`/`manual`, tanpa gelombang dibuka, atau keuangan mati → bagian tidak dirender; keuangan mati → tidak ada SQL yang memuat `fee_rates` di query log; jenis/jenjang tanpa tarif dilewati tanpa error.
- **AC-9** Kontak: hanya `is_psb` true (label, nama, nomor terformat, WhatsApp bila `is_whatsapp`); kontak dihapus tidak tampil; tanpa kontak bertanda → bagian hilang; item lama tanpa `is_psb` = false.
- **AC-10** `UpdateSchoolContacts` menyimpan `is_psb` (lebih dari satu boleh; `"0"`/`"false"`/kosong → false, `"1"`/`true` → true, seperti `is_print`); aturan lama tetap (`is_print` maks satu); log `school_contacts_updated` hanya bila berubah.
- **AC-11** Sitemap & navigasi: psb aktif → `route('psb.info')` muncul tepat satu kali, termasuk saat konten mati dan saat halaman `psb-info` terbit ber-body kosong maupun berisi (tidak muncul lewat jalur halaman biasa); psb mati → tidak ada. Navigasi: item `page:info-psb` yang menunjuk halaman `psb-info` tidak dirender (psb aktif maupun mati); item `psb.info` tidak terpengaruh konten mati.
- **AC-12** Admin: Select template memuat "Info PSB"; toggle `is_psb` di Kontak & sosial tersimpan lewat Action; akses peran tidak berubah (satu assert di test akses yang ada).
- **AC-13** Query & render: dengan data jenjang/jenis biaya tetap, menambah 5 gelombang tanpa kuota (dibuka/mendatang), 10 syarat, dan 3 kontak tidak menambah jumlah query (helper pola `dashboardQueryCount`); bagian biaya ≤ 2 + 2 × (jenjang × jenis biaya) query; satu `h1`, `h2` per bagian, tanpa `text-accent-*`.

## Rencana test
| AC | File | Test |
|---|---|---|
| 1 | `tests/Feature/Actions/Konten/CreateStaticPageTest.php`, `UpdateStaticPageTest.php` (tambah) | `forces info-psb slug`, `rejects second psb-info page`, `requires new slug when leaving psb-info` |
| 10 | `tests/Feature/Actions/Konten/UpdateSchoolContactsTest.php` (tambah) | `saves psb contact flag` |
| 11 | `tests/Feature/Actions/Konten/GetPortalNavigationTest.php`, `tests/Feature/Http/Portal/SeoTest.php` (tambah) | `rejects page item for psb-info page`, `lists psb info once in sitemap` |
| 2–9, 13 | `tests/Feature/Http/Portal/PsbInfoPageTest.php`, `tests/Feature/Actions/Konten/GetPsbInfoTest.php` (baru) | `renders without page or data`, `uses stateless portal headers`, `returns 404 when psb disabled`, `lists open and upcoming waves only`, `groups requirements by unit`, `resolves fees for open wave`, `hides fees and skips fee_rates when finance disabled`, `shows only psb flagged contacts`, `query count does not grow with waves` |
| 12 | `tests/Feature/Filament/Resources/StaticPages/StaticPageResourceTest.php`, `tests/Feature/Filament/Pages/InstitutionProfileTest.php`, `KontenAccessTest.php` (tambah) | `offers psb-info template`, `toggles psb contact flag` |

## Urutan implementasi
**Paket 1 — data + Action** (hijau: `GetPsbInfoTest`, Create/UpdateStaticPage, UpdateSchoolContacts, navigasi, Seo)
1. `StaticPage` konstanta; Create/UpdateStaticPage (Keputusan 4).
2. `is_psb` di `UpdateSchoolContacts`, `GetSchoolProfile`.
3. `GetPsbInfo`.
4. Route `psb.info` (perlu untuk navigasi/sitemap); `GetPortalNavigation`, `BuildSitemap`.

**Paket 2 — portal** (hijau: `PsbInfoPageTest`)
5. `ShowPsbInfo` + view/partial + `lang/id`; cek 360px, 1024px, desktop; checklist §0.

**Paket 3 — admin + docs** (hijau: `StaticPageResourceTest`, `InstitutionProfileTest`, `KontenAccessTest`)
6. Opsi template, toggle kontak; demo opsional; `docs/prd.md`, `docs/erd.md`; Pint.

## Risiko & pertanyaan terbuka
- Deviasi dari issue lama: tanpa field teks khusus, brosur PDF keluar, kontak memakai `is_psb`. Body issue perlu diperbarui.
- `isFull()` = satu query per gelombang berkuota yang berlangsung (diterima, Keputusan 8).
- Biaya memakai gelombang dibuka pertama bila beberapa tahun ajaran terbuka sekaligus; jarang.
- Tahunan/manual sengaja tidak tampil; ditambah bila diminta.
- Nama berkas test admin/akses dicek ulang red-tester terhadap repo.

## Riwayat revisi
- **Rev 1 (20 Okt 2026, menjawab Review #1):**
  - #1 blocker route → Keputusan 2 (route berdiri sendiri, nama `psb.info`, middleware stateless portal + `psb,404`, sebelum grup `{page}`); AC-2 assert URL, header, menu.
  - #2 slug → Keputusan 4 (tanpa `ResolveStaticPageSlug` untuk `psb-info`, cek unik langsung, keluar dari `psb-info` wajib slug baru); AC-1 diperluas.
  - #3 query → Keputusan 8 + AC-13 (tidak naik terhadap gelombang tanpa kuota/syarat/kontak; biaya ≤ 2 + 2×U×T; `isFull` diterima); AC-8 lewat query log.
  - #4 sitemap/navigasi → Keputusan 7; AC-11 mencakup konten mati, body kosong, item `page:` ditolak, active `psb.info`.
  - #5 → AC-2 URL menu; #6 → Keputusan 3 + AC-4; #7 → `filter_var` pola `is_print`, AC-10; #8 → AC-12 satu assert, helper query count yang ada; nama file test disesuaikan dengan repo.

## Review #1 — 2026-10-20

Verdict: CHANGES_REQUESTED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | blocker | Keputusan 2, route | Grup portal di `routes/web.php` memakai `Route::name('portal.')`. `->name('psb.info')` di dalam grup itu menjadi `portal.psb.info`, sehingga `Route::has('psb.info')` di `GetPortalNavigation::system()` tetap false dan menu `psb_info` tidak tampil (AC-2 gagal). Grup `psb.` berprefiks URL `psb/`, jadi tidak bisa dipakai untuk `/info-psb`. | Daftarkan route di luar grup bernama `portal.` tetapi dengan middleware yang sama (withoutMiddleware stateless + ForcePortalRootUrl/SetPortalSecurityHeaders/SetPortalCacheHeaders), nama persis `psb.info`, ditaruh sebelum grup yang memuat `{page}`. Tulis di rencana dan tambah AC yang meng-assert `route('psb.info')` = `/info-psb` dan header stateless/cache portal. |
| 2 | major | Slug dikunci (Keputusan 4), AC-1 | `ResolveStaticPageSlug::isReserved()` menolak `info-psb` (ada di `RESERVED_SLUGS`, dan setelah route ditambah juga jadi segmen route). Rencana tidak menyebut bagaimana Action memaksa slug tanpa lewat validasi itu; `slug_taken` untuk halaman kedua juga tidak jelas karena dalam rencana slug input diabaikan. Pemakaian `GenerateSlug` bisa memicu `info-psb-2`. | Tentukan eksplisit: untuk template `psb-info` Action tidak memanggil `ResolveStaticPageSlug`, hanya cek unik langsung (`where slug`, abaikan diri sendiri saat update) lalu lempar `slug_taken`. Update halaman lama ke `psb-info` ketika slug lama sudah berbeda juga harus tercakup (slug berubah ke `info-psb`; bila template diubah keluar dari `psb-info`, slug tetap `info-psb` yang dicadangkan, tentukan perilakunya: tolak atau wajib slug baru). |
| 3 | major | AC-13, GetPsbInfo `fees`/`waves` | "Query konstan terhadap jumlah jenjang/gelombang" bertentangan dengan desain. `ResolveFeeRate::handle()` menjalankan satu query per (jenis biaya x jenjang). `AdmissionWave::openForRegistration()` memanggil `isFull()` -> `countedApplicants()` per gelombang berkuota. Jadi query tumbuh dengan jumlah jenjang x jenis biaya dan jumlah gelombang. AC tidak bisa lulus apa adanya. | Pilih satu: (a) ubah AC menjadi batas wajar yang bisa dites (mis. asersi jumlah query tidak naik terhadap jumlah gelombang, dan fee dibatasi unit x tipe, dengan batas atas tertulis); atau (b) satu query massal untuk `fee_rates` dan tetap pakai aturan `ResolveFeeRate` (hati-hati duplikasi aturan; jangan DRY-breaking). Untuk gelombang, jelaskan cara menghindari N+1 `isFull` (mis. withCount atau terima biaya per gelombang berkuota). Pilihan paling KISS: (a). |
| 4 | major | AC-11, Keputusan 2 vs sitemap/navigasi | Rute `/info-psb` independen dari modul konten, tetapi `BuildSitemap` mengembalikan lebih awal bila `konten` mati, dan memuat halaman lewat `portal.pages.show` (juga menyaring halaman berbody kosong). `GetPortalNavigation` memuat `page:` hanya saat konten aktif. Maka "masuk sitemap dengan URL /info-psb tidak ganda" tidak terdefinisi saat konten mati atau body kosong, dan halaman bertemplate `psb-info` akan masuk sitemap lewat jalur halaman biasa bila lolos filter. `route('portal.pages.show','info-psb')` juga menghasilkan `/info-psb` yang sekarang dilayani `ShowPsbInfo`, dan penanda `active` memakai `routeIs('portal.pages.show')` sehingga salah. | Putuskan: sitemap menambah entri `psb.info` satu kali di blok `psb` (sebelum return konten-mati) dan halaman `psb-info` dikecualikan dari loop halaman biasa; di navigasi, item `page:` bertemplate `psb-info` ditolak seluruhnya (bukan hanya saat psb mati) karena tujuannya sudah item `psb.info`, atau gunakan URL `psb.info` untuknya. Perjelas AC-11 dengan skenario konten mati/body kosong. |
| 5 | minor | AC-2 | `GetPortalNavigation` default menyertakan `psb.info`, tetapi `Route::has` baru benar setelah temuan 1 diperbaiki; AC-2 perlu satu assert URL menu = `route('psb.info')`. | Tambahkan ke AC-2. |
| 6 | minor | Keputusan 3 / AC-4 | Isi halaman diambil dari slug `info-psb` bertemplate `psb-info`. Halaman `psb-info` bila terbit dengan body kosong tetap mengubah `h1` ke judul. | Tentukan: judul memakai halaman terbit meski body kosong (sudah implisit), cukup ditulis. |
| 7 | minor | AC-10 | "mengabaikan nilai non-bool menjadi false" perlu mengikuti cara `is_print`/`is_whatsapp` di `UpdateSchoolContacts` agar konsisten. | Salin pola validasi yang ada. |
| 8 | minor | Ukuran | Rencana ±90 baris isi, 13 AC (batas 8-15); AC-12 (akses peran) cukup satu assert di test yang ada. Rencana test AC-8 "tidak ada query ke fee_rates saat keuangan mati" butuh `DB::listen`; pastikan pola itu sudah dipakai di repo. | Catatan saja. |
