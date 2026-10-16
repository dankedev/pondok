# F037 konten-halaman-menu — Halaman statis bebas dan urutan menu portal

Status rencana: APPROVED

## Ringkasan & tujuan

Saat ini F016/F017 hanya punya tiga halaman statis tetap: `profil`, `visi-misi`, dan `kontak`. Route `/{page}` dibatasi `whereIn(StaticPage::SLUGS)`, resource Filament tidak bisa membuat atau menghapus halaman, dan menu header di `portal/partials/nav-links.blade.php` ditulis langsung di kode. Akibatnya lembaga lain tidak bisa menyusun menu seperti contoh brief Al-Khair (Beranda, Tentang Yayasan, Pengurus Al-Khair, Selayang Pandang, Info PSB, Kontak) tanpa mengubah kode.

F037 menambahkan:
1. **Halaman bebas.** Admin konten bisa membuat, mengubah, menerbitkan atau menarik (draf), dan menghapus halaman dengan slug bebas. Slug tidak boleh bentrok dengan slug sistem maupun route yang dicadangkan.
2. **Menu portal yang diatur admin.** Header desktop dan seluler disusun dari item bawaan (Beranda, Berita, Galeri, Video, Info PSB) ditambah halaman. Urutan dan visibilitas diatur dari panel. Item yang modulnya mati, halamannya draf, atau halamannya terhapus tidak tampil.
3. **Tautan cepat footer (F036) bisa memilih halaman.** Tautan mengikuti slug terbaru dan hilang bila halamannya draf atau terhapus.
4. **Sitemap** memuat semua halaman terbit yang berisi.

Prinsip F036 "tanpa teks konten bawaan" tetap berlaku. Aplikasi tidak membuat halaman konten, judul konten, maupun isi. Yang berasal dari `lang/id` hanya teks antarmuka: label item bawaan (Beranda, Berita, Info PSB, dst.) dan judul awal tiga halaman sistem, yang oleh F036 sudah dinilai sebagai teks antarmuka (lihat F036-plan, bagian Prinsip).

## Keputusan utama

### K1. Nasib tiga halaman bawaan: tetap sebagai "halaman sistem", tetapi bisa draf

| Slug | Alasan dipertahankan di kode | Perilaku F037 |
|---|---|---|
| `kontak` | Halaman ini menampilkan kartu kontak dari profil lembaga (F036, data terstruktur), bukan sekadar isi HTML | Halaman sistem. Instalasi baru: **terbit**. Halaman dan item menunya **hanya tampil bila profil lembaga punya data kontak** (lihat aturan Kontak di bawah) |
| `profil` | Sumber seksi ringkasan profil di beranda (`GetPortalHomeData`, `profileTitle`/`profileSummary`) | Halaman sistem. Instalasi baru: **draf** |
| `visi-misi` | Entri F037 meminta ketiganya tetap ada. Dirujuk tautan "terkait" dari halaman profil | Halaman sistem. Instalasi baru: **draf** |

Aturan halaman sistem (`StaticPage::SYSTEM_SLUGS = ['profil', 'visi-misi', 'kontak']`, menggantikan `SLUGS`):
- Tidak bisa dihapus (Action menolak, tombol hapus tidak ada). Slug tidak bisa diubah.
- Judul dan isi bebas diubah. Status bisa `draft` atau `terbit`. Dengan begitu lembaga yang tidak memakai "Visi & misi" cukup membiarkannya draf: URL-nya 404, dan halaman itu tidak muncul di menu, footer, maupun sitemap.
- Judul awal tetap dari `pondok.static_page.defaults.*` (teks antarmuka, keputusan F036). Isi awal kosong.

