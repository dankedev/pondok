# F049 konten-taksonomi-berita — Taksonomi berita disederhanakan

Status rencana: APPROVED

Jalur: penuh (migration, Action yang mengubah data). Sumber: `docs/audits/ui-ux-portal-konten.md` §1 butir 7, §2.4, §5.7 (hanya bagian jenis berita; filter jenjang dan chip kosong sudah selesai di F047). Depends on: F047.

## Ringkasan & tujuan
Berita punya dua sumbu pengelompokan yang tumpang tindih: `type` (kegiatan/prestasi/berita) dan kategori. Sisakan satu: kategori, yang nama dan isinya ditentukan admin. `posts.type` dihapus dari admin, portal, dan database.

## Ruang lingkup
- Termasuk:
  - Hapus kolom `posts.type` (+ index) dengan migrasi data; hapus `Post::TYPE_*`, `TYPES`, `scopeOfType`.
  - Hapus Jenis dari form, kolom, dan filter admin; hapus filter `jenis`, chip jenis, badge jenis di portal, judul per jenis.
  - Berita dari Prestasi otomatis berkategori "Prestasi" (dibuat bila belum ada).
  - Blok feed beranda `posts` dan `achievements` dipindah dari `type` ke `achievement_id`.
  - Perbarui ERD dan dokumen terkait.
- Tidak termasuk (YAGNI):
  - Berita terkait, tautan kategori baru di detail (tautan kategori di detail sudah ada), halaman arsip per kategori, ikon/warna kategori.
  - Kategori bawaan di seeder instalasi (`ContentCategorySeeder` hanya memuat kategori album; tetap). Nama kategori tetap isian admin.
  - Setting atau opsi baru; mengubah sumber blok feed di `NormalizeContentBlocks` (`FEED_SOURCES` tetap, blok tersimpan tidak perlu dimigrasikan).
  - Pengalihan URL lama `?jenis=...`: parameter tidak dikenal diabaikan (daftar semua berita tampil). Lihat Risiko.
  - Kode perbaikan data demo (`migrate:fresh --seed`).

## Model data
Migrasi baru `2026_10_22_000000_move_post_type_to_categories`:
- `up()`, urutan: (1) bila ada post `type='kegiatan'` dengan `category_id` null, cari kategori `post` bernama "Kegiatan" atau ber-slug `kegiatan`, buat bila tidak ada, lalu isi `category_id` post itu; (2) sama untuk `type='prestasi'` dengan kategori "Prestasi"/slug `prestasi`; (3) post `type='berita'` tidak diubah (tetap tanpa kategori bila belum punya); post yang sudah punya `category_id` tidak diubah apa pun tipenya; (4) `dropIndex(['type'])` lalu `dropColumn('type')` (urutan wajib untuk SQLite). Kategori dibuat lewat `DB::table` (Action tidak dipakai di migrasi), `type='post'`, slug unik per type, tanpa membuat kategori bila tidak ada post yang membutuhkan.
- `down()`: tambah `type` string(10) default `'berita'`, tanpa index tambahan; catat bahwa tipe asli tidak bisa dipulihkan (kategori yang dibuat tetap).
- `Post`: `#[Fillable]` tanpa `type`; docblock "berita, kegiatan, dan prestasi" disesuaikan. `Category` tidak berubah.
- Docs: `docs/erd.md` hapus `enum type` di blok `posts`; ganti baris catatan "Kegiatan, prestasi, dan berita disatukan di `posts` dengan kolom `type`" menjadi "dibedakan lewat kategori; berita dari Prestasi berkategori Prestasi"; tambahkan migrasi F049 di catatan. Tambahkan perubahan `feed` di catatan blok beranda (`posts` = tanpa `achievement_id`, `achievements` = dengan `achievement_id`). `docs/prd.md` bagian 3.1 diperiksa dan hanya diubah bila menyebut jenis berita.

