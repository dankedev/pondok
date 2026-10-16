# F036 konten-profil-lembaga — Profil lembaga generik untuk portal

Status rencana: APPROVED

## Ringkasan & tujuan

Profil sekolah F016 hanya punya 10 field (`school.name`, `tagline`, `address`, `phone`, `whatsapp`, `email`, tiga URL media sosial, `logo_path`). Itu cukup untuk Al-Khair, tetapi tidak untuk lembaga lain yang memakai Pondok. F036 mengganti field tersebut dengan profil lembaga generik yang diisi dari panel tanpa mengubah kode:

- identitas dengan logo cetak, logo desktop, logo mobile, dan favicon;
- kontak dan media sosial berupa daftar berlabel, ditambah tautan peta;
- isi beranda: sambutan, keunggulan, slider album, video profil, video PSB;
- footer: kutipan ayat, deskripsi, tautan cepat.

Portal F017 membaca semua field ini. Bagian yang kosong tidak dirender.

Permintaan user (16 Okt 2026) dipecah menjadi tiga fitur:

| ID | Key | Isi |
|---|---|---|
| **F036** (rencana ini) | `konten-profil-lembaga` | Butir 1–7: identitas, sambutan, lokasi & kontak, media sosial, keunggulan, media beranda, footer |
| F037 | `konten-halaman-menu` | Butir 8: halaman statis bebas dan urutan menu |
| F038 | `psb-info-publik` | Butir 9: halaman Info PSB hibrida |

Alasan pemisahan: F037 mengubah routing `/{page}` dan skema `pages`. F038 membaca modul PSB, keuangan, dan konten dengan aturan biaya sendiri. F036 adalah fondasi bagi keduanya: F038 merujuk daftar kontak F036, dan F037 menyusun menu dari halaman yang memakai header/footer F036. F035 (`portal-identitas-visual`, worktree sesi lain) juga bergantung pada F036.

**Waktu mulai.** `depends_on` F036 memuat F034 (`inti-seeder-demo`), karena AC-59 mengubah berkas DemoSeeder yang sedang direvisi F034. Fase RED/GREEN F036 baru dimulai setelah dua hal terpenuhi: user menyetujui rencana ini, dan F034 berstatus `done` (setelah user meng-commit revisinya). Isi demo (AC-59) tetap bagian F036, tanpa entri terpisah.

## Prinsip: tanpa teks konten bawaan (keputusan user 16 Okt 2026)

> "buat isianya jangan kita yang nentukan, misal basmalah itu dibuat isian aja jangan jadi patokan"

- **Teks konten** tidak boleh berasal dari aplikasi. Termasuk di sini:
  - kalimat pembuka (basmalah, salam) dan sambutan;
  - ayat atau kutipan, serta deskripsi lembaga;
  - judul seksi yang bersifat konten (mis. "Mengapa memilih ...", "Mengenal kami");
  - kalimat PSB seperti "dibuka sepanjang tahun".

  Semuanya kolom isian admin dengan nilai awal `null`. Bagian yang kosong tidak dirender.