**Aturan Kontak (keputusan orkestrator atas Review #1 temuan 4, sesuai prinsip "tanpa isi bawaan").** Halaman `kontak` dianggap *tersedia* hanya bila statusnya `terbit` **dan** profil lembaga punya minimal satu data kontak, yaitu salah satu dari `address`, `phone`, `email`, `map_url` terisi atau `contacts` tidak kosong (output `GetSchoolProfile`, sama dengan `$hasContact` F036). Bila tidak tersedia:
- `GET /kontak` mengembalikan 404 seperti halaman draf;
- item menu Kontak tidak tampil;
- tautan footer ke halaman `kontak` tidak dirender;
- URL `/kontak` tidak masuk sitemap.

Isi (`body`) halaman kontak tanpa data kontak **tidak** membuatnya tersedia. Ini keputusan sadar: halaman kontak adalah tempat data kontak terstruktur, dan teks bebas bisa ditulis di halaman biasa. Akibatnya, empty-state halaman `kontak` (F036 AC-50, kasus "semua kosong") berubah menjadi 404. Test itu diperbarui di RED.

**Instalasi baru** tidak menampilkan "Profil" yang kosong di menu, dan juga tidak menampilkan "Kontak" sebelum admin mengisi data kontak. Admin menulis isi lalu menerbitkannya. **Instalasi lama** tidak berubah tampilannya: migrasi menambah kolom `status` dengan nilai `terbit` untuk semua baris yang sudah ada. Migrasi tidak mendrafkan halaman secara otomatis. Admin yang memutuskan.

Keputusan ini **dikonfirmasi user** (16 Okt 2026, "pertahankan"; D1). Alternatif yang ditolak: hanya `kontak` yang menjadi halaman sistem, sedangkan `profil`/`visi-misi` menjadi halaman biasa yang boleh dihapus. Pilihan ini menyimpang dari acceptance F037 ("Halaman bawaan tidak bisa dihapus") dan menuntut pemilih "halaman ringkasan beranda" di F036. Itu perluasan ruang lingkup.

### K2. Halaman dihapus permanen, dan disembunyikan lewat status

- `pages` **tidak** memakai soft delete (CLAUDE.md: soft delete hanya untuk `students`, `applicants`, `posts`).
- Kolom baru `status` (`draft` | `terbit`, konstanta sama dengan `Post`) untuk menyembunyikan sementara.
- Hapus = hard delete lewat `DeleteStaticPage`, dengan konfirmasi. Halaman bukan data keuangan dan tidak dirujuk FK. Rujukan di settings (`portal.menu_items`, `portal.footer_links`) dibersihkan dalam transaksi yang sama, sehingga ID yang suatu saat dipakai ulang oleh DB tidak muncul di menu.

### K3. Menu disimpan di `settings` (JSON), bukan tabel baru

Polanya sama dengan F036 (single-tenant, daftar dibaca dan ditulis utuh, rujukan halaman tanpa FK, dibaca secara toleran). Key baru:

| Key | Tipe | Isi |
|---|---|---|
| `portal.menu_items` | list atau `null` | Item berurutan: `{type: "builtin", key, visible}` atau `{type: "page", page_id, label, visible}`. `null` berarti menu bawaan (lihat K4) |
| `portal.menu_show_register` | bool atau `null` | Tombol "Daftar" PSB di ujung menu. `null` diperlakukan `true` (perilaku sekarang) |

Item bawaan (`App\Support\PortalMenu::BUILTIN_KEYS` atau konstanta di Action, dengan nama final ditentukan green-coder):

| Key | Label (`lang/id`) | Tampil bila |
|---|---|---|
| `home` | `pondok.portal.nav.home` | selalu |
| `news` | `pondok.portal.nav.news` | modul `konten` aktif |
| `gallery` | `pondok.portal.nav.gallery` | modul `konten` aktif |
| `videos` | `pondok.portal.nav.videos` | modul `konten` aktif |
| `psb_info` | `pondok.portal.nav.psb_info` ("Info PSB", baru) | modul `psb` aktif **dan** `Route::has('psb.info')`. Route dibuat F038. Sampai F038 selesai, item ini tidak pernah tampil |

Item halaman tampil bila modul `konten` aktif, halaman ada, dan statusnya `terbit`. Label = `label` item bila diisi, selain itu judul halaman dipotong 30 karakter (`Str::limit`).

"Masuk" dan "Daftar" bukan item menu yang bisa diurutkan. Keduanya tetap di ujung menu seperti sekarang. "Daftar" bisa disembunyikan lewat `portal.menu_show_register` (D2). "Masuk" selalu tampil.

### K4. Menu bawaan dan penggabungan

- `portal.menu_items` bernilai `null` (instalasi baru dan lama) menghasilkan urutan yang **sama dengan header sekarang**: `home`, halaman `profil`, `news`, `gallery`, `videos`, `psb_info`, halaman `kontak`, semuanya `visible: true`. Halaman draf otomatis tidak tampil. Instalasi lama tidak berubah tampilannya. Instalasi baru tampil tanpa "Profil" karena halaman itu draf.
- **Batas 8 item `visible`** (di luar Masuk/Daftar). Yang dihitung adalah item ber-`visible: true` di daftar, **apa pun status tampilnya**: halaman draf, item dari modul mati, `psb_info` tanpa route, dan Kontak tanpa data kontak tetap dihitung. Aturan ini sederhana dan bisa dites. `UpdatePortalMenu` menolak lebih dari 8 (`menu_too_many`).
- **Penggabungan saat baca** (dipakai portal dan editor, satu fungsi bersama):
  1. Item rusak, key bawaan tak dikenal, halaman yang tidak ada, dan duplikat dibuang. Daftar tersimpan dibaca maksimal 60 entri (5 bawaan ditambah batas halaman K7), sisanya diabaikan.
  2. Bila daftar tersimpan memuat lebih dari 8 item `visible` (data manual), hanya 8 pertama yang `visible`, sisanya dibaca `visible: false`.
  3. **Item bawaan yang tidak ada di daftar ditambahkan di akhir**, berurutan sesuai katalog. Item itu `visible: true` **hanya bila jumlah `visible` saat itu masih < 8**, selain itu `visible: false`. Dengan begitu item bawaan rilis berikutnya muncul tanpa membuat editor gagal disimpan.
  4. Halaman yang tidak ada di daftar tidak tampil di portal. Di editor, halaman itu muncul di akhir dengan `visible: false`, urut judul.
- **Isi editor saat `portal.menu_items = null`:** daftar bawaan K4 (`home`, `profil`, `news`, `gallery`, `videos`, `psb_info`, `kontak`), ketujuhnya `visible: true` (7 ≤ 8), apa pun status halaman sistemnya. Halaman lain menyusul di akhir dengan `visible: false`. Menyimpan editor tanpa perubahan menghasilkan menu portal yang sama dengan sebelum disimpan.

### K5. Slug: unik, berformat, dan tidak bentrok

- Format: di-trim dan di-lowercase, lalu harus cocok `^[a-z0-9]+(?:-[a-z0-9]+)*$`, maks 50 (panjang kolom). Pola ini sama dengan regex route. `sitemap.xml`, `robots.txt`, `a/b`, dan `-x` ditolak (`slug_format`).
- Slug kosong: dibuat dari judul lewat `GenerateSlug` (akhiran `-2`, `-3`, ...). Slug cadangan diperlakukan sebagai sudah terpakai, sehingga judul "Berita" menghasilkan `berita-2`.
- Slug eksplisit yang terpakai ditolak dengan `slug_taken`, dan yang dicadangkan ditolak dengan `slug_reserved`.
- **Pemeriksaan format, cadangan, route, dan `public_path` hanya dijalankan saat slug baru dibuat atau diubah**, yaitu berbeda dari slug yang tersimpan. Slug yang sama dengan nilai tersimpan selalu lolos. Halaman lama yang slug-nya belakangan bentrok dengan route atau folder baru tetap bisa diubah judul, isi, dan statusnya.
- Slug cadangan adalah gabungan tiga sumber, dicek saat simpan:
  1. Daftar tetap `StaticPage::RESERVED_SLUGS`: `admin`, `berita`, `galeri`, `video`, `psb`, `info-psb`, `sitemap`, `robots`, `verifikasi-surat`, `storage`, `up`, `livewire`, `filament`, `login`, `logout`, `register`, `api`, `wali`, `build`, `vendor`. `info-psb` dan `wali` dicadangkan untuk F038 dan portal wali Fase 2.
  2. Segmen pertama setiap URI route terdaftar (`Route::getRoutes()`), selain parameter `{...}`. Ini otomatis menangkap `livewire-<hash>`, `_boost`, dan route baru di rilis berikutnya.
  3. Nama yang sudah ada sebagai berkas atau folder di `public_path()`, karena web server cPanel menyajikannya sebelum Laravel.
- Route `/{page}` diubah dari `whereIn(SLUGS)` menjadi `where('page', '[a-z0-9]+(?:-[a-z0-9]+)*')` dan tetap didaftarkan paling akhir. AC-15 menjaga agar route ini tidak membayangi `/admin`, `/up`, dan route lain. Bila urutan registrasi panel Filament ternyata kalah, regex diberi negative lookahead dari `RESERVED_SLUGS` (didukung requirement Symfony, portabel).

### K6. Footer memilih halaman

Item `portal.footer_links` boleh berbentuk lama `{label, url}` atau bentuk baru `{label: ?string, page_id: int}`. Bentuk baru me-render `route('portal.pages.show', <slug terkini>)`, dengan label berupa isian atau, bila kosong, judul halaman. Tautan bawaan `visi-misi` yang selama ini ditulis langsung di footer **dihapus**, sehingga footer sepenuhnya diatur admin. Footer lama selalu menampilkan tautan itu tanpa syarat isi. Supaya instalasi lama benar-benar tidak berubah, migrasi menambahkannya sebagai item `{label: null, page_id}` bila halaman `visi-misi` ada dan terbit, **apa pun isinya** (lihat Migrasi).

### K7. Batas jumlah halaman

Satu instalasi berisi maksimal **50 halaman** (`StaticPage::MAX_PAGES`), termasuk tiga halaman sistem. Bagi satu sekolah jumlah ini cukup, dan dengan batas ini editor menu (semua halaman dalam satu repeater, tanpa paginasi) serta `GetPortalNavigation` tetap ringan. `CreateStaticPage` menolak halaman ke-51 dengan `page_limit_reached`. Pesannya meminta admin menghapus halaman yang tidak dipakai.

## Ruang lingkup

Termasuk:
- Migrasi skema `pages.status` dan migrasi data footer (satu berkas, dapat dibalik).
- Model `StaticPage`: `SYSTEM_SLUGS`, `RESERVED_SLUGS`, `isSystem()`, scope `published()`, status konstanta, dan factory `StaticPageFactory` (state `draft`, `published`, `system`).
- Action: `CreateStaticPage` (baru), `UpdateStaticPage` (diperluas), `DeleteStaticPage` (baru), `UpdatePortalMenu` (baru), `GetPortalNavigation` (baru), serta perubahan `UpdatePortalFooter`, `GetSchoolProfile` (bentuk item footer dan prefetch key menu), `ShowPublicStaticPage`, `BuildSitemap`, dan `GetPortalHomeData` (profil harus terbit).
- `StaticPageSeeder`: status per halaman sistem.
- `RolePermissionSeeder`: `StaticPage` mendapat `create` dan `delete`, tanpa `deleteAny` (tidak ada hapus massal).
- `StaticPagePolicy`.
- Filament: `StaticPageResource` (buat, hapus, kolom status), halaman cluster baru **Menu** (`/admin/institution-profile/menu`), repeater Footer bisa memilih halaman.
- Portal: `routes/web.php`, `layouts/public.blade.php` (navigasi dihitung sekali), `portal/partials/nav-links.blade.php`, `portal/partials/footer.blade.php`, `portal/pages/show.blade.php` (tautan terkait memakai judul halaman tujuan dan hanya bila terbit), `portal/home.blade.php` (tidak ada perubahan markup selain mengikuti `profileTitle`).
- `lang/id/pondok.php`.
- DemoSeeder: contoh halaman dan menu dari brief.
- `docs/erd.md` (tabel `pages`, key settings baru, bentuk tautan footer) dan `docs/prd.md` §3.1 (kalimat "halaman statis (profil, visi-misi, kontak)").

Tidak termasuk (eksplisit):
- Halaman Info PSB beserta route `psb.info` (`/info-psb`): **F038**. F037 hanya mencadangkan slug dan menyediakan item `psb_info`. **Kontrak untuk F038:** nama route `psb.info`, path `info-psb`.
- Submenu/dropdown dan tautan menu ke URL eksternal. Menu hanya berisi item bawaan dan halaman. URL bebas tetap lewat tautan footer.
- Pratinjau halaman draf di portal, riwayat revisi halaman, jadwal terbit, dan meta description per halaman.
- Mengubah label item bawaan. Labelnya teks antarmuka dari `lang/id`.
- Toggle tautan "Masuk".
- Activity log untuk halaman. `UpdateStaticPage` F016 tidak mencatat log, dan polanya diikuti. Menu dicatat lewat `SavesProfileSettings` seperti F036.
- Menangani bentrok slug halaman lama dengan route yang baru ditambahkan di rilis mendatang (dicatat di Risiko).

## Model data

### Tabel `pages` (model `StaticPage`)

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigint PK | tetap |
| `slug` | string(50) unique | tetap. Kini bebas (aturan K5). Slug halaman sistem terkunci |
| `title` | string(255) | tetap |
| `body` | longText | tetap. HTML yang disanitasi `SanitizeContentHtml` (sama dengan F016). Isi kosong disimpan `''` |
| `status` | string(10), default DB `terbit` | **baru**. `draft` atau `terbit`, divalidasi di Action (portabel, tanpa enum DB). Ditambahkan ke `#[Fillable]` `StaticPage` |
| timestamps | | tetap |

Tanpa soft delete, tanpa FK baru, tanpa index tambahan (tabel kecil).

**Default status (keputusan sadar).** Default kolom DB `terbit` hanya berfungsi saat migrasi, supaya baris lama otomatis terbit tanpa langkah data. Selain itu tidak ada kode yang bergantung pada default DB:
- `CreateStaticPage` selalu mengisi `status` sendiri (bawaan `draft` bila tidak dikirim);
- `StaticPageSeeder` mengisi status eksplisit per halaman sistem;
- `StaticPageFactory` mengisi `status` eksplisit (bawaan `terbit`, dengan state `draft()`).

AC-4 dan AC-3 menguji nilai yang diisi Action dan seeder, bukan default DB.

### Settings

- Baru: `portal.menu_items` (list), `portal.menu_show_register` (bool). Keduanya dibaca dalam satu `Setting::getMany` bersama key profil, dengan cara menambahkannya ke `GetSchoolProfile::KEYS`. Output `GetSchoolProfile` tidak bertambah key baru selain yang disebut di bawah. `GetPortalNavigation` membaca nilainya dari cache scoped `Setting`, sehingga tidak ada query settings tambahan.
- Diubah: item `portal.footer_links` boleh `{label: ?string, page_id: int}`. `GetSchoolProfile` mengembalikan item URL dengan bentuk lama `{label, url}` dan item halaman `{label: ?string, page_id: int}` sesuai urutan tersimpan. Item halaman dengan `page_id` bukan bilangan bulat positif dibuang.

### Migrasi `database/migrations/2026_10_18_000000_add_status_to_pages_table.php`

`up()`:
1. `Schema::table('pages')`: `string('status', 10)->default('terbit')`. Semua baris lama menjadi `terbit`.
2. Data footer (memakai `DB::table`, `json_decode`/`json_encode` seperti migrasi F036, tanpa SQL mentah). Bila baris `pages` ber-slug `visi-misi` ada dan berstatus `terbit` (semua baris lama terbit setelah langkah 1), **apa pun isinya**, dan `portal.footer_links` (null atau list) belum memuat item dengan `page_id` itu dan jumlahnya < 8, tambahkan `{label: null, page_id: <id>}` di akhir. Ini meniru footer lama yang selalu menampilkan tautan Visi & misi. Insert/update mengisi timestamps.

Idempoten: menjalankan ulang tidak menambah item.

`down()`:
- Buang item ber-`page_id` dari `portal.footer_links`. Bila daftar jadi kosong, simpan `null`. Data rusak tidak memicu exception.
- `dropColumn('status')`. Laravel 13 mendukungnya di SQLite, MySQL, dan Postgres.

Tidak ada migrasi untuk `portal.menu_items`. Nilai `null` menghasilkan menu yang sama dengan sekarang (K4).

## Action class

Semua di `app/Actions/Konten/`. Galat lewat `ValidatesContentInput::fail()` dengan pesan `pondok.konten.errors.<reason>`. Semua validasi berjalan sebelum ada data yang ditulis.

1. **`CreateStaticPage`** (baru)
   - Input: `title`, `slug` (opsional), `body`, `status` (opsional, bawaan `draft`).
   - Aturan:
     - judul wajib, maks 255 (`title_required`/`too_long`);
     - slug sesuai K5 (`slug_format`, `too_long`, `slug_taken`, `slug_reserved`);
     - body maks `SanitizeContentHtml::MAX_INPUT_BYTES`, disanitasi, dan media non-YouTube ditolak (`media_source_invalid`), sama dengan `UpdateStaticPage`;
     - status harus `draft` atau `terbit` (`status_invalid`);
     - jumlah halaman sudah `StaticPage::MAX_PAGES` (50) → `page_limit_reached` (K7).
   - `UniqueConstraintViolationException` dipetakan ke `slug_taken` (pola `SaveCategory`).
   - Output: `StaticPage`.
2. **`UpdateStaticPage`** (diperluas, tanda tangan tetap `handle(StaticPage $page, array $data)`)
   - Key `slug` dan `status` **opsional**: bila tidak ada di input, nilainya tidak berubah. Ini menjaga kompatibilitas test F016 dan DemoContent.
   - Halaman sistem: `slug` yang berbeda dari slug sekarang ditolak (`slug_locked`), sedangkan slug yang sama diterima.
   - Halaman biasa: bila `slug` dikirim dan **berbeda** dari slug tersimpan, slug divalidasi seperti di `CreateStaticPage` (format, cadangan, route, `public_path`, unik kecuali dirinya sendiri). Bila sama, tidak ada pemeriksaan slug (K5).
3. **`DeleteStaticPage`** (baru). Ini **guard akhir** untuk halaman sistem. Super-admin melewati policy karena Shield `intercept_gate = before`, jadi penolakan tidak boleh hanya bergantung pada `can()`.
   - Halaman sistem ditolak (`page_system_locked`).
   - Dalam satu transaksi: hapus baris, lalu buang item ber-`page_id` itu dari `portal.menu_items` dan `portal.footer_links`. Daftar yang menjadi kosong disimpan `null`. Penulisan settings memakai `Setting::set`, sehingga cache scoped ikut diperbarui.
4. **`UpdatePortalMenu`** (baru, memakai `SavesProfileSettings`)
   - Input: `items` (list `{type, key | page_id, label?, visible}`) dan `show_register` (bool).
   - Aturan:
     - `type` harus `builtin` atau `page`;
     - `key` bawaan dikenal (`menu_item_invalid`);
     - `page_id` ada di `pages`, boleh draf (`page_not_found`);
     - item bawaan dan halaman tidak boleh duplikat (`menu_item_duplicate`);
     - `label` opsional, hanya untuk halaman, teks polos maks 30 (`too_long`);
     - maks 8 item `visible` (`menu_too_many`, field `items`).
   - Galat berindeks `items.<i>.<field>`.
   - Disimpan sesuai urutan input. Daftar kosong disimpan `null`.
   - Log `portal_menu_updated` hanya bila ada perubahan.
5. **`GetPortalNavigation`** (baru, dipanggil **sekali** per render layout)
   - Output: `items` (list `{label, url, active}`, sudah difilter dan diurutkan sesuai K3/K4), `registerUrl` (`?string`), `footerLinks` (list `{label, url}`, sudah di-resolve).
   - Tepat **satu** query ke `pages` (`whereIn id`, status `terbit`, kolom `id`/`slug`/`title`) untuk item menu dan tautan footer sekaligus. Tidak ada query ke `pages` bila modul konten mati atau tidak ada rujukan halaman.
   - Untuk menu bawaan (null), halaman sistem dirujuk lewat slug. Satu query `whereIn` atas id dan slug digabung menjadi satu query.
   - Halaman `kontak` diperlakukan tidak tersedia bila profil tidak punya data kontak (aturan Kontak, K1). Data kontak dibaca dari `GetSchoolProfile` yang sudah dimuat, tanpa query tambahan.
   - Logika penggabungan K4 berada di satu method publik (mis. `mergedItems()`) yang juga dipakai halaman editor Menu.
6. **`UpdatePortalFooter`** (diubah)
   - Item tautan boleh `{label, url}` (aturan F036 tetap) atau `{label?, page_id}`.
   - Bila `url` dan `page_id` keduanya diisi atau keduanya kosong, ditolak (`link_target_invalid`). `page_id` yang tidak ada ditolak (`page_not_found`).
   - Label maks 60, wajib untuk URL, dan opsional untuk halaman.
7. **`ShowPublicStaticPage`** (diubah): mencari berdasarkan slug dengan status `terbit`, selain itu 404. Halaman `kontak` tanpa data kontak juga 404 (aturan Kontak). Tautan "terkait" profil dan visi-misi hanya muncul bila halaman tujuan terbit, dengan label dari judul halaman tujuan.
8. **`BuildSitemap`** (diubah): memuat halaman `terbit` dengan isi tidak kosong. Aturan isi kosong sama dengan sekarang. Halaman lain di luar tiga slug kini ikut. `kontak` hanya dimuat bila isinya tidak kosong **dan** tersedia menurut aturan Kontak.
9. **`GetPortalHomeData`** (diubah): halaman `profil` dipakai hanya bila `terbit`.

Teks bebas (judul halaman, label menu, label tautan) di-escape Blade `{{ }}`. Satu-satunya keluaran `{!! !!}` tetap body tersanitasi.

## UI (Filament / Blade)

Wajib: baca `docs/design-guide.md`, jalankan alur §13 (anti-ui-slop), dan checklist §0. Teks lewat `lang/id/pondok.php`. Placeholder dan helper tidak memuat contoh Arab, ayat, salam, maupun nama lembaga (prinsip F036).

### Panel admin

**`StaticPageResource`** (grup Konten, label tetap "Halaman"):
- Tabel:
  - kolom judul, slug (mono), dan status sebagai badge (§4: Konten `draft` → `gray`, `terbit` → `success`; label `pondok.static_page.statuses.*`);
  - penanda "Halaman sistem" (badge `gray`) untuk `isSystem()`, dan diubah;
  - empty state tetap.
- Aksi tabel: Ubah, dan Hapus (`danger`, `requiresConfirmation`). Hapus **wajib** memakai `->hidden(fn (StaticPage $record): bool => $record->isSystem())` secara eksplisit, karena super-admin melewati policy. Aturan yang sama berlaku untuk aksi header Hapus di halaman Ubah. Deskripsi modal menjelaskan akibatnya: "Halaman dihapus permanen dan dilepas dari menu serta tautan footer. Alamatnya akan menampilkan halaman tidak ditemukan." Tidak ada aksi massal.
- Halaman Buat (`/admin/static-pages/create`):
  - judul;
  - slug opsional, dengan helper "Kosongkan untuk dibuat dari judul. Hanya huruf kecil, angka, dan tanda hubung.";
  - status (`ToggleButtons` Draf/Terbit, bawaan Draf);
  - isi (`RichEditor`, toolbar `PostForm::TOOLBAR`).
  - Memanggil `CreateStaticPage`. Galat Action ditampilkan di field lewat `ShowsActionErrorsOnPageForm`.
- Halaman Ubah:
  - field sama dengan halaman Buat. Untuk halaman sistem, slug `disabled` dengan helper "Alamat halaman sistem tidak bisa diubah.";
  - aksi header Hapus dengan `hidden(isSystem)` eksplisit, seperti aksi tabel;
  - memanggil `UpdateStaticPage`.
- Notifikasi sukses menyebut objek, misalnya "Halaman Pengurus Al-Khair dihapus."

**Cluster Profil lembaga, halaman baru "Menu"** (`Clusters/InstitutionProfile/Pages/Menu.php`, slug `menu`, `navigationSort` 5, turunan `InstitutionProfilePage`):
- Section "Menu utama":
  - `Repeater` `items`, `reorderable`, `addable(false)`, `deletable(false)`;
  - isinya **semua** item bawaan dan semua halaman, digabung sesuai K4;
  - tiap baris berisi nama item (teks, bukan input; halaman draf diberi keterangan "Draf, belum tampil"), `Toggle` "Tampilkan", dan `TextInput` label singkat (hanya untuk halaman, maks 30, placeholder = judul halaman);
  - helper: "Maksimal 8 item tampil. Item dari modul yang nonaktif tidak tampil di portal."
- Section "Tombol": `Toggle` "Tampilkan tombol Daftar PSB".
- Satu tombol simpan, memanggil `UpdatePortalMenu`. Notifikasi "Menu portal disimpan."

**Halaman Footer (F036)**: setiap baris repeater tautan mendapat `ToggleButtons` "Jenis" (Halaman / Tautan). Jenis Halaman menampilkan `Select` halaman (judul, dengan keterangan draf) dan label opsional. Jenis Tautan menampilkan label dan URL wajib seperti sekarang.

### Portal (Blade)

- **`layouts/public.blade.php`** memanggil `GetPortalNavigation` sekali dan meneruskan hasilnya ke `nav-links` (dua kali) dan `footer`.
  - Nav desktop tampil mulai `lg` (sebelumnya `md`), dan menu `<details>` dipakai di bawah `lg`. Alasannya, menu bisa sampai 8 item ditambah Masuk/Daftar. Breakpoint logo F036 (`md`) tidak berubah.
- **`nav-links.blade.php`** tidak lagi menghitung item sendiri. Ia me-render `items`, lalu Masuk, lalu Daftar (bila `registerUrl`), dengan `aria-current="page"` pada item aktif. Kelas, target sentuh 44px, dan fokus tetap.
- **`footer.blade.php`** me-render `footerLinks` hasil resolve, lalu tautan sistem (status PSB, verifikasi surat). Tautan `visi-misi` yang ditulis langsung di kode dihapus.
- **`pages/show.blade.php`**: tautan terkait mengikuti aturan Action no. 7. Lainnya tetap.
- Cek visual 360px, 1024px, dan desktop dengan 8 item berlabel 30 karakter: tidak ada scroll horizontal. Bila belum dicek, laporkan.

### `lang/id/pondok.php`

Teks baru (antarmuka saja):
- `static_page.status`, `static_page.statuses.{draft,terbit}`, `static_page.system_badge`, `static_page.slug_helper_create`, `static_page.slug_helper_locked`, `static_page.delete_*`, `static_page.saved/created/deleted`;
- `portal.nav.psb_info`;
- `school_profile.pages.menu` serta label dan helper halaman Menu;
- tambahan Footer: `link_type`, `link_type_page`, `link_type_url`, `link_page`;
- `konten.errors.{slug_format, slug_reserved, slug_locked, status_invalid, page_system_locked, page_not_found, menu_item_invalid, menu_item_duplicate, menu_too_many, link_target_invalid}`. Kalimatnya menyebut cara memperbaiki (§9).

Kunci `static_page.slug_helper` lama ("Alamat halaman tetap dan tidak bisa diubah.") diganti. `portal.nav.profile` dan `portal.nav.vision` dihapus bila setelah perubahan tidak lagi dipakai (grep).

## Permission & cakupan

- `RolePermissionSeeder::LIMITED_RESOURCE_ACTIONS['StaticPage']` menjadi `['viewAny', 'view', 'create', 'update', 'delete']`. Permission baru `create:static-page` dan `delete:static-page`. **Tidak ada** `delete-any:static-page`.
- `admin-konten` mendapat seluruh permission `StaticPage` (sudah memakai `resourcePermissions('StaticPage')`). `super-admin` mendapat semua. Peran lain tidak mendapat apa-apa. Komentar seeder dan policy diperbarui.
- `StaticPagePolicy`:
  - `create` → `can('create:static-page')`;
  - `delete` → `can('delete:static-page') && ! $page->isSystem()`. Untuk super-admin, Gate lolos lebih dulu (`intercept_gate = before`), sehingga yang menjaga adalah `hidden()` di UI dan `DeleteStaticPage`;
  - `deleteAny` → `false`.
- Resource tetap memakai `BelongsToModule` (`konten`). Modul mati berarti 403 dan menu hilang.
- Halaman Menu memakai `view:school-profile` (super-admin, admin-sekolah, admin-konten) seperti halaman cluster F036 lainnya, dan tidak terikat saklar modul.
- Portal: item dan halaman mengikuti saklar `konten`, dan `psb_info` serta Daftar mengikuti saklar `psb`. Data tidak per jenjang, sehingga `unit_user` tidak berlaku.

## Kriteria penerimaan (bisa dites)

Catatan umum: "nav" berarti **kedua** `<nav>` header (desktop `aria-label` `pondok.portal.nav.label` dan seluler `mobile_label`). Urutan diassert dari teks `<a>` di dalam masing-masing nav. Bila tidak disebut lain, modul `konten` aktif.

### Migrasi & seeder

- **AC-1** DB berisi tiga halaman lama tanpa kolom `status` (migrasi sebelum F037) → `up()` → kolom `status` ada, ketiga baris `terbit`, judul dan isi tidak berubah. `down()` → kolom hilang tanpa exception.
- **AC-2** Migrasi footer: `visi-misi` berisi `<p>Visi</p>` dan `portal.footer_links` = dua tautan URL → `up()` → `portal.footer_links` = dua tautan semula ditambah `{label: null, page_id: <id visi-misi>}` di akhir (JSON valid). Menjalankan `up()` kedua kali tidak menambah item. Bila isi `visi-misi` `''` tetapi halamannya terbit → item **tetap** ditambah. Bila `visi-misi` berstatus `draft` (diubah test sebelum langkah 2) → tidak ditambah. Bila sudah ada 8 tautan → tidak ditambah dan tanpa exception. `down()` → item ber-`page_id` hilang dan tautan URL tetap. `portal.footer_links` bernilai `null` atau JSON rusak saat `down()` → tanpa exception.
- **AC-3** `StaticPageSeeder` di DB kosong → tiga baris: `profil` dan `visi-misi` berstatus `draft`, `kontak` `terbit`, judul dari `pondok.static_page.defaults.*`, isi `''`. Dijalankan lagi setelah admin mengubah status dan isi → baris tidak berubah. `portal.menu_items` dan `portal.menu_show_register` tidak ditulis.

### `CreateStaticPage`

- **AC-4** Judul "Pengurus Yayasan", isi `<p onclick="x()">Isi</p><script>alert(1)</script>`, tanpa slug dan tanpa status → satu baris dengan slug `pengurus-yayasan`, status `draft`, dan isi tanpa `<script>` maupun `onclick`. Isi berisi iframe ke host non-YouTube → `ValidationException` `media_source_invalid` dan tidak ada baris.
- **AC-5** Slug eksplisit `"  Selayang-Pandang "` dan status `terbit` → slug `selayang-pandang`, status `terbit`.
- **AC-6** Slug eksplisit dari setiap nilai `StaticPage::RESERVED_SLUGS` (termasuk `berita`, `galeri`, `video`, `psb`, `info-psb`, `sitemap`, `robots`, `verifikasi-surat`, `admin`) → `ValidationException` pada `slug` dengan pesan `slug_reserved`, tanpa baris. Hal yang sama berlaku untuk slug yang sama dengan segmen pertama route yang didaftarkan di test (`Route::get('rute-uji-f037/{x}', ...)` → slug `rute-uji-f037`), dan untuk nama folder yang dibuat test di `public_path()` (dibersihkan setelah test).
- **AC-7** Tanpa slug, judul "Berita" → slug `berita-2`. Judul "Kontak" (halaman sistem `kontak` ada) → `kontak-2`. Slug eksplisit `kontak` → `slug_taken`.
- **AC-8** Slug eksplisit `sitemap.xml`, `robots.txt`, `a/b`, `-x`, `x-`, `a--b`, `ü-x` → `slug_format`. Slug 51 karakter valid → `too_long`. Tidak ada baris dibuat.
- **AC-9** Judul kosong → `title_required`. Judul 256 karakter → `too_long`. Status `published` → `status_invalid`. Isi lebih dari `MAX_INPUT_BYTES` → `too_long`. Sudah ada 50 halaman (termasuk 3 halaman sistem) → `page_limit_reached` dan tidak ada baris ke-51.

### `UpdateStaticPage` dan `DeleteStaticPage`

- **AC-10** Halaman biasa `pengurus`: ubah judul, slug ke `pengurus-yayasan`, dan status `terbit` → tersimpan. Ubah slug ke `berita` → `slug_reserved`, ke slug halaman lain → `slug_taken`, dan baris tidak berubah. Input hanya `{title, body}` (bentuk F016) → slug dan status tidak berubah.
  - **Slug lama yang kini bentrok:** halaman `rute-uji-f037` dibuat, lalu test mendaftarkan `Route::get('rute-uji-f037/{x}', ...)` dan membuat folder `public_path('rute-uji-f037')`. Kirim judul dan isi baru dengan `slug` sama (atau tanpa key `slug`) → tersimpan tanpa galat. Mengubah slug halaman lain ke `rute-uji-f037` → `slug_reserved`.
- **AC-11** Halaman sistem `profil`: slug `tentang` → `ValidationException` `slug_locked` dan tidak ada perubahan. Slug `profil` (sama) bersama judul baru → tersimpan. Status `draft` → tersimpan.
- **AC-12** Halaman biasa X dirujuk `portal.menu_items` (bersama `home` dan halaman Y) dan `portal.footer_links` (bersama satu URL) → `DeleteStaticPage` → baris X tidak ada (hard delete, bukan soft delete: tidak ada kolom `deleted_at`), item X hilang dari kedua daftar, item lain tetap urut. Bila X satu-satunya item → key bernilai `null`.
- **AC-13** `DeleteStaticPage` pada `profil`, `visi-misi`, atau `kontak` → `ValidationException` `page_system_locked` dan baris tetap ada.

### Halaman publik & routing

- **AC-14** Halaman `pengurus` `terbit` berisi → `GET /pengurus` 200, dengan `<h1>` judul, isi tersanitasi, dan canonical `/pengurus`. Status `draft` → 404. Slug tidak ada → 404. Modul konten mati → 404. `GET /Pengurus` dan slug 51 karakter → 404 tanpa 500.
- **AC-15** Dengan halaman terbit `pengurus` ada: `GET /admin` → redirect ke login (bukan 404), `GET /up` → 200, `/sitemap.xml` dan `/robots.txt` → 200 dengan content-type semula, `/berita` → 200, `/verifikasi-surat` (modul dokumen aktif) → 200, `/psb` (modul psb aktif) → 200.
- **AC-16** Judul halaman, label menu, dan label tautan footer berisi `<script>alert(1)</script>` → HTML `/`, `/pengurus`, dan nav memuat bentuk ter-escape tanpa tag mentah.
- **AC-17** Halaman sistem:
  - `kontak` draf (dengan data kontak) → `/kontak` 404 dan tidak ada item Kontak di nav;
  - `profil` draf dengan isi → beranda tanpa seksi profil (`seksi-profil`) dan tidak ada `href` ke `/profil` di HTML;
  - `profil` terbit dan `visi-misi` draf → `/profil` tanpa tautan ke `/visi-misi`;
  - keduanya terbit → `/profil` memuat tautan ke `/visi-misi` berlabel judul halaman `visi-misi`.
- **AC-17a** Aturan Kontak: `kontak` terbit dan berisi `<p>Jam layanan</p>`, sedangkan profil tanpa `address`, `phone`, `email`, `map_url`, dan `contacts` →
  - `/kontak` 404;
  - tidak ada item Kontak di kedua nav;
  - tautan footer ber-`page_id` kontak tidak dirender;
  - `/kontak` tidak ada di sitemap.

  Lalu salah satu saja yang diisi (dataset: hanya `email`, hanya `map_url`, hanya satu `contacts`) → `/kontak` 200 dengan kartu kontak, dan item Kontak tampil.

### Navigasi portal

- **AC-18** `portal.menu_items` null, modul psb aktif dengan gelombang terbuka, ketiga halaman sistem terbit dengan judul bawaan, dan `school.email` terisi → nav berurutan: Beranda, Profil, Berita, Galeri, Video, Kontak, Masuk, Daftar. Sama dengan perilaku sebelum F037. "Info PSB" tidak tampil karena route `psb.info` belum ada.
- **AC-19** Instalasi baru (`portalBoot` dengan seeder F037, tanpa ubahan, profil tanpa data kontak) → nav: Beranda, Berita, Galeri, Video, Masuk. Tidak ada "Profil", "Visi", maupun "Kontak". Setelah `school.email` diisi → Kontak tampil setelah Video.
- **AC-20** `portal.menu_items` = `[page pengurus (label "Pengurus", visible), builtin news (hidden), builtin home, page selayang-pandang (tanpa label), page kontak]`, semua halaman terbit → nav: Pengurus, Beranda, Selayang Pandang, Kontak, Galeri, Video, Masuk. Galeri dan Video ditambahkan di akhir karena tidak ada di daftar, dan Berita disembunyikan.
- **AC-21** Item tidak tampil bila:
  - halamannya draf;
  - baris halaman dihapus langsung dari DB (rujukan basi), dan dalam kasus ini tanpa 500;
  - `visible: false`;
  - modul konten mati: hanya Beranda dan Masuk(/Daftar) yang tampil, dan **tidak ada query** ke `pages`, `posts`, `albums`, maupun `videos` pada `GET /`.
  - `psb_info` tampil hanya bila modul psb aktif **dan** test mendaftarkan route bernama `psb.info`. Modul psb mati → tidak tampil walau route ada.
- **AC-22** Pada `/pengurus`, item Pengurus di kedua nav ber-`aria-current="page"` dan item lain tidak. Pada `/berita/<slug>`, item Berita aktif.
- **AC-23** `portal.menu_show_register` `false` → tidak ada tombol Daftar walau gelombang terbuka. `null` atau `true` → Daftar tampil seperti F017.
- **AC-24** Menu berisi 4 item halaman dan footer berisi 2 tautan halaman → `GET /berita` menjalankan tepat **satu** query ke tabel `pages`, dan tidak ada query `settings` dengan SQL dan binding yang sama dua kali.
- **AC-25** Halaman terbit berjudul 80 karakter tanpa label menu → teks item nav maksimal 30 karakter (diakhiri `...`), sedangkan `<h1>` halaman memuat judul utuh.

### `UpdatePortalMenu`

- **AC-26** Input semua item bawaan dan 3 halaman (satu draf) dalam urutan acak, dengan visibilitas campuran, label "Pengurus" pada satu halaman, dan `show_register: false` → `portal.menu_items` tersimpan sesuai urutan input dengan bentuk `{type, key|page_id, label, visible}`, `portal.menu_show_register = false`, dan satu log `portal_menu_updated`. Input sama dikirim ulang → tidak ada log baru.
- **AC-27** Input berisi salah satu dari: key bawaan `login`, `type: "link"`, `page_id` tidak ada, key `news` dua kali, halaman yang sama dua kali, label 31 karakter, atau 9 item `visible` (dihitung apa pun status tampilnya, misalnya termasuk halaman draf dan `psb_info` tanpa route) → `ValidationException` pada field berindeks (`items.<i>.key`, `items.<i>.page_id`, `items.<i>.label`) atau `items` (untuk `menu_too_many`), dan settings tidak berubah.

- **AC-27a** Penggabungan dengan batas: `portal.menu_items` tersimpan berisi `home`, `news`, `gallery`, `videos`, dan 4 halaman, semuanya `visible` (8), tanpa `psb_info` → `mergedItems()` menambahkan `psb_info` di akhir dengan `visible: false`. Editor Menu dibuka lalu langsung `save` → sukses tanpa `menu_too_many`. Bila daftar tersimpan hanya berisi 7 visible → `psb_info` ditambahkan `visible: true`. Daftar manual berisi 10 item visible → portal hanya menampilkan 8 pertama.
- **AC-27b** Editor saat `portal.menu_items = null`, dengan halaman sistem (profil draf) dan dua halaman biasa → baris editor berurutan `home`, `profil`, `news`, `gallery`, `videos`, `psb_info`, `kontak` (ketujuhnya visible), lalu dua halaman biasa (hidden, urut judul). `save` tanpa perubahan → `portal.menu_items` berisi urutan itu, dan HTML nav `GET /` sama dengan sebelum disimpan.

### Footer

- **AC-28** `UpdatePortalFooter` menerima `{page_id: <id>, label: ""}` (disimpan label `null`) dan `{label: "Visi", page_id: <id>}`. Item dengan `url` **dan** `page_id`, atau tanpa keduanya → `link_target_invalid`. `page_id` tidak ada → `page_not_found`. Aturan tautan URL F036 tetap (test F036 AC-30/31 hijau).
- **AC-29** Footer dengan tautan halaman `pengurus` tanpa label → `<a href="/pengurus">` dengan teks judul halaman. Slug kemudian diubah ke `pengurus-yayasan` → href mengikuti. Halaman draf, terhapus, atau modul konten mati → tautan tidak dirender. `portal.footer_links` kosong dan `visi-misi` terbit → footer **tidak** memuat `href` ke `/visi-misi`.
- **AC-30** `GetSchoolProfile` dengan `portal.footer_links` = `[{label: "A", url: "/berita"}, {page_id: 5}, {page_id: "x"}, {page_id: -1}]` → `footer.links` = `[{label: "A", url: "/berita"}, {label: null, page_id: 5}]`.

### Sitemap

- **AC-31** Halaman terbit berisi (`pengurus` dan `profil`) → keduanya ada di `sitemap.xml` dengan `lastmod`. Halaman draf, halaman terbit berisi kosong, dan halaman yang dihapus → tidak ada. Modul konten mati → tidak ada URL halaman sama sekali.

### Panel admin

- **AC-32** `admin-konten`: Livewire `CreateStaticPage` (halaman resource) dengan judul, slug kosong, status Terbit, dan isi → baris tersimpan, notifikasi sukses, dan redirect ke Ubah. Slug `berita` → error pada field `data.slug` dengan pesan `slug_reserved`, tanpa exception dan tanpa baris.
- **AC-33** Diuji untuk **`admin-konten` dan `super-admin`** (dataset). Tabel resource menampilkan badge status (Draf/Terbit) dan badge "Halaman sistem" untuk tiga slug sistem. Aksi Hapus **tidak terlihat** (`assertActionHidden`) untuk halaman sistem, baik di tabel maupun di header halaman Ubah, dan terlihat untuk halaman biasa. Memanggil paksa aksi hapus tabel pada halaman sistem (`callTableAction`) → baris tetap ada. Memanggil hapus pada halaman biasa meminta konfirmasi, lalu baris terhapus dan notifikasi menyebut judulnya. Di halaman Ubah halaman sistem, field slug `disabled`.
- **AC-34** `bendahara`, `admin-psb`, `admin-sekolah`, `kesantrian`, `musyrif`, `petugas-pos`, dan `wali` → 403 untuk `/admin/static-pages/create`. Modul konten mati → `admin-konten` mendapat 403. `RolePermissionSeeder` dijalankan dua kali → `admin-konten` punya `create:static-page` dan `delete:static-page`, `delete-any:static-page` tidak ada di tabel permission, dan tidak ada duplikasi. `StaticPagePolicy::delete` dipanggil **langsung** (`(new StaticPagePolicy)->delete($superAdmin, $profil)`, bukan lewat Gate) bernilai `false` untuk halaman sistem. Guard akhir: `DeleteStaticPage` atas halaman sistem dengan aktor super-admin → `page_system_locked` (AC-13).
- **AC-35** Halaman Menu: `super-admin`, `admin-sekolah`, dan `admin-konten` → 200; peran lain → 403 dan item navigasi Menu tidak tampil. Form memuat semua item bawaan (label dari `lang/id`) dan semua halaman (halaman draf berketerangan draf). Repeater tidak punya tombol tambah maupun hapus. Urutan diubah, satu item disembunyikan, lalu `save` → `portal.menu_items` sesuai form dan notifikasi sukses. 9 item tampil → error tampil di form tanpa exception.
- **AC-36** Halaman Footer: satu baris jenis Halaman dengan halaman terpilih dan satu baris jenis Tautan, lalu `save` → tersimpan sesuai AC-28. Form dibuka ulang → jenis dan pilihan terisi kembali.
- **AC-37** HTML halaman resource (daftar, buat, ubah) dan halaman Menu tidak memuat kunci terjemahan mentah (`pondok.`). Placeholder dan helper text tidak memuat huruf Arab, "QS", "Bismillah", "Assalamu", maupun "Ahlan".

### Demo & tanpa teks konten bawaan

- **AC-38** `db:seed --class=DemoSeeder` → halaman terbit:
  - `profil` berjudul "Tentang Yayasan";
  - `pengurus` berjudul "Pengurus Al-Khair";
  - `selayang-pandang` berjudul "Selayang Pandang";
  - `visi-misi` dan `kontak`.

  `portal.menu_items` → nav: Beranda, Tentang Yayasan, Pengurus Al-Khair, Selayang Pandang, Kontak, Masuk(, Daftar). Info PSB ikut tampil setelah F038. Berita, Galeri, dan Video disembunyikan dari header. `portal.footer_links` memuat tautan halaman `visi-misi`. Isi tidak memuat istilah terlarang manhaj CLAUDE.md (Maulid, Isra' Mi'raj, Nuzulul Qur'an, Tahun Baru Hijriah, Nisfu Sya'ban, haul, tahlilan, harlah, hadrah, shalawatan). Seeder dijalankan dua kali → jumlah halaman dan isi menu sama.
- **AC-39** Instalasi baru tanpa DemoSeeder → tabel `pages` hanya berisi tiga halaman sistem, `portal.menu_items` null, dan semua label nav berasal dari `lang/id`. Pemindaian nilai `lang/id/pondok.php` (F036 AC-55) tetap hijau dengan kunci baru F037.

## Rencana test

**Test lama yang terdampak** diperbarui di fase RED oleh red-tester. Green-coder tidak mengubah test.

| Berkas | Alasan |
|---|---|
| `tests/Feature/Filament/Resources/StaticPages/StaticPageResourceTest.php` | AC-32 F016 "cannot create or delete static pages" diganti: create kini boleh, dan delete hanya untuk halaman non-sistem |
| `tests/Feature/Database/Seeders/KontenSeedersTest.php` | Seeder kini mengisi `status` (profil/visi-misi draf) |
| `tests/Feature/Database/Seeders/RolePermissionSeederTest.php` (±baris 79) | Daftar permission `static-page` bertambah `create` dan `delete` |
| `tests/Feature/Filament/KontenAccessTest.php`, `KontenVerifyGapsTest.php` | Asersi izin static page |
| `tests/Feature/Filament/KontenTranslationsTest.php` | `slug_helper` berubah, dan kunci `nav.profile`/`nav.vision` mungkin dihapus |
| `tests/Feature/Actions/Konten/UpdateStaticPageTest.php` | Tetap valid (slug/status opsional). Periksa asersi "slug tetap" |
| `tests/Feature/Http/Portal/PortalRoutingTest.php` | AC-2 F017 mengharapkan `/profil` dan `/visi-misi` 200: helper `portalBoot` perlu menerbitkan halaman sistem, atau dataset disesuaikan |
| `tests/Feature/Http/Portal/NoBuiltInContentTest.php` | F036 AC-54: `GET /profil` dan `GET /kontak` kini 404 di instalasi baru (profil draf, kontak tanpa data kontak) |
| `tests/Feature/Http/Portal/InstitutionFooterContactTest.php` (F036 AC-50) | Kasus "semua kosong → empty-state" pada `/kontak` kini 404 (aturan Kontak). Kasus dengan data kontak tetap 200 |
| `tests/Feature/Http/Portal/InstitutionFooterContactTest.php`, `InstitutionVerifyGapsTest.php`, `PortalAccessibilityTest.php`, `GalleryVideoPageTest.php`, `SeoTest.php`, `HomePageTest.php`, `tests/Feature/Http/HomePageTest.php` | Tautan `visi-misi` di footer dihapus, `nav.profile`/`nav.vision`, breakpoint nav `lg`, sitemap halaman, dan seksi profil bergantung status |
| `tests/Feature/Database/Seeders/DemoSeederTest.php`, `InstallSeederTest.php` | Halaman demo baru dan jumlah halaman |
| `tests/Pest.php` | Helper `portalBoot`/`kontenBoot` (status halaman sistem), helper baru `staticPage()`/`menuSet()` |

**Test baru:**

| AC | File | Nama test (usulan) |
|---|---|---|
| 1–2 | `tests/Feature/Database/Migrations/AddStatusToPagesTableTest.php` | `adds published status to existing pages`, `keeps the vision page in footer links idempotently`, `down removes page links safely` |
| 3 | `tests/Feature/Database/Seeders/KontenSeedersTest.php` | `seeds system pages with draft profile and published contact` |
| 4–9 | `tests/Feature/Actions/Konten/CreateStaticPageTest.php` | `creates a draft page with sanitized body`, `normalizes explicit slug`, `rejects reserved slugs`, `suffixes generated slugs that are reserved or taken`, `rejects malformed slugs`, `validates title status and body size` |
| 10–11 | `tests/Feature/Actions/Konten/UpdateStaticPageTest.php` | `updates slug and status of a free page`, `keeps an unchanged slug that now collides`, `locks system page slug` |
| 12–13 | `tests/Feature/Actions/Konten/DeleteStaticPageTest.php` | `hard deletes and detaches menu and footer references`, `refuses to delete system pages` |
| 14–17a | `tests/Feature/Http/Portal/FreeStaticPageTest.php` | `serves only published pages`, `does not shadow reserved routes`, `escapes page titles and labels`, `system pages follow their status`, `contact page needs contact data` |
| 18–25 | `tests/Feature/Http/Portal/PortalMenuTest.php` | `default menu matches previous header`, `fresh install hides draft profile`, `stored order and visibility drive both navs`, `hides items for disabled modules and missing pages`, `marks the active item`, `register button can be hidden`, `resolves pages with a single query`, `truncates long page titles` |
| 26–27b | `tests/Feature/Actions/Konten/UpdatePortalMenuTest.php` (27b juga `tests/Feature/Filament/Pages/InstitutionMenuTest.php`) | `saves ordered menu and logs changes`, `rejects invalid menu items`, `appends new builtins without exceeding the limit`, `editor default equals current header` |
| 28–30 | `tests/Feature/Actions/Konten/UpdatePortalFooterTest.php`, `tests/Feature/Http/Portal/InstitutionFooterContactTest.php`, `tests/Feature/Actions/Konten/GetSchoolProfileTest.php` | `accepts page links`, `rejects ambiguous link targets`, `page links follow slug and status`, `reads page links tolerantly` |
| 31 | `tests/Feature/Http/Portal/SeoTest.php` | `sitemap lists published free pages` |
| 32–34 | `tests/Feature/Filament/Resources/StaticPages/StaticPageResourceTest.php` | `content admin creates a page`, `shows reserved slug error on the form`, `system pages have no delete action`, `deletes a free page after confirmation`, `other roles cannot create pages` |
| 35 | `tests/Feature/Filament/Pages/InstitutionMenuTest.php` | `profile roles open the menu page`, `reorders and hides menu items`, `shows validation errors for too many items` |
| 36 | `tests/Feature/Filament/Pages/InstitutionProfileTest.php` | `footer repeater links a page` |
| 37 | `tests/Feature/Filament/KontenTranslationsTest.php` | `page and menu screens have no raw keys or content examples` |
| 38–39 | `tests/Feature/Database/Seeders/DemoSeederTest.php`, `tests/Feature/Http/Portal/NoBuiltInContentTest.php` | `seeds brief menu pages idempotently`, `fresh install has only system pages and interface labels` |

## Urutan implementasi

1. Migrasi `add_status_to_pages_table` (skema dan data footer) dan model `StaticPage` (konstanta, `isSystem`, scope `published`), ditambah `StaticPageFactory` (AC-1–2).
2. `StaticPageSeeder` dengan status (AC-3).
3. Validasi slug bersama (format, cadangan dari daftar, route, dan `public_path`), diintegrasikan dengan `GenerateSlug` (AC-6–8).
4. `CreateStaticPage` (AC-4–9), lalu perluasan `UpdateStaticPage` (AC-10–11), lalu `DeleteStaticPage` (AC-12–13).
5. Route `/{page}` regex, `ShowPublicStaticPage`, `GetPortalHomeData` (profil terbit), dan tautan terkait (AC-14–17).
6. `GetSchoolProfile` (prefetch key menu, item footer halaman) dan `UpdatePortalFooter` (AC-28, 30).
7. `GetPortalNavigation` dan `UpdatePortalMenu` (AC-18–27).
8. Layout, `nav-links`, `footer` (breakpoint `lg`, hapus tautan visi-misi bawaan) (AC-18–25, 29).
9. `BuildSitemap` (AC-31).
10. `RolePermissionSeeder` dan `StaticPagePolicy` (AC-34).
11. `StaticPageResource` (Buat, Hapus, kolom status) (AC-32–33).
12. Halaman cluster Menu dan repeater Footer (AC-35–36).
13. `lang/id/pondok.php` (AC-37, 39).
14. DemoSeeder (`DemoData::pages()` dipecah sesuai brief, menu dan footer demo) (AC-38).
15. `docs/erd.md` dan `docs/prd.md` §3.1.
16. Pint, test terkait, checklist design-guide §0, cek visual 360px/1024px/desktop (atau laporkan belum).

## Risiko & pertanyaan terbuka

Risiko:
- **Konflik berkas dengan F035** (`portal-identitas-visual`, worktree `.claude/worktrees/infallible-hamilton-d9c6ef`, belum di main). **F037 di-merge dulu, F035 rebase.** Berkas yang kemungkinan dipakai bersama:
  - `resources/views/layouts/public.blade.php` (blok header/nav);
  - `resources/views/portal/partials/nav-links.blade.php` (ditulis ulang total);
  - `resources/views/portal/partials/footer.blade.php`;
  - `resources/views/portal/pages/show.blade.php`;
  - `lang/id/pondok.php`;
  - `app/Filament/Clusters/InstitutionProfile/Pages/Footer.php`.

  F037 tidak menyentuh token warna atau font. Perubahan kelas di header terbatas pada breakpoint nav (`md` → `lg`).
- **Route catch-all `/{page}`** bisa membayangi route satu segmen yang didaftarkan setelahnya (mis. panel Filament `/admin`). AC-15 menjaga hal ini, dengan rencana cadangan negative lookahead (K5).
- **Bentrok di masa depan:** rilis baru bisa menambah route yang sama dengan slug halaman yang sudah ada. Route eksplisit menang, sehingga halaman itu tidak terjangkau. Mitigasinya daftar cadangan yang luas (`wali`, `api`, `login`, ...). Penanganan otomatis di luar ruang lingkup.
- **Perubahan tampilan instalasi baru:** "Profil" tidak lagi muncul di menu sampai admin menerbitkannya. Ini disengaja (prinsip F036) dan memecahkan beberapa test F017/F036 yang diperbarui di RED.
- **Breakpoint nav `md` → `lg`:** pada lebar 768–1023px, menu kini memakai `<details>`. Ini diperlukan agar 8 item ditambah Masuk/Daftar tidak meluap.
- **ID halaman dipakai ulang oleh DB** setelah dihapus: dicegah karena `DeleteStaticPage` membersihkan rujukan di settings. Rujukan basi dari data manual dibaca secara toleran (AC-21).
- **Kontrak dengan F038:** route bernama `psb.info` dengan path `/info-psb`, didaftarkan **di luar** grup prefix `psb` (grup itu berprefix `psb/`) dan sebelum `/{page}`. Bila F038 memakai nama lain, item `psb_info` tidak akan pernah tampil. Kontrak ini sudah dicatat di `notes` F038 di `feature_list.json`.
- **Urutan merge dengan F035:** F035 belum ada di main maupun `feature_list.json`, sehingga urutannya dicatat di sini saja. **F037 di-merge dulu, lalu F035 rebase.**
- **Kontak tanpa data menjadi 404** (aturan Kontak): pengunjung yang punya tautan lama ke `/kontak` di instalasi tanpa data kontak akan mendapat 404. Dampaknya kecil, karena halaman itu sebelumnya hanya berisi empty-state.

### Keputusan (dapat dikonfirmasi user)

- **D1. Halaman profil, visi-misi, dan kontak: DIKONFIRMASI USER (16 Okt 2026, "pertahankan").** Ketiganya tetap halaman sistem: tidak bisa dihapus, slug terkunci, tetapi bisa draf (K1). Di instalasi baru, `profil` dan `visi-misi` mulai sebagai draf dan `kontak` terbit, dengan aturan Kontak. Instalasi lama tetap terbit. Bukan lagi keputusan sementara.
- **D2. Tombol header (default, sementara).** "Daftar" (PSB) bisa disembunyikan lewat halaman Menu. "Masuk" selalu tampil.
- **D3. Menu demo (default, sementara).** Mengikuti brief (Beranda, Tentang Yayasan, Pengurus Al-Khair, Selayang Pandang, Info PSB, Kontak). Berita, Galeri, dan Video disembunyikan dari header tetapi tetap terjangkau dari beranda dan footer.
- **D4. Aturan Kontak (keputusan orkestrator).** Halaman dan item Kontak hanya tampil bila profil punya data kontak (K1).
- **D5. Batas 50 halaman per instalasi (K7).** Usulan product-manager atas Review #1 temuan 8.

Tidak ada pertanyaan terbuka yang menahan fase RED.

## Riwayat revisi

- 2026-10-16 DRAFT awal (product-manager). Fitur diklaim dengan owner `orchestrator-bc5d2383`.
- 2026-10-16 REVISED atas Review #1 dan arahan orkestrator:
  - #1 major: pemeriksaan slug hanya saat slug berubah (K5, `UpdateStaticPage`). AC-10 ditambah kasus slug lama yang kini bentrok.
  - #2 major: `hidden(isSystem)` eksplisit pada aksi Hapus tabel dan header. `DeleteStaticPage` sebagai guard akhir. AC-33 diuji untuk admin-konten dan super-admin. AC-34 memakai pemanggilan policy langsung.
  - #3 major: batas 8 menghitung semua item `visible`. Item bawaan baru visible hanya bila total < 8. Isi editor saat `null` ditetapkan (K4). AC-27a dan AC-27b baru, AC-27 diperjelas.
  - #4 minor, keputusan orkestrator: aturan Kontak (K1, D4). AC-17a baru, AC-18/19 disesuaikan, test F036 AC-50/54 masuk daftar terdampak.
  - #5 minor: `status` ke Fillable, dan alasan default DB `terbit` dijelaskan di Model data.
  - #6 minor: migrasi menambah tautan visi-misi bila terbit, apa pun isinya. AC-2 disesuaikan.
  - #7 minor: kontrak F038 (`psb.info`, `/info-psb`, di luar prefix `psb`) dicatat di notes F038 di `feature_list.json`, dan urutan merge F035 di Risiko.
  - #8 minor: batas 50 halaman (K7, AC-9), daftar menu tersimpan dibaca maks 60 entri.
  - Q1 dikonfirmasi user ("pertahankan") sebagai D1. Q2/Q3 menjadi keputusan default D2/D3. Bagian pertanyaan diganti "Keputusan (dapat dikonfirmasi user)".

## Review #1 — 2026-10-16

Verdict: CHANGES_REQUESTED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | K5, `UpdateStaticPage`, AC-10 | Update halaman biasa memvalidasi slug "seperti Create" termasuk daftar cadangan, segmen route, dan `public_path()`. Bila rilis berikutnya menambah route atau folder yang sama dengan slug halaman lama (risiko yang diakui di bagian Risiko), halaman itu tidak bisa lagi diubah judul atau isinya (`slug_reserved`), padahal slug tidak berubah. | Cek cadangan, route, public, dan format hanya bila slug berbeda dari slug tersimpan. Slug yang sama selalu lolos. Tambahkan ke AC-10: halaman ber-slug yang kemudian bentrok dengan route uji tetap bisa disimpan bila slug tidak diubah. |
| 2 | major | Permission, AC-33, AC-34 | `config/filament-shield.php` memakai `intercept_gate = before` untuk `super-admin`, sehingga Gate untuk super-admin lolos sebelum `StaticPagePolicy::delete` dievaluasi. Klaim "policy delete bernilai false walau super-admin" hanya benar bila policy dipanggil langsung. Tombol Hapus (aksi tabel dan aksi header Ubah) tetap terlihat untuk super-admin di halaman sistem bila hanya bergantung pada `can()`. | Wajibkan `->hidden(fn (StaticPage $r) => $r->isSystem())` eksplisit pada semua aksi Hapus. Ubah AC-33 menjadi diuji untuk `admin-konten` dan `super-admin` (aksi tak terlihat, pemanggilan paksa ditolak). AC-34 jelaskan bahwa uji policy memakai pemanggilan langsung, dan guard akhir adalah `DeleteStaticPage` (`page_system_locked`). |
| 3 | major | K3/K4, `UpdatePortalMenu`, AC-20, AC-26, AC-35 | Aturan batas 8 item ambigu dan bisa membuat editor tak bisa disimpan. (a) Tidak jelas apakah item `visible` yang tak tampil (halaman draf, modul mati, `psb_info` tanpa route) dihitung. (b) Item bawaan baru yang ditambahkan di akhir dengan `visible: true` bisa membuat total terlihat 9: portal memotong diam-diam, editor menolak simpan (`menu_too_many`) tanpa admin tahu sebabnya. (c) Keadaan editor saat `menu_items = null` tidak ditentukan: halaman selain `profil`/`kontak` muncul `visible: false`, tetapi `profil` draf tampil sebagai visible yang ikut terhitung. | Tetapkan: batas menghitung item `visible: true` pada daftar tersimpan, apa pun status tampilnya (sederhana, bisa dites). Item bawaan baru yang ditambahkan oleh penggabungan dimasukkan `visible: true` hanya bila total terlihat masih < 8, selain itu `visible: false`. Nyatakan isi editor saat `null` (urutan K4, `profil` dan `kontak` visible, halaman lain hidden) dan tambahkan AC untuk kasus ini serta kasus (b). |
| 4 | minor | K1, AC-18/19 | Instalasi baru menerbitkan `kontak` dan menampilkan "Kontak" di menu walau profil lembaga kosong, sehingga pengunjung masuk ke halaman empty-state. Ini bertentangan dengan semangat "tanpa isi bawaan". | Pertimbangkan: item Kontak hanya tampil bila halaman terbit dan ada data kontak, atau mulai sebagai `draft`. Bila tetap dipertahankan, catat sebagai keputusan sadar di K1 dan pastikan F036 AC-54 sudah dicek. |
| 5 | minor | Model data | Default kolom `terbit` (agar baris lama terbit) berbeda dari default Action `draft`. `StaticPage` memakai `#[Fillable(['slug','title','body'])]`, dan `status` belum disebut untuk ditambahkan, juga factory tanpa model state. | Tulis eksplisit: tambahkan `status` ke Fillable, dan Action/factory selalu mengisi `status` sendiri. |
| 6 | minor | Migrasi footer, AC-2 | Footer lama selalu menampilkan tautan `visi-misi` (footer.blade.php baris 61) tanpa syarat isi. Migrasi hanya menambah bila isi tidak kosong, jadi pada instalasi lama dengan visi-misi kosong tautan itu hilang. Klaim "instalasi lama tidak berubah tampilannya" tidak persis. | Ubah klaim, atau tambahkan tautan bila halaman ada (tanpa syarat isi). Pilih salah satu dan sesuaikan AC-2. |
| 7 | minor | Kontrak F038, Risiko | Route F038 `psb.info` berpath `info-psb`, sedangkan grup route `psb` berprefix `psb` dan bernama `psb.`. F038 harus mendaftarkan route di luar grup itu. Kontrak baru "perlu dicatat" tetapi belum ada tindakan. | Tambah langkah: perbarui `notes` F038 di `feature_list.json` (nama `psb.info`, path `/info-psb`, di luar prefix `psb`, didaftarkan sebelum `/{page}`). Catat juga urutan merge F037 sebelum F035 di notes F035. |
| 8 | minor | Editor Menu | Repeater memuat semua halaman tanpa batas. Banyak halaman membuat form panjang, dan `GetPortalNavigation` memuat id halaman dari settings tanpa batas wajar saat data manual. | Catat sebagai risiko yang diterima, atau batasi pembacaan daftar tersimpan (mis. maks 50 entri). |

Catatan hasil pemeriksaan (tidak butuh perubahan): urutan registrasi route sudah benar (`admin`, `up` terdaftar sebelum `{page}` menurut `route:list`), jadi AC-15 cukup sebagai pengaman dan lookahead hanya cadangan. Batas footer 8 (`UpdatePortalFooter::MAX_LINKS`) konsisten dengan migrasi. Pola `DB::table` dan JSON toleran sesuai migrasi F036. Tidak ada kebutuhan Redis/Node, query portabel. Sanitasi HTML, escape label, dan cakupan sitemap memadai. Daftar test lama terdampak sudah lengkap.

Penilaian pertanyaan user: default Q1, Q2, dan Q3 wajar dan konsisten dengan acceptance F037 dan prinsip F036. Tidak ada yang wajib ditanyakan sebelum RED. Q1 hanya perlu dikonfirmasi bila user ingin profil/visi-misi bisa dihapus. Q3 adalah isi demo, risikonya rendah. Temuan #4 adalah satu-satunya hal yang menyentuh prinsip user dan boleh diputuskan orkestrator.

## Review #2 — 2026-10-16

Verdict: APPROVED

Semua 3 major Review #1 tertangani (slug hanya dicek saat berubah dengan AC-10 kasus bentrok belakangan; `hidden(isSystem)` eksplisit dengan guard `DeleteStaticPage` dan AC-33/34 untuk super-admin; aturan visible K4 dengan AC-27/27a/27b). Minor #4–#8 juga tertangani. D1 (tiga halaman sistem dipertahankan) konsisten dengan acceptance F037.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | Rencana test (test lama terdampak) | `tests/Feature/Http/Portal/PortalAdversarialTest.php`, `PortalRoutingTest.php`, dan `UpdateStaticPageTest.php` merujuk `/kontak` atau `'kontak'` tetapi `PortalAdversarialTest` belum ada di daftar terdampak. Aturan Kontak (404 tanpa data kontak) bisa mematahkannya. | Red-tester cek semua berkas test yang memuat `/kontak` (grep) dan perbarui yang perlu. |
| 2 | minor | AC-2 | Cabang "visi-misi berstatus draft sebelum langkah 2" tidak dapat dicapai: kolom `status` baru ada setelah langkah 1, dan semua baris otomatis terbit. | Hapus cabang itu dari AC-2 atau uji lewat pemanggilan langkah data terpisah. |
| 3 | minor | K1 aturan Kontak | Empty-state di `show.blade.php` (`$hasContact`) menjadi kode mati setelah aturan Kontak. | Green-coder membersihkannya saat refaktor. |