## Action class
- `SavePost`: hapus input `type`, validasi `type`, error `invalid_type`, dan `fill`. Input `type` yang masih dikirim diabaikan (tidak error).
- `PublishAchievementAsPost`: hapus `type`; isi `category_id` dengan kategori `post` ber-nama "Prestasi" (cari nama atau slug `prestasi`; bila tidak ada buat lewat `SaveCategory`). Kategori yang sudah ada dipakai ulang, tidak digandakan. Draf tetap bisa diganti kategorinya oleh admin.
- `ListPublicPosts`: hapus `jenis`, `types`, `type` dari parameter dan hasil; `ofType` dihapus. Filter kategori dan jenjang tidak berubah.
- `GetPortalHomeData::latestPosts(bool $fromAchievement, int $limit)`: `posts` = terbit, `achievement_id` null; `achievements` = terbit, `achievement_id` tidak null. Urutan dan batas tidak berubah. Tautan "Lihat semua" `achievements` = `/berita?kategori=prestasi` bila kategori ber-slug `prestasi` ada, selain itu `/berita`.
- `Http\Controllers\Portal\ListPosts`: hapus pembacaan `jenis`.

## UI (Filament / Blade)
Baca `docs/design-guide.md` (§0, filter, badge, bahasa) sebelum menyentuh UI; tidak ada komponen baru.
- `PostForm`: hapus `Select` Jenis (bagian Isi). Kategori tetap, dengan teks bantu satu kalimat bila belum ada (tidak wajib).
- `PostsTable`: hapus kolom dan filter Jenis; kolom dan filter Kategori yang ada dipertahankan.
- `portal/posts/index.blade.php`: hapus `$typeChips`, chip jenis, `filter_types_label`; heading selalu `titles.all`. `x-portal.post-card`: badge jenis diganti nama kategori (`$post->category?->name`, badge `gray`, tidak dirender bila tanpa kategori). `portal/posts/show.blade.php`: badge jenis dihapus (kategori sudah tampil sebagai tautan).
- `lang/id/pondok.php`: hapus `post.type`, `post.types`, `portal.posts.titles.{berita,kegiatan,prestasi}`, `portal.posts.filter_types_label`, `portal.posts.all_types`, serta galat `invalid_type` Post bila ada.
- Seeder demo: `DemoData::POSTS` hapus `type`; `DemoContent::seedPosts` tanpa `type`; `DemoData::ACHIEVEMENT_CATEGORY` menjadi `'Prestasi'` dan masuk `POST_CATEGORIES` bila belum; `DemoRecords::publishPost` tanpa `type` dan tanpa override `category_id` (memakai kategori dari Action). Aturan konten keagamaan demo tidak berubah.

## Permission & cakupan
Tidak ada permission baru, tidak ada `unit_user`. Saklar `modules.konten.enabled` tetap menggerbang resource dan portal; modul Prestasi tetap memakai permission publikasi yang ada.

## Kriteria penerimaan (bisa dites)
- AC-1 Migrasi skema: setelah migrasi, `posts` tidak punya kolom `type` dan indeksnya; kolom lain tak berubah; `down()` menambah `type` kembali (default `berita`). Berjalan di SQLite.
- AC-2 Migrasi data: post `kegiatan` tanpa kategori masuk kategori "Kegiatan", post `prestasi` tanpa kategori masuk "Prestasi" (kategori dibuat `type=post` dengan slug `kegiatan`/`prestasi`); post `berita` tanpa kategori tetap `category_id` null; jumlah post (termasuk soft delete) dan slug tak berubah.
- AC-3 Migrasi tidak menimpa dan tidak menggandakan: post yang sudah punya kategori tetap pada kategorinya; kategori "Kegiatan"/slug `kegiatan` yang sudah ada dipakai ulang; tanpa post `kegiatan`/`prestasi` tanpa kategori, tidak ada kategori baru dibuat.
- AC-4 `SavePost` membuat dan mengubah post tanpa `type`; `type` yang masih dikirim diabaikan tanpa error dan tidak disimpan.
- AC-5 `PublishAchievementAsPost` pada instalasi tanpa kategori "Prestasi": membuat kategori itu (`type=post`) dan draf berita berkategori itu; publikasi prestasi kedua memakai kategori yang sama (hanya satu baris); bila kategori "Prestasi" sudah ada, tidak dibuat ulang. Aturan lama (tidak publik, belum terverifikasi, sudah dipublikasikan) tidak berubah.
- AC-6 Admin: form Berita tidak punya field Jenis dan tetap menyimpan post dengan kategori; tabel tidak punya kolom/filter Jenis dan filter Kategori masih menyaring; tampil dan tersimpan dengan benar untuk peran dengan permission yang ada.
- AC-7 Portal daftar `/berita`: menampilkan semua post terbit; `?kategori=<slug>` dan `?jenjang=` tetap menyaring dan menumpuk; chip Jenis tidak muncul; heading "Berita & kegiatan"; `?jenis=apa-pun` diabaikan (200, daftar semua) dan tidak ikut di URL kanonik, chip, atau tautan halaman.
- AC-8 Kartu dan detail berita: kartu menampilkan nama kategori sebagai badge bila ada dan tanpa badge bila tidak; detail tidak menampilkan badge jenis, tautan kategori tetap ada.
- AC-9 Beranda blok feed: `posts` hanya berisi post terbit tanpa `achievement_id` (urut terbaru, batas lama), `achievements` hanya post terbit dengan `achievement_id`; tautan "Lihat semua" prestasi menuju `/berita?kategori=prestasi` bila kategori ada, selain itu `/berita`; feed kosong tetap tidak dirender.
- AC-10 Tidak ada sisa rujukan: `Post::TYPES`, `Post::TYPE_*`, `Post::ofType`, dan kunci `pondok.post.type(s)` tidak ada lagi; seeder demo (`migrate:fresh --seed`) berjalan, berita prestasi demo berkategori "Prestasi", dan semua berita demo terbit tampil di `/berita`.