- Yang tetap dari `lang/id` hanya **teks antarmuka generik**: navigasi, tombol, label form, label aksesibilitas, nama platform media sosial, judul seksi modul yang deskriptif, dan pesan kosong/error.
- Isi khas Al-Khair (basmalah, "Ahlan wa Sahlan", QS. Ali 'Imran: 104, keunggulan dari brosur) **hanya** diisi DemoSeeder sebagai contoh. `SettingSeeder`, `InstallSeeder`, dan migrasi tidak mengisinya.
- Fallback ke **nama** dan **tagline** lembaga tetap boleh, karena keduanya data isian admin, bukan teks aplikasi.
- Placeholder dan helper text di form admin tidak memuat contoh Arab, ayat, atau salam. Helper hanya menjelaskan pemakaian field.
- F038 wajib memakai prinsip yang sama (sudah tertulis di acceptance F038).

### Audit teks portal (`pondok.portal.*` dan Blade portal)

Hasil grep Blade portal: semua teks lewat `__()`, tidak ada teks hardcode.

| Kunci | Kelas | Tindakan |
|---|---|---|
| `nav.*` (Beranda, Profil, Visi & misi, Berita, Galeri, Video, Kontak, Daftar, Masuk, Menu, label navigasi, lewati ke isi) | antarmuka | tetap |
| `meta.title`, `meta.home_title` (pola `:title — :name`) | antarmuka (hanya menyusun data admin) | tetap |
| `meta.default_description` ("Situs resmi :name: kabar, kegiatan, ...") | **konten** | **dihapus**. Fallback: tagline, lalu `portal.footer_description`, lalu tanpa tag |
| `home.profile_title` ("Mengenal kami") | **konten** | **dihapus**. Judul diambil dari judul halaman `profil` (`profileTitle`) |
| `home.psb_open_badge`, `home.psb_cta`, `home.read_news`, `home.read_profile`, `home.see_all*` | antarmuka (status dan tombol) | tetap |
| `home.latest_news`, `latest_achievements`, `featured_videos`, `latest_albums` | antarmuka (judul seksi modul, deskriptif) | tetap |
| `posts.*`, `albums.*`, `videos.*`, `pages.empty`, `errors.*`, `pagination*` | antarmuka | tetap |
| `contact.title` ("Hubungi kami") | antarmuka (judul kartu kontak) | **diganti** "Informasi kontak" agar netral tanpa sapaan lembaga |
| `contact.address`, `phone`, `email`, `social` | antarmuka | tetap |
| `contact.whatsapp`, `contact.instagram`, `contact.facebook`, `contact.youtube` | antarmuka | dipindah ke `portal.social.<platform>` dan `portal.contact.whatsapp_label` (label sr). Kunci yang tidak terpakai dihapus |
| `footer.links`, `footer.psb_status`, `footer.verify_letter`, `footer.rights` | antarmuka | tetap |
| Baru: `contact.map_link` ("Lihat di peta"), `contact.opens_new_tab`, `portal.social.*` | antarmuka | ditambah. Label aria slider diambil dari judul album (wajib di F016), jadi tidak perlu kunci baru |

`static_page.defaults` (judul halaman bawaan "Profil", "Visi & Misi", "Kontak" dari `StaticPageSeeder`) **dianggap teks antarmuka**. Alasannya, judul ini sama dengan label navigasi, bisa diubah admin, dan tidak berisi pernyataan lembaga. Isi (`body`) halaman di-seed kosong, sehingga di instalasi baru seksi profil beranda tidak tampil dan halaman `profil`/`kontak` hanya menampilkan empty-state generik. Seeder tidak diubah.

## Ruang lingkup

Termasuk:
- Key `settings` baru, `Setting::getMany()`, dan perluasan `GetSchoolProfile` (satu query).
- Migrasi data: kontak WA dan URL sosial lama dipindah ke daftar baru, dan logo cetak lama disalin menjadi logo desktop bila logo desktop kosong. Migrasi bisa dibalik.
- Empat Action simpan, ditambah perubahan `GetPortalHomeData`.
- Cluster Filament "Profil lembaga" dengan empat halaman. `SchoolProfile` dipindah ke cluster. Cluster didaftarkan di `AdminPanelProvider` (`discoverClusters`).
- `RolePermissionSeeder`: `view:school-profile` juga diberikan ke `admin-konten` (keputusan user).
- Portal:
  - `layouts/public.blade.php`: header `<picture>`, favicon, meta description;
  - `portal/home.blade.php`;
  - `portal/partials/footer.blade.php` dan `portal/partials/contact-list.blade.php`;
  - `portal/pages/show.blade.php`: `$hasContact` halaman `kontak` saat ini membaca `$school['whatsapp']` (temuan review);
  - `lang/id/pondok.php` (audit di atas).
- DemoSeeder: field baru diisi contoh Al-Khair, termasuk logo dari `docs/brand/` (AC-59).
- `docs/erd.md` bagian Konten: daftar key profil dan hubungan logo cetak dengan logo desktop.

Tidak termasuk (eksplisit):
- Halaman statis bebas dan urutan menu: **F037**.
- Halaman Info PSB beserta isinya: **F038**.
- Tema (warna, font, template portal): ditunda **tanpa entri baru**. F035 menangani identitas visual, dan tema yang bisa diatur admin bertabrakan dengan token design-guide §2. F036 tidak menyentuh token warna maupun font.
- Peta tertanam. Keputusan user: hanya tautan "Lihat di peta", tanpa iframe, tanpa cookie atau skrip Google, dan CSP tidak dilonggarkan.
- Favicon panel admin Filament.
- Kode sekolah penomoran surat (`LetterNumbering::defaultSchoolCode`) tetap dari `school.name`.

## Model data

### Keputusan penyimpanan: `settings` (key-value JSON), bukan tabel baru

- Pondok single-tenant, jadi profil hanya satu set data. Tabel satu baris tidak memberi manfaat relasional.
- `settings.value` adalah kolom `json` nullable dengan cast `json` di model, portabel di SQLite, MySQL, dan Postgres. Daftar dibaca dan ditulis utuh, tanpa query ke dalam JSON.
- Key lama `school.name`, `tagline`, `address`, `phone`, `email`, dan `logo_path` dipakai kop surat F014 (`IssueLetter`, `PrintIssuedLetter`, `LetterNumbering`), kuitansi F007 (`ShowPaymentReceipt`), SEO F017, dan DemoSeeder. Key ini **dipertahankan dengan nama yang sama**, sehingga kode cetak tidak berubah.
- `Setting::getMany()` memuat semua key profil dengan **satu query** dan mengisi cache scoped F033.
- Rujukan ke `albums.id` dan `videos.id` disimpan sebagai angka tanpa FK dan dibaca secara toleran: rujukan hilang berarti bagian itu tidak dirender. Album dan video tetap boleh dihapus seperti di F016.

### Daftar key

Prefix `school.*` dipakai untuk identitas dan kontak, `portal.*` untuk tampilan portal. Nilai kosong dan daftar kosong disimpan `null`. Tidak ada nilai bawaan selain `school.name` dari `SettingSeeder`.

| Key | Tipe | Batas / aturan | Status |
|---|---|---|---|
| `school.name` | string | wajib, maks 100 | tetap |
| `school.short_name` | string | maks 30 | baru |
| `school.arabic_name` | string | maks 150 | baru |
| `school.tagline` | string | maks 150 | tetap |
| `school.logo_path` | path `public` | **logo cetak**, JPG/PNG maks 1024 KB | tetap (label diubah) |
| `school.logo_desktop_path` | path | JPG/PNG maks 1024 KB, boleh lebar | baru |
| `school.logo_mobile_path` | path | JPG/PNG maks 1024 KB | baru |
| `school.favicon_path` | path | **PNG saja**, maks 256 KB | baru |
| `school.address` | string | maks 500 | tetap |
| `school.phone` | string | telepon kantor, `^\+?[0-9]{8,15}$` setelah normalisasi; dipakai cetak | tetap |
| `school.email` | string | email valid, maks 255 | tetap |
| `school.map_url` | string | URL aman (lihat aturan URL), maks 500 | baru |
| `school.contacts` | list | maks 10 `{key, label, name, phone, is_whatsapp}` | baru (menggantikan `school.whatsapp`) |
| `school.social_links` | list | maks 10 `{platform, label, url}` | baru (menggantikan 3 URL sosial) |
| `portal.hero_greeting` | string | maks 100 | baru |
| `portal.hero_title` | string | maks 150 | baru |
| `portal.hero_subtitle` | string | maks 300 | baru |
| `portal.highlights_heading` | string | maks 100; kosong berarti seksi tanpa judul tampak | baru |
| `portal.highlights` | list | maks 8 `{title, description}` | baru |
| `portal.hero_album_id` | int | `albums.id` yang ada | baru |
| `portal.profile_video_id` | int | `videos.id` yang ada | baru |
| `portal.psb_video_id` | int | `videos.id` yang ada | baru |
| `portal.footer_quote_arabic` | string | maks 1000 | baru |
| `portal.footer_quote_translation` | string | maks 1000 | baru |
| `portal.footer_quote_source` | string | maks 100 | baru |
| `portal.footer_description` | string | maks 500 | baru |
| `portal.footer_links` | list | maks 8 `{label, url}` | baru |

Dihapus setelah migrasi: `school.whatsapp`, `school.instagram_url`, `school.facebook_url`, `school.youtube_url`.

### Aturan item daftar

- **Kontak:**
  - `key`: 8 karakter `[a-z0-9]`. Dibuat Action untuk item baru, dipertahankan untuk item lama yang dikirim ulang. Ini rujukan stabil kontak PSB di F038. Key duplikat dalam satu kiriman ditolak.
  - `label`: wajib, maks 60.
  - `name`: opsional, maks 100.
  - `phone`: wajib, dinormalisasi seperti `school.phone`.
  - `is_whatsapp`: boolean, bawaan `true`.
- **Media sosial:**
  - `platform`: salah satu dari `instagram`, `facebook`, `youtube`, `tiktok`, `telegram`, `whatsapp_channel`, `x`, `website`, `lainnya` (konstanta `UpdateSchoolContacts::PLATFORMS`).
  - `label`: opsional, maks 60, wajib bila `lainnya`.
  - `url`: wajib, URL absolut aman, maks 255.
- **Keunggulan:** `title` wajib maks 80, `description` opsional maks 300.
- **Tautan footer:** `label` wajib maks 60. `url` wajib, berupa URL absolut aman **atau** path internal aman, maks 255.

### Aturan URL (satu helper, dipakai saat tulis dan saat baca)

Trait `App\Actions\Konten\Concerns\ValidatesPublicUrls`:
- `isSafeAbsoluteUrl(string)`:
  - tidak memuat karakter kontrol atau spasi (`/[\x00-\x20\x7F]/`, termasuk tab dan newline di posisi mana pun);
  - lolos `FILTER_VALIDATE_URL`;
  - skema `http` atau `https`;
  - host tidak kosong.
- `isSafeInternalPath(string)`:
  - tidak memuat karakter kontrol atau spasi;
  - diawali tepat satu `/`;
  - karakter kedua bukan `/` maupun `\`;
  - tidak memuat `\` sama sekali.
- Saat tulis: URL tidak aman ditolak dengan reason `url_invalid` / `link_invalid`.
- Saat baca (`GetSchoolProfile`): item `social_links` dan `footer_links` yang tidak aman dibuang, dan `map_url` yang tidak aman menjadi `null`. Ini melindungi dari data lama atau data yang diubah manual.

### Hubungan logo cetak dan logo desktop

- **Logo cetak** (`school.logo_path`, key lama) dipakai kop surat F014, kuitansi F007, dan fallback `og:image`. F036 tidak mengubah pemakaian cetak dan **tidak memakai logo cetak di header portal**.
- **Logo desktop** khusus header portal di layar `md` (≥ 768px) ke atas. Boleh berupa satu gambar lebar berisi beberapa logo. Tidak dipakai di cetakan, karena gambar lebar akan merusak kop A5.
- **Logo mobile** khusus header portal di bawah `md`.
- Urutan fallback header (keputusan koordinator/user, tanpa logo cetak):

| Desktop terisi | Mobile terisi | Tampilan ≥ md | Tampilan < md | Markup |
|---|---|---|---|---|
| ya | ya | logo desktop | logo mobile | `<picture>`: `<source media="(min-width: 768px)" srcset="desktop">` + `<img src="mobile" alt="nama">`, nama `sr-only` |
| ya | tidak | logo desktop | logo desktop | `<img src="desktop" alt="nama">` tanpa `<picture>`, nama `sr-only` |
| tidak | ya | teks nama | logo mobile | `<img src="mobile" alt="nama">` dalam pembungkus `md:hidden`, ditambah teks nama `hidden md:inline`. Di desktop img tersembunyi masih bisa diunduh browser; risiko ini diterima karena logo mobile kecil |
| tidak | tidak | teks nama | teks nama singkat (bila ada), selain itu nama | tanpa `<img>` |

- **Instalasi lama** yang hanya punya `school.logo_path` (F016/F017 menampilkan logo cetak di header): migrasi **menyalin berkas** logo cetak menjadi logo desktop bila logo desktop kosong, sehingga tampilan header sama seperti sebelumnya. Yang disalin berkasnya (nama acak baru di `school/`), bukan path-nya. Dengan begitu, mengganti atau menghapus salah satu logo tidak menghapus berkas logo lainnya.

### Migrasi data

Berkas `database/migrations/2026_10_17_000000_move_school_profile_settings.php`. Migrasi berisi data saja, tanpa perubahan skema. Ditulis memakai `DB::table('settings')`, bukan model, agar tidak bergantung pada kode model di masa depan. Karena cast model tidak berlaku:
- Membaca: `json_decode($row->value, true)`. Nilai yang gagal di-decode atau bertipe salah dianggap kosong (tanpa exception).
- Menulis: `json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`. Insert mengisi `created_at` dan `updated_at` = `now()`, update mengisi `updated_at`. Postgres menerima string JSON valid untuk kolom `json`.
- Satu transaksi DB untuk perubahan baris. Salin berkas logo dilakukan sebelum transaksi; bila transaksi gagal, berkas salinan dihapus.

`up()`:
1. **WhatsApp lama.** Bila `school.whatsapp` terisi, nomor dinormalisasi (hapus spasi, `-`, `.`, kurung).
   - `school.contacts` kosong: buat `[{key: <acak 8>, label: "WhatsApp", name: null, phone, is_whatsapp: true}]`.
   - `school.contacts` sudah ada: **tambahkan** kontak itu di akhir **bila belum ada kontak dengan nomor ternormalisasi yang sama**. Bila sudah ada, tidak ditambah.
   - Label "WhatsApp" adalah data migrasi yang bisa diubah admin, bukan teks UI.
2. **URL sosial lama.** Urutan instagram, facebook, youtube. Hanya URL aman (aturan URL) yang dipindah dan ditambahkan ke akhir `school.social_links` bila URL yang sama belum ada. URL lama yang tidak aman tidak dipindah, karena memang tidak pernah dirender F017.
3. **Logo.** Bila `school.logo_desktop_path` kosong, `school.logo_path` terisi, dan berkasnya ada di disk `public`, salin berkas ke `school/<uuid>.<ext>` dan isi `school.logo_desktop_path`. Bila berkas tidak ada, lewati.
4. Hapus empat key lama.

Idempoten: menjalankan ulang tidak menggandakan kontak, URL, maupun salinan logo.

`down()`:
- Bila `school.contacts` berisi kontak `is_whatsapp` pertama, kembalikan `school.whatsapp` dari nomornya. Bila daftar kosong, `null`, atau rusak, `school.whatsapp` tidak dibuat.
- Kembalikan URL pertama per platform instagram/facebook/youtube.
- Daftar baru dan `school.logo_desktop_path` dibiarkan, karena kode lama mengabaikannya.
- `down()` tidak pernah melempar exception karena data rusak.

### Perubahan model

- `Setting::getMany(array $keys): array<string, mixed>` mengambil key yang belum ada di cache scoped dengan satu `whereIn`, menyimpan key yang tidak ada sebagai `null` di cache, lalu mengembalikan peta key → nilai. `get`, `set`, dan `forget` tidak berubah.
- Tidak ada tabel, kolom, FK, maupun soft delete baru, dan tidak menyentuh data keuangan.

## Action class

Semua di `app/Actions/Konten/`. Aturan bersama:
- Validasi lewat `ValidatesContentInput` dan `ValidatesPublicUrls`. Pesan error `pondok.konten.errors.<reason>`, field berpola `contacts.<i>.phone`.
- Semua field divalidasi sebelum ada yang ditulis.
- Penulisan dalam satu transaksi lewat `ReplacesStoredFiles::saveWithFiles`.
- Activity log `konten` hanya memuat key yang berubah. Tidak ada perubahan berarti tidak ada log.
- **Nomor telepon kontak di activity log disimpan apa adanya (keputusan sadar).** Nomor-nomor itu memang diterbitkan di portal publik, sehingga bukan data pribadi tertutup. Masking justru menyulitkan audit siapa mengubah nomor apa.

1. **`GetSchoolProfile`** (diperluas)
   - Output: key lama `name`, `tagline`, `address`, `phone`, `email`, `logo_path`, `logo_url` (tetap), ditambah:
     - `short_name`, `arabic_name`, `map_url`;
     - `logo_desktop_url`, `logo_mobile_url`, `favicon_url`;
     - `contacts`, `social_links`;
     - `hero {greeting, title, subtitle}`, `highlights_heading`, `highlights`;
     - `hero_album_id`, `profile_video_id`, `psb_video_id`;
     - `footer {quote_arabic, quote_translation, quote_source, description, links}`.
   - `whatsapp`, `instagram_url`, `facebook_url`, dan `youtube_url` **dihapus** dari output.
   - Satu query lewat `getMany`. Item daftar rusak atau tidak aman dibuang per item. `name` jatuh ke `config('app.name')`.
2. **`UpdateSchoolProfile`** (diubah, halaman Identitas)
   - Input: `name`, `short_name`, `arabic_name`, `tagline`, `address`, `phone`, `email`. Untuk `logo`, `logo_desktop`, `logo_mobile`, dan `favicon` masing-masing ada `UploadedFile|null` dan `remove_*`.
   - Field teks yang tidak dikirim disimpan `null` (kontrak F016). Key lama `whatsapp` dan `*_url` **diabaikan**, agar `DemoData::SCHOOL` tetap jalan.
   - Gambar diunggah lewat `StorePublicImage` ke `school/`. Favicon wajib `image/png` dan maks `StorePublicImage::FAVICON_MAX_KILOBYTES = 256` (reason `favicon_invalid`).
   - Log `school_profile_updated`.
3. **`UpdateSchoolContacts`** (baru): `map_url`, `contacts`, `social_links`. Log `school_contacts_updated`.
4. **`UpdatePortalHome`** (baru)
   - Input: `hero_greeting`, `hero_title`, `hero_subtitle`, `highlights_heading`, `highlights`, `hero_album_id`, `profile_video_id`, `psb_video_id`.
   - Field teks dan daftar memakai full replace.
   - **Tiga key media hanya diubah bila key-nya ada di input** (`array_key_exists`). Saat modul konten mati, halaman admin tidak mengirim key media, sehingga pilihan lama tidak terhapus.
   - ID yang tidak ada ditolak (`album_not_found` / `video_not_found`). `null` diterima bila dikirim eksplisit.
   - Log `portal_home_updated`.
5. **`UpdatePortalFooter`** (baru): `footer_quote_arabic`, `footer_quote_translation`, `footer_quote_source`, `footer_description`, `footer_links`. Log `portal_footer_updated`.
6. **`GetPortalHomeData`** (diubah)
   - Menambah `profileTitle` (judul halaman `profil`, `null` bila `profileSummary` kosong), `heroAlbum` (`{title, photos}`, maks 10 foto urut `sort_order` lalu `id`), `profileVideo`, dan `psbVideo`.
   - Modul konten mati: semuanya `null`, tanpa query ke `pages`, `albums`, `photos`, atau `videos`. `psbVideo` juga `null` bila modul psb mati.
   - Rujukan hilang menghasilkan `null`.

Semua teks bebas disimpan sebagai teks polos (di-trim) dan di-escape Blade `{{ }}`. Tidak ada field HTML. Baris baru ditampilkan dengan `whitespace-pre-line`.

## UI (Filament / Blade)

Wajib: baca `docs/design-guide.md`, jalankan alur §13 (anti-ui-slop), dan checklist §0.

### Panel admin: cluster `App\Filament\Clusters\InstitutionProfile`

- `AdminPanelProvider` ditambah `->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')`. Halaman cluster berada di `app/Filament/Clusters/InstitutionProfile/Pages/`.
- Label cluster dan navigasi **"Profil lembaga"** (keputusan user; `pondok.school_profile.title` diubah). Grup `pondok.navigation.pengaturan`, ikon `OutlinedBuildingLibrary`.
- URL berubah dari `/admin/school-profile` menjadi `/admin/institution-profile/<slug>`. Test F016 yang memakai URL lama diperbarui di fase RED.
- Setiap halaman punya satu form, satu tombol utama, satu Action, dan notifikasi sukses yang menyebut objek (§7):

| Halaman | Slug | Section | Action |
|---|---|---|---|
| Identitas | `identity` | Identitas; Logo & favicon (pratinjau + hapus per gambar; helper: logo cetak "Dipakai di kop surat dan kuitansi", logo desktop "Boleh berisi beberapa logo dalam satu gambar", favicon "PNG persegi, maks. 256 KB"); Alamat & kontak resmi | `UpdateSchoolProfile` |
| Kontak & lokasi | `contacts` | Tautan peta; Daftar kontak (`Repeater` reorderable); Media sosial (`Repeater`) | `UpdateSchoolContacts` |
| Beranda | `home` | Sambutan; Keunggulan (judul seksi + `Repeater`); Media beranda (`Select` album, video profil, video PSB). **Bila modul konten mati, section media diganti teks `pondok.common.module_disabled` dan select tidak dirender** | `UpdatePortalHome` |
| Footer | `footer` | Kutipan (`Textarea` Arab ber-`dir="rtl" lang="ar"`, terjemah, sumber); Deskripsi; Tautan cepat (`Repeater`) | `UpdatePortalFooter` |

- Hanya komponen bawaan Filament. Teks lewat `lang/id/pondok.php`. Placeholder URL boleh `https://`. Placeholder dan helper lain tidak memuat contoh Arab, ayat, salam, maupun nama lembaga.

### Portal (Blade, F017)

- **`layouts/public.blade.php`**
  - Header logo sesuai tabel fallback, dengan tinggi tetap `h-10`, `max-w-full`, dan `object-contain`, tanpa scroll horizontal di 360px.
  - `<link rel="icon" type="image/png">` memakai favicon, bila kosong `favicon.ico`.
  - `og:image` dari `ogImage` halaman, lalu logo cetak.
  - Meta description: dari halaman, lalu tagline, lalu `footer_description`, selain itu tidak dirender.
- **`portal/home.blade.php`** (urutan seksi):
  1. Hero: nama Arab (`lang="ar" dir="rtl"`), sambutan, judul (fallback nama), subjudul (fallback tagline), dan tombol daftar bila `psbOpen`.
  2. Slider: `<div role="region" tabindex="0" aria-label="{judul album}">` dengan `overflow-x-auto snap-x snap-mandatory` dan fokus terlihat `focus-visible:ring-2 focus-visible:ring-primary-500`. Tanpa JavaScript dan tanpa library. Foto `aspect-video`, `loading="lazy"`, `alt` dari caption, bila kosong pola `pondok.portal.albums.photo_alt`.
  3. Keunggulan: `h2` dari `highlights_heading` (bila kosong tanpa `h2`), kartu `rounded-lg`.
  4. Video profil dan video PSB: `x-portal.video-embed`, judul dari `videos.title`.
  5. Seksi F017. Judul seksi profil = `profileTitle`.
  - Seksi kosong tidak dirender.
- **`portal/partials/footer.blade.php`**
  - Kutipan (Arab RTL, terjemah, sumber).
  - Identitas: nama, deskripsi, alamat, tautan peta.
  - Kontak berlabel: nomor `0812-3456-7890`, `wa.me` untuk kontak WA, `tel:` untuk lainnya.
  - Media sosial: label `pondok.portal.social.<platform>`, atau label isian untuk `lainnya`.
  - Tautan cepat: tautan isian admin, diikuti tautan sistem yang sudah ada.
- **Tautan peta** (footer dan halaman kontak): `<a href="{map_url}" target="_blank" rel="noopener noreferrer">` dengan teks `pondok.portal.contact.map_link` dan teks `sr-only` "membuka tab baru". Tanpa iframe dan tanpa skrip.
- **`portal/partials/contact-list.blade.php`**: alamat, telepon kantor, email, tautan peta, lalu daftar kontak.
- **`portal/pages/show.blade.php`**: `$hasContact` dihitung dari `address`, `phone`, `email`, `map_url`, atau `contacts` tidak kosong.
- Warna hanya token, target sentuh 44px, dan fokus terlihat. Cek visual 360px dan desktop, atau laporkan belum dicek.

## Permission & cakupan

- Tidak ada permission baru. Keempat halaman cluster memakai `canAccess()` = `can('view:school-profile')`.
- **Keputusan user:** `view:school-profile` dimiliki `super-admin`, `admin-sekolah`, dan **`admin-konten`**. `RolePermissionSeeder` diperbarui, ditambah komentar alasan. Peran lain mendapat 403 dan tidak melihat menu.
- Dampak ke test F016 yang mengasersi izin profil hanya untuk `admin-sekolah` (`tests/Feature/Database/Seeders/RolePermissionSeederTest.php` baris ±332, `tests/Feature/Filament/KontenAccessTest.php`, `tests/Feature/Filament/KontenVerifyGapsTest.php` baris ±122): diperbarui di fase RED.
- Halaman profil tidak terikat saklar modul (inti).
- Portal mengikuti saklar modul:
  - slider dan video profil butuh `konten`;
  - video PSB butuh `konten` dan `psb`;
  - tombol daftar butuh `psb` dan gelombang terbuka.
- Data per jenjang tidak ada, sehingga `unit_user` tidak berlaku.

## Kriteria penerimaan (bisa dites)

### Pembacaan

- **AC-1** Semua key profil ada di settings dan cache scoped kosong → `GetSchoolProfile::handle()` → tepat **satu** query ke `settings`. Panggilan kedua dalam request yang sama menambah 0 query.
- **AC-2** Settings hanya berisi `school.name` → output: field baru `null`/`[]`, `name` terisi, tanpa key `whatsapp`, `instagram_url`, `facebook_url`, `youtube_url`.
- **AC-3** `school.contacts` berisi string, item tanpa `phone`, dan satu item valid → hanya item valid dikembalikan, tanpa exception.
- **AC-4** Data tersimpan manual: `social_links` dengan `javascript:alert(1)` dan `https://a.test/\tx` (tab), `footer_links` dengan `//evil.test` dan `/\tevil` (tab), serta `map_url` `javascript:x` → item tidak aman tidak dikembalikan dan `map_url` = `null`. Item aman di daftar yang sama tetap dikembalikan.

### Migrasi

- **AC-5** Data lama `school.whatsapp = "0812-0000-0170"`, `school.instagram_url`, dan `school.youtube_url` aman, tanpa daftar baru → `up()` →
  - baris `school.contacts` berisi JSON valid (di-decode) satu kontak `{label "WhatsApp", phone "081200000170", is_whatsapp true}` dengan key `^[a-z0-9]{8}$`;
  - `school.social_links` = `[instagram, youtube]` berurutan;
  - `created_at` dan `updated_at` baris baru terisi;
  - keempat key lama tidak ada.
- **AC-6** `school.contacts` sudah berisi kontak bernomor `081200000170` dan kontak lain, `school.whatsapp = "0812 0000 0170"` → `up()` → jumlah kontak tidak berubah. Bila `school.whatsapp = "081311112222"` (nomor baru) → kontak ditambahkan di akhir. Dalam kedua kasus `school.whatsapp` terhapus.
- **AC-7** `school.facebook_url = "ftp://x"` → tidak masuk `social_links` dan key lama terhapus. `school.social_links` sudah memuat URL instagram yang sama → tidak terduplikasi.
- **AC-8** `school.logo_path` terisi, berkasnya ada di disk `public` (fake), dan `logo_desktop_path` kosong → `up()` → `school.logo_desktop_path` menunjuk berkas baru di `school/` dengan nama berbeda dan isi byte sama, sedangkan `school.logo_path` tidak berubah. Bila `logo_desktop_path` sudah terisi → tidak berubah. Bila berkas logo cetak tidak ada → `logo_desktop_path` tidak dibuat dan tidak ada exception.
- **AC-9** `up()` dijalankan dua kali → hasil sama, tanpa kontak, URL, atau berkas salinan tambahan.
- **AC-10** Setelah AC-5 → `down()` → `school.whatsapp` = `081200000170`, URL instagram/youtube kembali, dan `school.logo_desktop_path` tetap. `down()` dengan `school.contacts` `null`, `[]`, atau JSON rusak → tanpa exception dan `school.whatsapp` tidak dibuat.
- **AC-11** Settings tanpa key lama dan tanpa logo → `up()` → tidak ada baris baru.

### `UpdateSchoolProfile` (Identitas)

- **AC-12** Nama, nama singkat, nama Arab, tagline, alamat, telepon `0812-3456 7890`, dan email → tersimpan, telepon `081234567890`, satu log `school_profile_updated` dengan key yang berubah saja.
- **AC-13** Data sama dikirim ulang → tidak ada log baru.
- **AC-14** Input bentuk `DemoData::SCHOOL` (memuat `whatsapp`) → sukses, `school.contacts`/`social_links` tidak berubah, dan `school.whatsapp` tidak dibuat.
- **AC-15** `short_name` 31 karakter atau `arabic_name` 151 karakter → `ValidationException` pada field itu dan tidak ada perubahan.
- **AC-16** Unggah `logo_desktop` PNG 900 KB dan `logo_mobile` JPG → tersimpan di `public/school/` dengan nama acak, path di key masing-masing, `school.logo_path` tidak berubah.
- **AC-17** Ganti logo desktop → berkas lama terhapus dan berkas baru ada. `remove_logo_desktop` → key `null` dan berkas terhapus. Logo cetak hasil salinan migrasi tidak ikut terhapus.
- **AC-18** Favicon JPG, favicon PNG 300 KB, atau logo GIF/SVG → `ValidationException`, tanpa berkas tersimpan, tanpa perubahan. Favicon PNG 197 KB diterima.
- **AC-19** Email tidak valid bersama logo desktop valid → tidak ada berkas tertinggal dan tidak ada perubahan.

### `UpdateSchoolContacts`

- **AC-20** Dua kontak dan tiga media sosial → tersimpan sesuai urutan input, telepon dinormalisasi, key unik `^[a-z0-9]{8}$`, dan log `school_contacts_updated` memuat nomor apa adanya.
- **AC-21** Kontak lama berkey `abcd1234` dengan label diubah → key tetap. Kontak yang tidak dikirim hilang.
- **AC-22** Kontak tanpa label, telepon `12ab`, 11 kontak, atau dua key sama → `ValidationException` dengan field berindeks (mis. `contacts.1.phone`) dan tanpa perubahan.
- **AC-23** Media sosial dengan URL `javascript:alert(1)`, `ftp://x`, `//evil.test`, `https://a.test/\tx`, `https://a.test/\nx`, platform `myspace`, atau platform `lainnya` tanpa label → `ValidationException` dan tanpa perubahan.
- **AC-24** `map_url` `https://maps.app.goo.gl/abc` → tersimpan. `javascript:x` atau URL bertab → ditolak.
- **AC-25** Daftar kosong → disimpan `null`.

### `UpdatePortalHome`

- **AC-26** Sambutan, judul, subjudul, judul keunggulan, tiga keunggulan, album yang ada, dan dua video yang ada → semua key tersimpan, ID integer, dan ada log `portal_home_updated`.
- **AC-27** Album atau video yang tidak ada → `ValidationException` (`album_not_found`/`video_not_found`) dan tanpa perubahan.
- **AC-28** Sembilan keunggulan, atau keunggulan tanpa judul → `ValidationException`.
- **AC-29** Media sudah tersimpan, lalu input tanpa key media → ketiga ID media tidak berubah. Input `hero_album_id: null` eksplisit → `null`.

### `UpdatePortalFooter`

- **AC-30** Kutipan Arab, terjemah, sumber, deskripsi, serta tautan `{Berita, /berita}` dan `{PSB, https://contoh.test/psb}` → tersimpan dan ada log `portal_footer_updated`.
- **AC-31** Tautan dengan `//evil.test`, `/\evil.test`, `/\t/evil.test` (tab), `/\n/evil.test`, `javascript:x`, `data:text/html,x`, atau `berita` → `ValidationException` dan tanpa perubahan.

### Panel admin

- **AC-32** `super-admin`, `admin-sekolah`, dan `admin-konten` membuka keempat halaman cluster → 200, form terisi. `bendahara`, `admin-psb`, `kesantrian`, `musyrif`, `petugas-pos`, dan `wali` → 403, dan item navigasi "Profil lembaga" tidak tampil. Label navigasi = "Profil lembaga".
- **AC-33** `RolePermissionSeeder` dijalankan (dua kali) → `admin-konten`, `admin-sekolah`, dan `super-admin` punya `view:school-profile`, peran lain tidak, dan tanpa duplikasi.
- **AC-34** Livewire Kontak & lokasi: dua kontak di repeater lalu `save` → notifikasi sukses dan `school.contacts` dua item. Telepon tidak valid → error pada field repeater yang tepat, tanpa exception.
- **AC-35** Livewire Identitas: unggah logo desktop dan favicon lalu `save` → pratinjau logo desktop tampil. Hapus favicon lalu `save` → key `null`.
- **AC-36** Livewire Beranda dengan modul konten aktif: opsi `Select` dari `albums`/`videos`, ID tersimpan. Modul konten mati: select media tidak dirender, pesan modul nonaktif tampil, dan `save` tidak mengubah ID media tersimpan. Livewire Footer menyimpan kutipan Arab.
- **AC-37** HTML keempat halaman tidak memuat kunci terjemahan mentah (`pondok.`). Semua placeholder dan helper text di keempat form tidak memuat huruf Arab (`/\p{Arabic}/u`), "QS", "Bismillah", "Assalamu", maupun "Ahlan".

### Header, favicon, dan meta portal

- **AC-38** Logo desktop dan logo mobile terisi → header berisi `<picture>` dengan `<source media="(min-width: 768px)" srcset="<url desktop>">` dan `<img src="<url mobile>" alt="<nama>">`.
- **AC-39** Hanya logo desktop terisi → header berisi `<img src="<url desktop>" alt="<nama>">`, tanpa `<source>`, dan nama dalam elemen `sr-only`.
- **AC-40** Hanya logo mobile terisi → `<img src="<url mobile>">` di dalam pembungkus ber-kelas `md:hidden`, dan teks nama di elemen ber-kelas `hidden md:inline`.
- **AC-41** Tanpa logo desktop dan mobile, tetapi logo cetak terisi → header tidak memuat `<img>` maupun URL logo cetak. Teks nama tampil, dan nama singkat untuk tampilan mobile bila terisi.
- **AC-42** Favicon terisi → `<link rel="icon" type="image/png" href="<url favicon>">`. Favicon kosong → `href` ke `favicon.ico`. `og:image` = URL logo cetak bila halaman tidak memberi gambar sendiri, bukan logo desktop.
- **AC-43** Upgrade instalasi lama: hanya `school.logo_path` berisi berkas, jalankan migrasi, lalu GET `/` → header menampilkan `<img>` logo desktop hasil salinan (path `school/…`, berbeda dari logo cetak).

### Beranda

- **AC-44** Hero, nama Arab, judul keunggulan, dan tiga keunggulan terisi → semuanya tampil, nama Arab dalam elemen `lang="ar" dir="rtl"`. Judul dan subjudul hero kosong → judul = nama dan subjudul = tagline.
- **AC-45** Keunggulan, album, dan video tidak dipilih → tidak ada seksi keunggulan, slider, video profil, maupun video PSB.
- **AC-46** Modul konten aktif, album berjudul "Fasilitas" dengan 12 foto → 10 foto pertama urut `sort_order`, masing-masing `loading="lazy"` dan ber-`alt`. Kontainer slider punya `role="region"`, `tabindex="0"`, dan `aria-label="Fasilitas"`. Tidak ada `<script>` tambahan. Modul konten mati → slider dan video profil tidak tampil, tanpa query ke `albums`, `photos`, `videos`, maupun `pages`.
- **AC-47** Video PSB terpilih: modul psb aktif → embed `youtube-nocookie.com/embed/<id>` dengan judul video tampil. Modul psb mati → tidak tampil. Album/video terpilih lalu dihapus → beranda 200 tanpa seksi itu.

### Footer, kontak, keamanan

- **AC-48** Footer dengan kutipan, deskripsi, dua kontak (WA dan non-WA), tiga media sosial, dan dua tautan cepat → kutipan Arab `lang="ar" dir="rtl"`, terjemah, sumber, nomor `0812-3456-7890`, `https://wa.me/6281234567890` untuk WA dan `tel:` untuk non-WA, label platform dari `lang/id`, dan tautan cepat diikuti tautan sistem.
- **AC-49** `map_url` terisi → footer dan halaman `kontak` memuat `<a>` ke URL itu dengan teks "Lihat di peta", `target="_blank"`, dan `rel` memuat `noopener`. Kosong → tidak ada tautan. Dalam semua kasus, HTML tidak memuat `<iframe`, `maps.googleapis`, maupun `google.com/maps/embed`, dan header CSP sama persis dengan `SetPortalSecurityHeaders::CONTENT_SECURITY_POLICY`.
- **AC-50** Halaman `kontak` dengan body kosong:
  - hanya `school.contacts` terisi → kartu kontak tampil dengan kontak itu;
  - hanya `map_url` terisi → kartu kontak tampil dengan tautan peta;
  - semua kosong → empty-state `pondok.portal.pages.empty`.

  Tidak ada respons 500.
- **AC-51** XSS: `hero_title`, label kontak, deskripsi keunggulan, `highlights_heading`, dan label tautan footer berisi `<script>alert(1)</script>` → HTML memuat bentuk ter-escape dan tidak memuat tag mentah.
- **AC-52** Beranda tetap tanpa `Set-Cookie` (stateless F017).

### Cetak

- **AC-53** Logo cetak, desktop, dan mobile berbeda → kuitansi (F007) dan surat (F014) memuat logo cetak, nama, alamat, dan `school.phone`, tanpa URL atau berkas logo desktop/mobile.

### Tanpa teks konten bawaan

- **AC-54** Instalasi baru: `migrate:fresh --seed` tanpa DemoSeeder, dengan modul konten dan psb aktif → GET `/`, `/profil`, dan `/kontak` (beranda, footer, halaman profil, halaman kontak) →
  - HTML tidak memuat (case-insensitive): "Bismillah", "بسم", "Assalamu", "Ahlan", "Selamat datang", "Mengapa", "Mengenal", "Situs resmi", "QS.", "Ali 'Imran", "sepanjang tahun", "Lorem", "ipsum", "placeholder";
  - tidak ada elemen `lang="ar"`, blok kutipan footer, seksi keunggulan, slider, maupun video profil/PSB;
  - tidak ada `<meta name="description">` di beranda;
  - semua key `portal.*`, `school.arabic_name`, `school.contacts`, dan `school.social_links` bernilai `null` atau tidak ada.
- **AC-55** Pemindaian rekursif seluruh nilai `lang/id/pondok.php` → tidak ada nilai yang memuat huruf Arab, "Bismillah", "Assalamu", "Ahlan", "Selamat datang", "Mengapa memilih", "Mengenal kami", "Situs resmi", "QS.", "Ali 'Imran", atau "sepanjang tahun". Kunci `pondok.portal.meta.default_description` dan `pondok.portal.home.profile_title` tidak ada. `SettingSeeder` dan `InstallSeeder` tidak menulis key `portal.*` maupun `school.*` selain `school.name`.
- **AC-56** `highlights_heading` kosong dengan tiga keunggulan → tiga kartu tanpa `h2` seksi keunggulan. `highlights_heading = "Mengapa memilih kami"` → `h2` berisi teks itu.
- **AC-57** `GetPortalHomeData`: halaman `profil` berjudul "Tentang Yayasan" dengan isi → `profileTitle = "Tentang Yayasan"` dan seksi beranda berjudul itu. Isi kosong → `profileTitle` dan `profileSummary` `null`, seksi tidak dirender.
- **AC-58** Halaman tanpa deskripsi sendiri: tagline terisi → meta description = tagline. Tagline kosong dan `footer_description` terisi → meta = deskripsi footer. Keduanya kosong → tidak ada tag `<meta name="description">`.

### Demo

- **AC-59** (setelah F034 `done`) `db:seed --class=DemoSeeder` → settings berisi:
  - `school.contacts`: minimal dua kontak fiktif `08120000xxxx` berlabel "Panitia PSB" dan "Sekretariat";
  - `school.social_links`: minimal dua URL `https://` contoh;
  - `portal.hero_greeting` (basmalah/salam) dan `portal.highlights` dari brosur;
  - footer QS. Ali 'Imran: 104;
  - `portal.hero_album_id` dan `portal.psb_video_id` menunjuk data demo.

  Logo diunggah lewat `UpdateSchoolProfile`:
  - `logo_desktop_path` berisi salinan `docs/brand/logo-mahad-lengkap.jpg` (JPG 42 KB);
  - `logo_mobile_path` dan `favicon_path` berisi salinan `docs/brand/logo-mahad-simbol.png` (PNG 197 KB, 500×500, di bawah batas favicon 256 KB, tanpa konversi).

  Hasilnya tiga berkas berbeda di `public/school/`. Bila berkas sumber tidak ada, langkah logo dilewati tanpa gagal. Seeder dijalankan dua kali → nilai sama, tanpa berkas logo tambahan. Settings tidak memuat nomor asli brosur (`085832333725`, `085669941929`) maupun nomor rekening.

## Rencana test

Test lama yang terdampak diperbarui **di fase RED oleh red-tester** (green-coder tidak boleh mengubah test). Hasil grep `whatsapp|instagram_url|facebook_url|youtube_url|school-profile` di `tests/`:
- `tests/Feature/Actions/Konten/GetSchoolProfileTest.php`, `UpdateSchoolProfileTest.php`, `KontenVerifyGapsTest.php`, `PortalHelpersTest.php`, `SavePostTest.php` (periksa: mungkin hanya `youtube_url` milik post);
- `tests/Feature/Database/KontenConstraintsTest.php`;
- `tests/Feature/Database/Seeders/RolePermissionSeederTest.php` (izin `admin-konten`);
- `tests/Feature/Filament/KontenAccessTest.php`, `KontenVerifyGapsTest.php`, `Pages/SchoolProfileTest.php`, `KontenTranslationsTest.php` (URL `/admin/school-profile` dan peran);
- `tests/Feature/Http/HomePageTest.php`;
- `tests/Feature/Http/Portal/PortalAdversarialTest.php`, `PortalAccessibilityTest.php`, `GalleryVideoPageTest.php`, `PostPagesTest.php`, `SeoTest.php`, ditambah test halaman statis/kontak yang memakai `whatsapp`.

| AC | File | Nama test (usulan) |
|---|---|---|
| 1 | `tests/Feature/Models/SettingTest.php`, `tests/Feature/Actions/Konten/GetSchoolProfileTest.php` | `getMany loads missing keys in one query`, `school profile is read with a single settings query` |
| 2–4 | `GetSchoolProfileTest.php` | `returns empty defaults and no legacy keys`, `skips malformed list items`, `drops unsafe urls on read` |
| 5–11 | `tests/Feature/Database/Migrations/MoveSchoolProfileSettingsTest.php` | `moves whatsapp and social urls into lists`, `merges legacy whatsapp into existing contacts`, `skips unsafe legacy urls without duplicates`, `copies print logo into desktop logo`, `is idempotent`, `down restores legacy keys safely`, `creates nothing without legacy keys` |
| 12–19 | `tests/Feature/Actions/Konten/UpdateSchoolProfileTest.php` | `saves identity fields and logs changes`, `does not log unchanged data`, `ignores legacy contact keys`, `rejects too long names`, `stores desktop and mobile logos separately`, `replaces and removes logo files`, `rejects invalid favicon and logo`, `leaves no files when validation fails` |
| 20–25 | `tests/Feature/Actions/Konten/UpdateSchoolContactsTest.php` | `saves ordered contacts and socials`, `keeps contact keys`, `rejects invalid contacts`, `rejects unsafe social urls`, `validates map url`, `stores empty lists as null` |
| 26–29 | `tests/Feature/Actions/Konten/UpdatePortalHomeTest.php` | `saves hero highlights and media`, `rejects missing album or video`, `limits highlights`, `keeps media when keys are absent` |
| 30–31 | `tests/Feature/Actions/Konten/UpdatePortalFooterTest.php` | `saves footer quote and links`, `rejects unsafe footer links` |
| 32–37 | `tests/Feature/Filament/Pages/InstitutionProfileTest.php` (+ `RolePermissionSeederTest.php` untuk 33) | `school and content admins open profile pages`, `seeder grants profile permission to content admin`, `saves contacts through repeater`, `uploads desktop logo and favicon`, `home media follows content module`, `forms carry no content examples` |
| 38–43 | `tests/Feature/Http/Portal/InstitutionHeaderTest.php` | `picture for desktop and mobile logos`, `desktop logo only`, `mobile logo only`, `no header logo falls back to name not print logo`, `favicon and og image`, `legacy install keeps header logo after migration` |
| 44–47, 51–52 | `tests/Feature/Http/Portal/InstitutionHomeTest.php` | `hero and highlights render`, `empty sections are hidden`, `slider is keyboard accessible and follows content module`, `psb video follows psb module`, `escapes free text`, `stays stateless` |
| 48–50 | `tests/Feature/Http/Portal/InstitutionFooterContactTest.php` | `footer renders quote contacts socials and links`, `map is a link only with unchanged csp`, `contact page shows card or empty state` |
| 53 | `tests/Feature/Http/Keuangan/PaymentReceiptTest.php`, `tests/Feature/Http/Dokumen/IssuedLetterPrintTest.php` | `uses print logo not desktop logo` |
| 54–58 | `tests/Feature/Http/Portal/NoBuiltInContentTest.php` | `fresh install renders no built in content text`, `translations and install seeders carry no content text`, `highlights heading comes from settings`, `profile section uses static page title`, `meta description has no built in fallback` |
| 59 | `tests/Feature/Database/Seeders/DemoSeederTest.php` | `fills institution profile with fictitious data and brand logos` |

## Urutan implementasi

Dimulai setelah rencana disetujui user dan F034 `done`.

1. `Setting::getMany` (AC-1 bagian model).
2. Trait `ValidatesPublicUrls` (dipakai langkah 3–7).
3. Migrasi data `move_school_profile_settings` (AC-5–11).
4. `GetSchoolProfile` diperluas (AC-1–4).
5. `UpdateSchoolProfile` dan `StorePublicImage::FAVICON_MAX_KILOBYTES` (AC-12–19).
6. `UpdateSchoolContacts` (AC-20–25).
7. `UpdatePortalHome` (AC-26–29), lalu `UpdatePortalFooter` (AC-30–31).
8. `lang/id/pondok.php`: teks baru dan audit (hapus `default_description` dan `profile_title`, ganti `contact.title`, pindah label platform) (AC-55).
9. `RolePermissionSeeder` (AC-33).
10. Cluster Filament, `discoverClusters`, dan empat halaman. `SchoolProfile` lama dipindah (AC-32, 34–37).
11. Layout portal: header `<picture>`, favicon, meta description (AC-38–43, 58).
12. `GetPortalHomeData` dan `home.blade.php` (AC-44–47, 51–52, 56–57).
13. Footer, `contact-list`, dan `pages/show.blade.php` (AC-48–50). Verifikasi cetak (AC-53) dan instalasi baru (AC-54).
14. `docs/erd.md` bagian Konten.
15. DemoSeeder: `DemoData`/`DemoContent` field baru dan logo dari `docs/brand/` lewat `UpdateSchoolProfile` (AC-59).
16. Pint, test terkait, checklist design-guide §0, dan cek visual 360px/desktop (atau laporkan belum).

## Risiko & pertanyaan terbuka

Risiko:
- **Urutan merge dengan F035 (worktree lain):** F036 di-merge **lebih dulu**, lalu F035 di-rebase di atasnya. F036 tidak menyentuh token warna/font (`resources/css/app.css`, konfigurasi tema). Berkas yang kemungkinan dipakai bersama:
  - `resources/views/layouts/public.blade.php`
  - `resources/views/portal/partials/footer.blade.php`, `nav-links.blade.php`, `contact-list.blade.php`
  - `resources/views/portal/home.blade.php`, `resources/views/portal/pages/show.blade.php`
  - `lang/id/pondok.php`
  - `app/Filament/Pages/SchoolProfile.php` (dipindah ke cluster)
  - `app/Actions/Konten/GetSchoolProfile.php`
- **Perubahan output `GetSchoolProfile`, URL halaman admin, dan izin `admin-konten`** memecahkan test F016/F017. Ini disengaja dan diperbarui di fase RED.
- **Logo desktop sangat lebar** bisa memicu scroll horizontal di 360px. Dicegah dengan `max-w-full h-10 object-contain`. Di bawah `md`, logo desktop hanya tampil bila logo mobile kosong.
- **Rujukan album/video tanpa FK** ditangani dengan pembacaan toleran (AC-47).
- **`Setting::getMany` menyentuh kode F033.** Test F033 harus tetap hijau.
- **Migrasi menyalin berkas** di disk `public`. Bila disk tidak bisa ditulis saat deploy cPanel, langkah logo dilewati, key lain tetap dimigrasi, dan admin dapat mengunggah ulang.
- **Berkas `docs/brand/`** ikut paket rilis (tidak dikecualikan `BuildReleaseManifest`), tetapi DemoSeeder tetap melewati logo bila berkas tidak ada.

Pertanyaan terbuka: tidak ada. Q1–Q4 sudah dijawab user (peta hanya tautan, `admin-konten` mendapat izin, nama "Profil lembaga", logo demo dari `docs/brand/`).

## Riwayat revisi

- 2026-10-16 DRAFT awal (product-manager). Mencakup koreksi user tentang logo: daftar logo pendamping diganti logo desktop dan logo mobile, favicon tetap, dan logo cetak tetap `school.logo_path`. Permintaan dipecah menjadi F036/F037/F038.
- 2026-10-16 Prinsip user "tanpa teks konten bawaan" ditambahkan: bagian Prinsip, key `portal.highlights_heading`, judul seksi profil dari halaman statis, fallback meta description dihapus, serta AC-46–50 (penomoran lama).
- 2026-10-16 REVISED atas Review #1 dan jawaban user Q1–Q4. **AC dinomori ulang (AC-1 sampai AC-59).**
  - #1 blocker: `pages/show.blade.php` masuk lingkup, ditambah AC-50.
  - #2: F034 tetap di `depends_on`, RED/GREEN dimulai setelah F034 `done`, isi demo tetap di F036 (AC-59).
  - #3: migrasi eksplisit (`json_decode`/`json_encode`, timestamps, penggabungan WA ke kontak yang ada, URL lama tidak aman, `down()` aman) di AC-5–11.
  - #4: fallback header tanpa logo cetak, `school.logo_path` disalin menjadi logo desktop saat migrasi, dan semua kombinasi dipetakan di AC-38–43.
  - #5: audit `pondok.portal.*` dengan daftar kata uji lebih luas di AC-37, 54, 55.
  - #6: `profileTitle` ditambahkan, dan judul bawaan `StaticPageSeeder` dinilai sebagai teks antarmuka (AC-57).
  - Minor:
    - karakter kontrol ditolak dan URL disaring saat baca (AC-4, 23, 31);
    - header memakai `<picture>`;
    - slider ber-`region`/`tabindex`;
    - select media mengikuti modul konten (AC-29, 36);
    - `discoverClusters` ditambahkan;
    - urutan merge F036 lalu F035, dengan daftar berkas bersama;
    - nomor telepon di log tidak dimasking (keputusan sadar);
    - notes F037/F038 diperbarui.
  - Jawaban user:
    - peta hanya tautan (AC-49);
    - `admin-konten` mendapat izin (AC-32–33);
    - label "Profil lembaga";
    - logo demo dari `docs/brand/` (AC-59).
  - Bagian pertanyaan terbuka dikosongkan.

## Review #1 — 2026-10-16

Verdict: CHANGES_REQUESTED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | blocker | Ruang lingkup / Portal / AC | `resources/views/portal/pages/show.blade.php` baris 4 membaca `$school['whatsapp']` untuk `$hasContact` halaman `kontak`. Setelah key `whatsapp` dihapus dari output `GetSchoolProfile` (AC-2), halaman `/kontak` error `Undefined array key` (500). Berkas ini tidak disebut di rencana (hanya `contact-list` dan footer). | Tambahkan `pages/show.blade.php` ke ruang lingkup dan langkah 12. `$hasContact` harus menghitung `address`, `phone`, `email`, `map_url`, dan `contacts`. Tambah AC: halaman `kontak` dengan hanya `contacts` terisi tetap menampilkan kartu kontak; semua kosong menampilkan empty-state; tidak ada 500. |
| 2 | major | `depends_on` F034 (feature_list.json, langkah 14, AC-45) | F034 masih `in-progress` dan `DemoSeeder`, `DemoData`, `DemoContent` sedang berubah di worktree. CLAUDE.md melarang memulai fitur sebelum dependensi `done`, sehingga seluruh F036 ikut tertahan. | Pilih satu. (a) Hapus F034 dari `depends_on` F036, lalu pindahkan AC-45, langkah 14, dan acceptance ke-7 ke entri kecil baru (depends_on F036 dan F034). (b) Tunda mulai F036 sampai F034 `done`. Disarankan (a). |
| 3 | major | Migrasi data | Migrasi memakai `DB::table`, jadi cast `json` model tidak berlaku. Rencana tidak menyebut `json_decode` saat baca dan `json_encode` saat tulis (kolom `settings.value` bertipe `json`; Postgres menolak string bukan JSON). Nasib `school.whatsapp` bila `school.contacts` sudah ada juga tidak jelas: key lama dihapus sehingga nomor hilang. | Tulis eksplisit encode/decode dan pengisian `created_at/updated_at`. Tentukan perilaku bila `contacts` sudah ada dan `whatsapp` terisi (disarankan: tambahkan bila nomor belum ada di daftar, lalu hapus). Tambah AC untuk kasus itu, untuk URL lama bukan http/https, dan `down()` saat `contacts` kosong. |
| 4 | major | Prinsip (a), header portal | Prinsip user: mobile jatuh ke logo desktop lalu nama teks; logo cetak untuk kop/kuitansi. Rencana menyisipkan logo cetak + nama pada rantai fallback header. Itu menyimpang dari prinsip walau menjaga perilaku F017 lama. | Minta konfirmasi user atau selaraskan. Alternatif: fallback ke logo cetak hanya bila logo desktop dan mobile keduanya kosong, ditulis sebagai keputusan sadar. AC-32 sampai AC-34 harus memetakan urutan terpilih, termasuk kasus hanya logo mobile terisi (desktop jatuh ke mana). |
| 5 | major | Prinsip (b), AC-47 | Pemindaian `lang/id` hanya mencari 5 kata; teks konten lain bisa lolos. Placeholder/helper form admin juga bisa memuat contoh konten. | Perluas AC-47: tambah kata "Mengapa", "Mengenal", "Situs resmi", "QS.", "Assalamu", "sepanjang"; placeholder/helper admin tidak boleh memuat contoh Arab atau ayat. Tambah audit manual semua `pondok.portal.*` di langkah 8. |
| 6 | major | AC-49, `GetPortalHomeData` | Judul seksi profil diambil dari judul halaman `profil`, tetapi keluaran `GetPortalHomeData` tidak punya `profileTitle`. Belum jelas apakah `StaticPage` `profil` di-seed dengan judul default aplikasi. | Tambahkan `profileTitle` ke keluaran (null bila halaman/isi kosong). Periksa seeder `StaticPage` dan tetapkan sikap, lalu cerminkan di AC-49. |
| 7 | minor | Keamanan, AC-27 | Path internal menolak `//` dan `/\`, tetapi tab/newline di dalam nilai (`/<TAB>/evil.test`) dibuang browser menjadi `//evil.test`. Saat baca, hanya URL sosial disaring (AC-4); `footer_links` dan `map_url` tidak. | Tolak karakter kontrol dan spasi di semua URL/path. Saring juga saat baca untuk `footer_links` dan `map_url`, tambah AC sejajar AC-4. |
| 8 | minor | Header portal | Dua `<img>` (`hidden md:block` dan `md:hidden`) tetap diunduh walau disembunyikan. AC-33 mengassert kelas Tailwind (rapuh). | Pakai `<picture>` dengan `<source media="(min-width: 768px)">`, dan assert strukturnya. |
| 9 | minor | Slider tanpa JS | Kontainer `overflow-x-auto` tidak bisa di-scroll keyboard bila tidak fokusable. | Tambah `tabindex="0"`, `role="region"`, `aria-label` dari judul album, fokus terlihat; masukkan ke AC-38. |
| 10 | minor | Saklar modul, admin | Halaman Beranda memuat `Select` album/video walau modul konten mati. Folder `app/Filament/Clusters` belum ada. | Sembunyikan/nonaktifkan `Select` media saat modul konten mati (assert di AC-31). Pastikan cluster terdaftar di panel provider. |
| 11 | minor | Konflik F035 | Hanya tertulis "koordinasi urutan merge". F035 menyentuh berkas yang sama (`layouts/public.blade.php`, `footer.blade.php`, `home.blade.php`, `lang/id/pondok.php`, `SchoolProfile`). | Tetapkan F036 merge dulu dan F035 rebase di atasnya; F036 tidak menyentuh token warna/font; cantumkan daftar berkas bersama di Risiko. |
| 12 | minor | Activity log | Log menyimpan nomor telepon lama dan baru apa adanya. | Catat sebagai keputusan sadar, atau cukup log jumlah item berubah untuk `contacts`. |
| 13 | minor | Pemecahan F037/F038 | Wajar: `depends_on` benar (F004, F009, F010 `done`), cakupan tidak tumpang tindih. F037 bergantung F036 secara longgar; slug `info-psb` di F037 baru dibuat F038. | Tulis di notes F037/F038 alasan dependensi dan bahwa `info-psb` dicadangkan oleh F037. |

Sudah baik: penyimpanan settings JSON portabel dan ukurannya aman; `Setting::getMany` selaras cache F033; key lama `school.*` dipertahankan sehingga kop F014 dan kuitansi F007 tidak terdampak (AC-44); favicon PNG dan atomisitas unggahan punya AC; daftar test lama untuk red-tester lengkap (tambahkan test yang menyentuh `pages/show` setelah grep); prinsip (c) tercermin di AC-45 dan AC-46.

## Review #2 — 2026-10-16

Verdict: APPROVED

Semua temuan Review #1 (1 blocker, 5 major, 7 minor) tertangani dan sudah dicocokkan dengan kode nyata. Catatan sisa hanya minor.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | AC-54 | Pemeriksaan kata "Ali 'Imran" pada HTML: tanda petik akan ter-escape (`&#039;`), sehingga assert bisa lolos palsu. Kata "placeholder" juga bisa cocok dengan atribut `placeholder=` bila ada form di portal. | Assert pada teks setelah `html_entity_decode`; batasi "placeholder" ke teks tampak (bukan atribut). |
| 2 | minor | AC-59 | "Seeder dua kali tanpa berkas logo tambahan" tidak menyebut mekanismenya. | Tulis: lewati bila key logo sudah terisi, atau ganti dan hapus berkas lama; assert jumlah berkas di `school/` sama. |
| 3 | minor | Permission | `docs/prd.md` §2 dan komentar `RolePermissionSeeder` ("PRD 2: ... milik Admin Sekolah") menyatakan profil milik Admin Sekolah. | Perbarui PRD §2 dan komentar seeder agar sesuai keputusan user (admin-konten ikut). |
| 4 | minor | Waktu mulai | `depends_on` F034 masih `in-progress`; RED/GREEN baru boleh mulai setelah F034 `done` (sudah ditulis di rencana). | Orkestrator jangan memulai RED sebelum F034 `done`. |

Verifikasi poin yang diminta:
- Fallback logo tanpa logo cetak: sesuai prinsip (a). Tabel 4 kombinasi dan AC-38 sampai AC-43 konsisten. Salinan berkas (bukan path) saat migrasi mencegah penghapusan silang (AC-17).
- Migrasi DB::table: `json_decode`/`json_encode`, timestamps, penggabungan WA, URL tidak aman, dan `down()` aman tertulis lengkap (AC-5 sampai AC-11). Kegagalan transaksi menghapus salinan berkas. Nilai JSON dibaca sebagai string di ketiga DB.
- Audit `pondok.portal.*`: cocok dengan `lang/id/pondok.php` (hanya `default_description` dan `profile_title` berisi konten; `contact.title` diganti). Daftar kata uji AC-54/55 tidak bentrok dengan isi `lang` lain (sudah di-grep).
- Judul StaticPage sebagai teks antarmuka: wajar (`defaults` = label navigasi, `body` kosong).
- Activity log tanpa masking: dicatat sebagai keputusan sadar dan masuk akal untuk nomor yang dipublikasikan.
- URL admin: `school-profile` hanya dirujuk oleh `SchoolProfile.php` sendiri, jadi tidak ada tautan lain yang rusak. Test yang terdampak sudah terdaftar untuk RED.
- Test F016/F017 yang harus diperbarui di RED: daftar lengkap, termasuk izin `admin-konten`.
- Pemecahan F037/F038 dan notes di `feature_list.json` sudah sesuai.