## Rencana test
- `tests/Feature/Database/Migrations/MovePostTypeToCategoriesTest.php` (pola `MovePortalHomeToBlocksTest`): AC-1, AC-2, AC-3.
- `tests/Feature/Actions/Konten/SavePostTest.php` (ubah): AC-4. `tests/Feature/Filament/Resources/Posts/PostFromAchievementTest.php` + `ListAchievementsTest.php` (ubah): AC-5.
- `tests/Feature/Filament/Resources/Posts/PostResourceTest.php` (ubah): AC-6.
- `tests/Feature/Http/Portal/PostPagesTest.php`, `PortalRoutingTest.php`, `PortalHeadersTest.php`, `SeoTest.php`, `PortalAdversarialTest.php` (ubah): AC-7, AC-8. `HomeBlocksTest.php` (ubah): AC-9.
- `tests/Feature/Database/KontenConstraintsTest.php` (daftar kolom), `PostAchievementForeignKeyTest.php`, `Models/PostTest.php` (hapus AC-18 filter jenis dan konstanta), `DemoSeederTest.php` (baris ~539, kategori Prestasi): AC-1, AC-10.
- `tests/Pest.php` (`kontenPostData`, `kontenPost`): hapus `type`. Tes lain yang memakai `kontenPost(['type' => ...])` disesuaikan (`KontenVerifyGapsTest` x2, `ContentOverviewWidgetTest`, `AchievementTest`, `AchievementPolicyTest`, `CategoryActionsTest`, `KontenAccessTest`, `PhotosRelationManagerTest` tidak terkait, itu `type` kategori).
- File yang harus berubah (daftar kode): `app/Models/Post.php`, `app/Actions/Konten/{SavePost,ListPublicPosts,GetPortalHomeData}.php`, `app/Actions/Prestasi/PublishAchievementAsPost.php`, `app/Http/Controllers/Portal/ListPosts.php`, `app/Filament/Resources/Posts/{Schemas/PostForm,Tables/PostsTable}.php`, `resources/views/portal/posts/{index,show}.blade.php`, `resources/views/components/portal/post-card.blade.php`, `lang/id/pondok.php`, `database/seeders/Demo/{DemoData,DemoContent,DemoRecords}.php`, migrasi baru, `docs/erd.md`. Tidak berubah (diperiksa): `BuildSitemap`, `GetPortalNavigation`, `NormalizeContentBlocks`/`ParseContentBlocks`, `Filament/Pages/Site/Home.php`, `ContentCategorySeeder`, `lang/id/validation.php` (kunci `type` dipakai form lain), tidak ada `PostFactory`.

## Urutan implementasi
Paket 1, data + Action (test hijau: `Database/Migrations/MovePostTypeToCategoriesTest`, `Actions/Konten/SavePostTest`, `Filament/Resources/Posts/PostFromAchievementTest`, `Database/KontenConstraintsTest`, `Models/PostTest`):
1. Migrasi + `Post`, `tests/Pest.php` helper. 2. `SavePost`. 3. `PublishAchievementAsPost`.
Paket 2, admin (`Filament/Resources/Posts/PostResourceTest`, `Achievements/ListAchievementsTest`, `KontenVerifyGapsTest`, `ContentOverviewWidgetTest`):
4. `PostForm`, `PostsTable`, lang admin.
Paket 3, portal + demo (`Http/Portal/*`, `Database/Seeders/DemoSeederTest`):
5. `ListPublicPosts`, controller, views, lang portal. 6. `GetPortalHomeData`. 7. Seeder demo. 8. ERD/PRD. Lalu Pint.

## Risiko & pertanyaan terbuka
- Tautan lama `/berita?jenis=prestasi` kini menampilkan semua berita, bukan 404 dan bukan arsip prestasi. Dibiarkan (YAGNI; tautan eksternal ke filter ini kecil kemungkinannya). Satu-satunya tautan internal, "Lihat semua" blok prestasi, sudah dialihkan.
- Pertanyaan (mengubah perilaku lembaga): post `kegiatan`/`prestasi` tanpa kategori otomatis diberi kategori "Kegiatan"/"Prestasi", sedangkan `berita` tanpa kategori tetap tanpa kategori. Rekomendasi: pakai aturan ini (tidak ada informasi yang hilang, admin bebas mengganti nama). Alternatif: tanpa pemberian kategori sama sekali (lebih sederhana, tetapi pembeda kegiatan/prestasi hilang di data nyata).
- Risiko minor: post bertipe `prestasi` yang ditulis manual (tanpa `achievement_id`) kini muncul di feed `posts`, bukan `achievements`. Diterima; admin tidak perlu tindakan.
- Catatan implementasi: bila slug `prestasi` dipakai kategori lain ber-nama berbeda, kategori itu dipakai ulang (bukan membuat slug ganda).

## Riwayat revisi
(belum ada)

## Review #1 — 2026-10-17

Verdict: APPROVED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | Rencana test / Paket 1 | Daftar pemakai `type` di test belum lengkap: `tests/Feature/Actions/Prestasi/PublishAchievementAsPostTest.php` (baris ~154 menyisipkan `type`) dan `tests/Feature/Http/Portal/PostFilterVisibilityTest.php` (baris 21-22) tidak disebut, dan yang pertama tidak ada di daftar hijau Paket 1. `PostResourceTest` baris 24 dan 150-151 juga memakai `type`. | Tambahkan ke rencana; jalankan grep `'type' => 'berita|kegiatan|prestasi'` di `tests/` setelah `tests/Pest.php` diubah. |
| 2 | minor | AC-8 / `ListPublicPosts` | Badge kategori di kartu butuh relasi `category` ter-eager-load di daftar publik (cegah N+1); beranda sudah `with(['category','unit'])`. | Pastikan `ListPublicPosts` memuat `category`. |
| 3 | minor | `PublishAchievementAsPost` | `SaveCategory` melempar `category_name_taken` bila nama "Prestasi" ada tetapi slug berbeda; kategori juga dibuat sebelum kompensasi balapan `already_published` (tidak berbahaya). | Cari nama ATAU slug dulu (sudah direncanakan); cukup dicatat. |
| 4 | minor | Migrasi | Pencocokan nama "Kegiatan"/"Prestasi" sebaiknya tidak peka huruf besar-kecil (collation MySQL) agar tidak bentrok unique nama. | Cocokkan lewat slug atau `lower(name)`. |

Cakupan: tidak ada pemakai `type` post di luar daftar file rencana (diperiksa `app`, `resources`, `database`, `routes`; sitemap, dashboard, widget tidak memakai). Tidak ada blocker/major: data, `?jenis=` diabaikan, YAGNI, ukuran (10 AC), portabilitas DB (dropIndex lalu dropColumn) memadai.
