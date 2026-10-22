# F016 konten-cms — Konten (CMS) di panel

Status rencana: APPROVED

Owner: diklaim orkestrator di `feature_list.json`. Dependensi tertulis: F001 (`done`). Tidak ada dependensi nyata lain. `achievements` (F015) belum ada; kolom `posts.achievement_id` dibuat tanpa FK.

## Ringkasan & tujuan

Admin konten mengelola isi situs dari panel Filament tanpa menyentuh kode:

- **Berita, kegiatan, dan prestasi** dalam satu tabel `posts` (kolom `type`), soft delete, slug unik (termasuk baris yang terhapus), status `draft | terbit` dengan `published_at` (bisa dijadwalkan).
- **Galeri foto berkategori**: `categories` (type `album`), `albums`, `photos` di disk `public`.
- **Video YouTube**: `videos` dengan `youtube_id` tervalidasi.
- **Halaman statis** tetap tiga: `profil`, `visi-misi`, `kontak` (model `StaticPage` di tabel `pages`), hanya bisa diubah.
- **Profil sekolah** (nama, slogan, logo, alamat, kontak, media sosial) ditulis ke `settings` lewat halaman Filament khusus dengan permission sendiri.
- Body HTML disanitasi di server (anti-XSS) tanpa dependency baru.
- Scope/helper query yang dipakai portal publik F017 (`Post::published()`, `Video::ordered()`, URL gambar, URL embed YouTube, pembaca profil sekolah).

Acuan: PRD 3.1; ERD "Konten Publik (CMS)" (tabel `categories`, `posts`, `albums`, `photos`, `videos`, `pages`, `settings`) dan relasi `users ||--o{ posts`, `achievements |o--o| posts`; CLAUDE.md (soft delete hanya `students`/`applicants`/`posts`, disk `public` untuk konten website, modul bisa dimatikan); design guide §2–§9, §12.

## Ruang lingkup

- Termasuk:
  - Migration + model + factory: `categories`, `posts` (soft delete), `albums`, `photos`, `videos`, `pages` (model `StaticPage`).
  - Seeder `StaticPageSeeder` (tiga halaman) dan `ContentCategorySeeder` (kategori album "Fasilitas" dan "Kegiatan"), dipanggil `DatabaseSeeder`.
  - Action `app/Actions/Konten/*` (daftar di bawah), termasuk sanitasi HTML, slug, parser YouTube, simpan gambar ke disk `public`.
  - Resource Filament: `PostResource`, `AlbumResource` (+ `PhotosRelationManager`), `VideoResource`, `StaticPageResource`, `CategoryResource`.
  - Halaman Filament `SchoolProfile` (`/admin/school-profile`) yang menulis `settings` `school.*` lewat Action.
  - Permission + policy (Shield), pembagian ke `admin-konten` (konten) dan `admin-sekolah` (profil sekolah), saklar `modules.konten.enabled` (masuk `ENABLED_BY_DEFAULT`).
  - Scope/helper untuk F017 (lihat "Model data").
  - Teks `lang/id/pondok.php`, grup navigasi `konten` dan `pengaturan`, baris status konten di tabel §4 design guide.
- Tidak termasuk (eksplisit):
  - Portal publik Blade/Livewire, route publik, sitemap, RSS, SEO meta tag (F017).
  - Widget dashboard "jumlah konten" (`view-content-metrics`, F018).
  - Menghubungkan prestasi ke berita (`achievement_id` diisi, FK ke `achievements`, aksi "Terbitkan sebagai berita") — F015. F016 hanya membuat kolom nullable + index tanpa FK, dan `SavePost` tidak menerimanya.
  - Kolom `meta` tidak punya UI; hanya cast `array`.
  - Lampiran gambar di dalam body (RichEditor `fileAttachments` dimatikan). Gambar artikel memakai cover; foto banyak memakai galeri.
  - Kompresi/resize gambar di server (butuh GD/Imagick yang belum tentu ada di cPanel). Hanya batas ukuran + resize di browser bawaan Filament (lihat UI).
  - Mengganti `brandName` panel menjadi `school.name` (lihat pertanyaan terbuka #6).
  - Activity log untuk konten (bukan data keuangan). Hanya perubahan profil sekolah yang dicatat.
  - Cakupan jenjang (`unit_user`) untuk konten (lihat "Permission & cakupan").
  - Hapus permanen post (force delete).

### Kontrak untuk F017 (portal publik) dan deployment (review #1 #5, #11)

- `title`, `excerpt`, `caption`, `description` (album/video), dan semua nilai `school.*` adalah **teks polos**; portal wajib meng-escape (`{{ }}`), tidak pernah `{!! !!}`.
- `posts.body` dan `pages.body` adalah HTML tersanitasi. Portal merender di dalam wadah dengan kelas `isolate overflow-hidden` (membatasi `style` seperti `position:fixed`) dan **menyanitasi ulang saat render** lewat `SanitizeContentHtml` (pertahanan berlapis bila konfigurasi Filament berubah).
- Daftar post publik memakai `Post::published()`; `author` bisa NULL (`nullOnDelete`) sehingga portal menampilkan tanpa nama penulis.
- Daftar album memakai `Album::query()->withCount('photos')->with('coverPhoto')`; album tanpa foto tetap boleh tampil dengan placeholder atau disembunyikan (keputusan F017).
- Video memakai `Video::ordered()` dan `embedUrl()`.
- URL gambar (`coverUrl()`, `Photo::url()`, `logo_url`) berasal dari `Storage::disk('public')->url()`, yang bergantung pada symlink `public/storage` (`php artisan storage:link`) dan `APP_URL`. Di cPanel yang mematikan fungsi `symlink`, panduan instalasi harus menjelaskan alternatif (symlink manual lewat File Manager/SSH, atau `url` disk `public` diarahkan ke folder yang bisa diakses web). F016 tidak mengubah konfigurasi disk; catatan ini masuk panduan instalasi.

## Model data

Semua migration memakai fitur portabel SQLite/MySQL/Postgres (tanpa enum native: `string` + validasi di Action, pola modul lain). Satu migration per tabel, urutan: `categories`, `posts`, `albums`, `photos`, `videos`, `pages`.

### `categories` — model `App\Models\Category`

| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| type | string(10) | `post` \| `album` |
| name | string(100) | |
| slug | string(120) | |
| timestamps | | |

- Unique (`type`, `name`) dan unique (`type`, `slug`). (ERD tidak menandai UK; ditambahkan agar URL kategori F017 tidak ambigu.)
- Konstanta `TYPE_POST = 'post'`, `TYPE_ALBUM = 'album'`, `TYPES`. Relasi `posts(): HasMany` (dipakai dengan `withTrashed()` untuk cek pemakaian), `albums(): HasMany`. Scope `ofType(string $type)`.
- Method `isInUse(): bool` — ada post (termasuk soft-deleted) atau album yang merujuk.

### `posts` — model `App\Models\Post` (`SoftDeletes`)

| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| category_id | foreignId nullable → categories, `restrictOnDelete` | hanya kategori `type = post` (dicek Action) |
| unit_id | foreignId nullable → units, `restrictOnDelete` | NULL = umum |
| author_id | foreignId **nullable** → users, `nullOnDelete` | diisi user pembuat; lihat pertanyaan #3 |
| type | string(10) | `kegiatan` \| `prestasi` \| `berita` |
| title | string(255) | |
| slug | string(200) **unique** | unique DB mencakup baris soft-deleted |
| excerpt | string(255) nullable | teks polos |
| body | longText | HTML tersanitasi |
| cover_path | string nullable | path relatif di disk `public` |
| youtube_url | string(255) nullable | disimpan ternormalisasi `https://www.youtube.com/watch?v=<ID>` |
| achievement_id | unsignedBigInteger nullable, index | **tanpa FK** sampai F015 |
| meta | json nullable | cast `array` |
| status | string(10), default `draft` | `draft` \| `terbit` |
| published_at | timestamp nullable | |
| timestamps, softDeletes | | |

- Index (`status`, `published_at`) untuk query portal; index `type`.
- Konstanta: `TYPE_ACTIVITY = 'kegiatan'`, `TYPE_ACHIEVEMENT = 'prestasi'`, `TYPE_NEWS = 'berita'`, `TYPES`; `STATUS_DRAFT = 'draft'`, `STATUS_PUBLISHED = 'terbit'`, `STATUSES`; `DISPLAY_SCHEDULED = 'terjadwal'` (status tampilan, bukan disimpan); `COVER_DIRECTORY = 'posts/covers'`.
- Cast: `published_at` → `datetime`, `meta` → `array`.
- Relasi: `category()`, `unit()`, `author()` (BelongsTo User).
- Scope `published(Builder $query, ?CarbonInterface $now = null)`: `status = terbit` AND `published_at IS NOT NULL` AND `published_at <= $now ?? now()`. SoftDeletes otomatis mengecualikan yang terhapus. Dipakai F017.
- Scope `ofType(string $type)`.
- `displayStatus(): string` → `terjadwal` bila `terbit` dan `published_at > now()`, selain itu `status`. `displayStatusColor(): string` dari satu peta `STATUS_COLORS` (`draft` gray, `terbit` success, `terjadwal` info).
- `coverUrl(): ?string` → `Storage::disk('public')->url($cover_path)` atau null.
- `youtubeId(): ?string` → hasil `ParseYoutubeId` atas `youtube_url`.
- Tidak memakai `HasUnitScope`.

### `albums` — model `App\Models\Album`

| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| category_id | foreignId → categories, `restrictOnDelete` | wajib, hanya `type = album` |
| title | string(255) | |
| slug | string(200) unique | |
| description | text nullable | teks polos |
| timestamps | | |

- Relasi `category()`, `photos(): HasMany` diurutkan `sort_order`, lalu `id`; `coverPhoto(): HasOne` (foto dengan `sort_order` terkecil, lalu `id` terkecil — `oneOfMany` atau `HasOne` + `orderBy`, pilih yang portabel).
- `DIRECTORY_PREFIX = 'albums'` → foto disimpan di `albums/{album_id}/`.

### `photos` — model `App\Models\Photo`

| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| album_id | foreignId → albums, `cascadeOnDelete` | file dihapus Action, bukan DB |
| path | string | relatif di disk `public` |
| caption | string(255) nullable | |
| sort_order | unsignedSmallInteger default 0 | |
| timestamps | | |

- `url(): string` → `Storage::disk('public')->url($path)`.

### `videos` — model `App\Models\Video`

| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| title | string(255) | |
| youtube_id | string(11) | regex `^[A-Za-z0-9_-]{11}$` |
| description | text nullable | teks polos |
| is_featured | boolean default false | |
| sort_order | unsignedSmallInteger default 0 | |
| timestamps | | |

- Scope `ordered()`: `is_featured` desc, `sort_order` asc, `id` desc.
- `embedUrl(): string` → `https://www.youtube-nocookie.com/embed/{youtube_id}`; `watchUrl(): string` → `https://www.youtube.com/watch?v={youtube_id}`.
- `youtube_id` tidak unik (video sama boleh dipakai dua kali dengan judul berbeda).

### `pages` — model `App\Models\StaticPage` (`$table = 'pages'`)

Nama model sengaja `StaticPage` agar tidak bentrok dengan kelas `Filament\Pages\Page` / `Filament\Resources\Pages\Page` dan agar permission Shield (`view:static-page`) tidak mirip permission halaman (`view:<slug>`).

| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| slug | string(50) unique | `profil` \| `visi-misi` \| `kontak` |
| title | string(255) | |
| body | longText | boleh string kosong (belum diisi) |
| timestamps | | |

- Konstanta `SLUGS = ['profil', 'visi-misi', 'kontak']`. Scope/helper `findBySlug(string $slug): ?self`.
- `StaticPageSeeder`: `firstOrCreate(['slug' => ...], ['title' => __('pondok.static_page.defaults.<slug>'), 'body' => ''])` → "Profil", "Visi & Misi", "Kontak". Seed ulang tidak menimpa isi.

### `settings` (tabel F001, tanpa perubahan skema) — key profil sekolah

| Key | Isi | Batas |
|---|---|---|
| `school.name` | nama lembaga (sudah di-seed `SettingSeeder`) | wajib, ≤ 100 |
| `school.tagline` | slogan/motto | opsional, ≤ 150 |
| `school.logo_path` | path di disk `public` (sudah dibaca kuitansi F007) | png/jpeg/webp ≤ 1024 KB |
| `school.address` | alamat (sudah dibaca kuitansi) | opsional, ≤ 500 |
| `school.phone` | telepon tanpa format (sudah dibaca kuitansi) | opsional, `^\+?[0-9]{8,15}$` setelah normalisasi |
| `school.whatsapp` | nomor WhatsApp tanpa format | sama dengan phone |
| `school.email` | email | opsional, `FILTER_VALIDATE_EMAIL`, ≤ 255 |
| `school.instagram_url`, `school.facebook_url`, `school.youtube_url` | tautan media sosial | opsional, `http`/`https` saja, ≤ 255 |

Key opsional yang dikosongkan disimpan `null` (`Setting::set($key, null)`), sehingga `Setting::get($key, $default)` mengembalikan default. Nama key `school.address`, `school.phone`, `school.logo_path` wajib sama dengan yang sudah dibaca `ShowPaymentReceipt`.

### Batas gambar (cPanel)

Konstanta di satu tempat, `App\Actions\Konten\StorePublicImage`: `MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp']` (tanpa SVG/GIF; SVG bisa memuat skrip), `MAX_KILOBYTES = 2048` (cover dan foto), `LOGO_MAX_KILOBYTES = 1024`. 2 MB dipilih karena `upload_max_filesize` bawaan banyak cPanel 2–8 MB; FilePond Filament mengunggah satu berkas per request sehingga unggah banyak foto tidak menabrak `post_max_size`.

## Action class

Semua Action melempar `ValidationException` dengan pesan `__("pondok.konten.errors.<reason>")` pada field terkait (pola `SaveScholarship::fail`). Simpan DB di dalam `DB::transaction`. File baru yang sudah tersimpan dihapus bila transaksi gagal; file lama dihapus hanya setelah commit (`DB::afterCommit`).

### `App\Actions\Konten\SanitizeContentHtml::handle(?string $html): string`

- `handle` tidak melempar error. Sanitizer Filament memotong input diam-diam di 500.000 **byte** (`withMaxInputLength(500000)` Symfony menghitung byte), jadi pemanggil wajib memeriksa `strlen($html)` (byte, **bukan** `mb_strlen`) **sebelum** memanggil dan menolak dengan `too_long`. Konstanta `MAX_INPUT_BYTES = 500000` didefinisikan di sini dan dipakai pemanggil. Teks Arab memakai 2 byte per huruf, jadi batas efektifnya ±250.000 huruf Arab.
- Mengembalikan `Str::sanitizeHtml($html ?? '')` (macro dari `filament/support`, memakai `symfony/html-sanitizer` yang sudah terpasang sebagai dependency Filament; **tidak ada perubahan `composer.json`**). Konfigurasi Filament membuang `<script>`, `<iframe>`, `<object>`, atribut `on*`, dan skema `javascript:` pada tautan. Konfigurasi itu **masih mengizinkan** skema `data:` pada media (`allowedMediaSchemes` bawaan Symfony `http`, `https`, `data`) dan atribut `style`/`class` di semua elemen.
- `hasDisallowedMediaSource(string $sanitized): bool` (keputusan orkestrator, opsi tanpa dependency baru): memeriksa **hasil sanitasi** (atribut sudah dinormalisasi Symfony menjadi `nama="nilai"`). Untuk setiap atribut `src`, `srcset`, dan `poster` (regex `/\s(src|srcset|poster)="([^"]*)"/i`; `srcset` dipecah per koma, diambil URL tiap kandidat), nilai di-`html_entity_decode` + trim lalu `parse_url($value, PHP_URL_SCHEME)`; skema selain `null` (relatif), `http`, `https` → true. Pemanggil yang mendapat true **menolak** simpan dengan `body`/`media_source_invalid` ("Gambar di isi harus memakai tautan http atau https. Unggah gambar sebagai sampul atau lewat galeri."). Menolak, bukan menghapus diam-diam, agar penulis tahu gambarnya tidak tersimpan (mis. gambar yang ditempel ke RichEditor menjadi `data:`).
- `isBlank(string $html): bool`: `trim(html_entity_decode(strip_tags($html)))` kosong dan tidak ada `<img`.

### `App\Actions\Konten\ParseYoutubeId::handle(?string $input): ?string`

- Trim. Kosong → null (pemanggil memutuskan wajib/tidak).
- Diterima: ID mentah 11 karakter `[A-Za-z0-9_-]`; URL `http(s)://` dengan host `youtube.com`, `www.youtube.com`, `m.youtube.com`, `music.youtube.com` dan path `/watch?v=ID`, `/shorts/ID`, `/embed/ID`, `/live/ID`; host `youtu.be` dengan path `/ID`; `youtube-nocookie.com/embed/ID`. Query lain (`t=`, `si=`) diabaikan.
- Selain itu (Vimeo, skema `javascript:`, ID ≠ 11 karakter, host mirip `youtube.com.evil.test`) → melempar `InvalidArgumentException`; pemanggil memetakan ke `youtube_invalid`. Pengecekan host memakai `parse_url` + perbandingan persis, bukan `str_contains`.
- Helper statis `watchUrl(string $id): string`.

### `App\Actions\Konten\GenerateSlug::handle(string $modelClass, string $source, ?string $explicit, int $maxLength, ?Model $ignore = null, array $scope = []): string`

- `$maxLength` mengikuti panjang kolom: konstanta `Category::SLUG_MAX_LENGTH = 120`, `Post::SLUG_MAX_LENGTH = 200`, `Album::SLUG_MAX_LENGTH = 200` (migration memakai konstanta yang sama). Halaman statis tidak memakai `GenerateSlug` (slug tetap).
- `$explicit` terisi → `Str::slug($explicit)`; hasil kosong → `slug`/`slug_invalid`; panjang > `$maxLength` → `slug`/`too_long`; sudah dipakai (query `withTrashed()` bila model memakai SoftDeletes, kecuali `$ignore`, dibatasi `$scope` mis. `['type' => 'album']` untuk kategori) → `slug`/`slug_taken`. Tidak ada akhiran otomatis untuk slug eksplisit.
- `$explicit` kosong → basis `Str::slug($source)` dipotong `$maxLength - 10` karakter (tanpa `-` di ujung; sisa ruang untuk akhiran `-N`). Judul beraksara Arab **ditransliterasi** oleh `Str::slug` (mis. "الحمد لله" → `alhmd-llh`) dan itu diterima apa adanya. Basis kosong (judul tanpa huruf/angka, mis. "★★★") → basis `class_basename` kebab (`post`, `album`, `category`). Bila basis dipakai → coba `basis-2`, `basis-3`, … sampai bebas.
- Unique index DB tetap jadi pengaman terakhir untuk balapan; `QueryException` unique ditangkap pemanggil dan dipetakan ke `slug`/`slug_taken`.

### `App\Actions\Konten\StorePublicImage::handle(UploadedFile $file, string $directory, string $field, int $maxKilobytes = self::MAX_KILOBYTES): string`

- MIME dari deteksi server (`$file->getMimeType()`) ∈ `MIME_TYPES` dan ukuran ≤ `$maxKilobytes` → bila tidak, `$field`/`image_invalid` (pesan menyebut format jpg/png/webp dan batas ukuran).
- Simpan ke disk `public`: `{directory}/{Str::uuid()}.{ext}` (ext dari MIME: `jpg`/`png`/`webp`; nama asli tidak dipakai). Kembalikan path relatif.

### `App\Actions\Konten\SavePost::handle(array $data, User $actor, ?Post $post = null): Post`

`$data`: `array{type: mixed, title: mixed, slug?: mixed, category_id?: mixed, unit_id?: mixed, excerpt?: mixed, body: mixed, youtube_url?: mixed, cover?: UploadedFile|null, remove_cover?: bool, status: mixed, published_at?: mixed}`.

Urutan validasi (berhenti di error pertama, pola Action lain):
1. `type` ∈ `Post::TYPES` → `type`/`invalid_type`.
2. `title` di-trim, kosong → `title`/`title_required`; > 255 → `title`/`too_long`.
3. Slug lewat `GenerateSlug` (sumber `title`, abaikan `$post`).
4. `category_id`: kosong → null; bukan id kategori `type = post` → `category_id`/`invalid_category`.
5. `unit_id`: kosong → null; bukan id unit → `unit_id`/`invalid_unit`.
6. `excerpt`: `strip_tags` + trim; kosong → null; > 255 → `excerpt`/`too_long`.
7. `body`: `strlen` (byte) > `MAX_INPUT_BYTES` → `body`/`too_long`; disanitasi; `hasDisallowedMediaSource` → `body`/`media_source_invalid`; `isBlank` → `body`/`body_required`.
8. `youtube_url`: kosong → null; `ParseYoutubeId` gagal → `youtube_url`/`youtube_invalid`; berhasil → disimpan `watchUrl(id)`.
9. `status` ∈ `Post::STATUSES` → `status`/`invalid_status`.
10. `published_at`: parse **ketat** — diterima hanya `CarbonInterface` atau string format `Y-m-d H:i:s` / `Y-m-d H:i` (keluaran `DateTimePicker` Filament) lewat `CarbonImmutable::createFromFormat` dengan pemeriksaan galat; string relatif ("tomorrow", "next monday", "besok") → `published_at`/`published_at_invalid`. Kosong → null. Bila `status = terbit` dan null: post yang sudah punya `published_at` tersimpan **mempertahankan** nilai lama (tanggal terbit tidak berubah karena field dikosongkan); post tanpa nilai lama → `now()`. Status `draft` menyimpan nilai apa adanya (boleh null).
11. `cover` (bila ada) lewat `StorePublicImage` ke `Post::COVER_DIRECTORY`, field `cover`.

Efek: buat baru → `author_id = $actor->id`; ubah → `author_id` tidak berubah. `cover` baru menggantikan `cover_path` lama; `remove_cover = true` tanpa `cover` baru → `cover_path = null`. File cover lama dihapus dari disk `public` setelah commit. `achievement_id` dan `meta` tidak disentuh.

### `App\Actions\Konten\DeletePost::handle(Post $post): void` dan `RestorePost::handle(Post $post): void`

- `DeletePost`: soft delete (`$post->delete()`); file cover **tidak** dihapus. `RestorePost`: `$post->restore()`. Tipis, tetapi dipakai `DeleteAction`/`RestoreAction` Filament (lewat `->using(...)`) agar resource hanya memanggil Action (review #1 #9).

### `App\Actions\Konten\SaveCategory::handle(array $data, ?Category $category = null): Category`

- `type` ∈ `Category::TYPES` → `type`/`invalid_type`. Saat ubah, `type` tidak boleh berubah bila `isInUse()` → `type`/`category_type_locked`.
- `name` trim, kosong → `name`/`name_required`; > 100 → `name`/`too_long`; sudah dipakai di `type` yang sama → `name`/`category_name_taken`.
- Slug lewat `GenerateSlug` dengan `$scope = ['type' => $type]`, sumber `name`.

### `App\Actions\Konten\DeleteCategory::handle(Category $category): void`

- `isInUse()` (post termasuk soft-deleted, atau album) → `category`/`category_in_use`; baris tetap. Selain itu dihapus.

### `App\Actions\Konten\SaveAlbum::handle(array $data, ?Album $album = null): Album`

- `title` (wajib, ≤ 255), `slug` (`GenerateSlug`, unik di `albums`), `category_id` wajib dan harus kategori `type = album` → `category_id`/`invalid_category`, `description` (`strip_tags`, trim, kosong → null, ≤ 2000 → `too_long`).

### `App\Actions\Konten\AddAlbumPhotos::handle(Album $album, array $files, ?string $caption = null): Collection<Photo>`

- `$files` kosong → `photos`/`photos_required`; > 20 (`MAX_FILES_PER_UPLOAD`) → `photos`/`photos_too_many`.
- Semua berkas divalidasi dulu (MIME/ukuran seperti `StorePublicImage`); satu tidak valid → `photos`/`image_invalid`, **tidak ada** baris maupun file yang tersimpan.
- Simpan berurutan ke `albums/{album_id}/`; `sort_order` melanjutkan `max(sort_order) + 1` (album kosong mulai 1). `caption` (≤ 255) diterapkan ke semua foto dalam unggahan ini (opsional).
- Bila penyimpanan DB gagal, file yang sudah tersimpan di unggahan ini dihapus.

### `App\Actions\Konten\UpdatePhoto::handle(Photo $photo, array $data): Photo`

- `caption` trim, kosong → null, > 255 → `caption`/`too_long`. `sort_order` bilangan bulat 0–32767 → selain itu `sort_order`/`sort_order_invalid`.

### `App\Actions\Konten\DeletePhoto::handle(Photo $photo): void`

- Hapus baris, lalu file di disk `public` setelah commit.

### `App\Actions\Konten\DeleteAlbum::handle(Album $album): void`

- Hapus semua `photos` dan album dalam transaksi, lalu setelah commit hapus file foto (dan direktori `albums/{id}`). Kategori tidak tersentuh.

### `App\Actions\Konten\SaveVideo::handle(array $data, ?Video $video = null): Video`

- `title` wajib ≤ 255; `youtube` (URL atau ID) wajib → kosong `youtube`/`youtube_required`, tidak valid `youtube`/`youtube_invalid`, disimpan sebagai `youtube_id`; `description` teks polos ≤ 2000; `is_featured` bool (default false); `sort_order` 0–32767 (default 0) → `sort_order_invalid`.

### `App\Actions\Konten\DeleteVideo::handle(Video $video): void`

- Hapus permanen baris video (tidak ada file). Dipakai `DeleteAction` di `ManageVideos` lewat `->using(...)`.

### `App\Actions\Konten\UpdateStaticPage::handle(StaticPage $page, array $data): StaticPage`

- `title` wajib ≤ 255; `body` `strlen` (byte) ≤ `MAX_INPUT_BYTES` (→ `too_long`), disanitasi, `hasDisallowedMediaSource` → `media_source_invalid`, **boleh kosong** (halaman belum diisi). `slug` tidak bisa diubah (diabaikan bila dikirim).

### `App\Actions\Konten\UpdateSchoolProfile::handle(array $data, User $actor): void`

`$data`: `array{name: mixed, tagline?: mixed, address?: mixed, phone?: mixed, whatsapp?: mixed, email?: mixed, instagram_url?: mixed, facebook_url?: mixed, youtube_url?: mixed, logo?: UploadedFile|null, remove_logo?: bool}`.

- Validasi semua field **sebelum** menulis apa pun (all-or-nothing): `name` → `name_required`/`too_long`; teks opsional → `too_long`; `phone`/`whatsapp` dinormalisasi (buang spasi, `-`, `.`, `(`, `)`; `+` hanya di depan) lalu `^\+?[0-9]{8,15}$` → `phone_invalid`; `email` → `email_invalid`; URL sosial: `filter_var(FILTER_VALIDATE_URL)` dan skema `http`/`https` → `url_invalid`; `logo` lewat `StorePublicImage` ke `school/` dengan `LOGO_MAX_KILOBYTES`, field `logo`.
- Tulis semua key lewat `Setting::set` dalam satu transaksi; kosong → `null`. Logo baru menggantikan `school.logo_path`; `remove_logo` → `null`; file lama dihapus setelah commit.
- Activity log (`activity()->causedBy($actor)->event('updated')->withProperties(['changed' => [<key> => ['old' => ..., 'new' => ...]]])->log('school_profile_updated')`, log name `konten`), hanya key yang berubah; tidak dicatat bila tidak ada perubahan.

### `App\Actions\Konten\GetSchoolProfile::handle(): array`

- Mengembalikan `array{name: string, tagline: ?string, address: ?string, phone: ?string, whatsapp: ?string, email: ?string, instagram_url: ?string, facebook_url: ?string, youtube_url: ?string, logo_path: ?string, logo_url: ?string}`. `name` fallback `config('app.name')`; `logo_url` dari disk `public`, null bila tidak di-set. Dipakai halaman `SchoolProfile` (isi form) dan F017. Konstanta `KEYS` daftar key `school.*` menjadi satu sumber bagi `UpdateSchoolProfile`.

## UI (Filament / Blade)

Rujukan design guide: §3 (Heroicon Outlined), §4 (badge status), §5 (tanggal `j M Y` / `j M Y, H.i`, kosong `-`), §6 (struktur resource, Section, maks. ±7 kolom, `defaultSort`, eager-load, filter), §7 (empty state), §8 (konfirmasi, label kata kerja + objek), §9 (bahasa), §12 (tanpa library tambahan). Jalankan alur `/anti-ui-slop` (§13) bila tersedia dan checklist §0 sebelum menyatakan selesai.

Grup navigasi baru di `pondok.navigation`: `konten` → "Konten", `pengaturan` → "Pengaturan".

Semua form memanggil Action lewat `ShowsActionErrorsOnForm` (aksi modal) atau override `handleRecordCreation`/`handleRecordUpdate` di halaman Create/Edit yang memetakan `ValidationException` ke `data.<field>` (pola resource yang sudah ada; implementer memilih pola terdekat di kode).

### `PostResource` — "Berita & Kegiatan" (`/admin/posts`, sort 1, `Heroicon::OutlinedNewspaper`)

- Halaman `ListPosts`, `CreatePost`, `EditPost` (form panjang, bukan modal).
- Form (`Schemas/PostForm`), `->columns(2)`:
  - Section `section_content`: `type` (Select, label "Jenis": Kegiatan, Prestasi, Berita), `title`, `slug` (opsional, helper "Kosongkan untuk dibuat otomatis dari judul."), `excerpt` (Textarea, `columnSpanFull`, maxLength 255), `body` (`RichEditor`, `columnSpanFull`, `fileAttachments(false)`, toolbar: bold, italic, underline, strike, link, h2, h3, bulletList, orderedList, blockquote, undo, redo).
  - Section `section_media`: `cover` (`FileUpload`, `image()`, `storeFiles(false)`, `acceptedFileTypes(StorePublicImage::MIME_TYPES)`, `maxSize(StorePublicImage::MAX_KILOBYTES)`, resize di browser bawaan FilePond: `imageResizeTargetWidth('1600')`, `imageResizeMode('contain')`, `imageResizeUpscale(false)`; di halaman edit tampilkan cover saat ini sebagai pratinjau + toggle `remove_cover` "Hapus sampul"), `youtube_url` (helper "Tempel tautan video YouTube.").
  - Section `section_publication`: `status` (Radio/ToggleButtons Draft/Terbit), `published_at` (DateTimePicker, helper "Kosongkan untuk memakai waktu saat diterbitkan. Isi waktu yang akan datang untuk menjadwalkan."), `category_id` (Select kategori `type = post`, opsional), `unit_id` (Select jenjang, placeholder "Umum (semua jenjang)").
- Tabel (`Tables/PostsTable`): `title` (searchable, deskripsi `slug`), `type` (label lang), `category.name`, `unit.name` (placeholder "Umum"), `author.name` (toggleable hidden), `published_at` (`j M Y, H.i`, placeholder `-`), status badge `displayStatus()` dengan label lang dan warna `Post::STATUS_COLORS`. `defaultSort('created_at', 'desc')`, eager-load `category`, `unit`, `author`. Filter: `type`, `status`, `category_id`, `unit_id` (dengan opsi "Umum"), `TrashedFilter`. Aksi baris: `EditAction`; `ActionGroup` [`DeleteAction` → `DeletePost` (konfirmasi: "Berita dipindahkan ke tempat sampah dan tidak tampil di situs. Bisa dipulihkan."), `RestoreAction` → `RestorePost`]. Tanpa aksi massal. Empty state: "Belum ada berita atau kegiatan." + [Tulis berita] bila boleh `create`.
- `EditPost` header: `DeleteAction` → `DeletePost`, `RestoreAction` → `RestorePost` (tampil sesuai policy `delete`/`restore`). Route binding edit memakai `withTrashed` agar post terhapus bisa dibuka untuk dipulihkan (pola `StudentResource`).
- Notifikasi sukses menyebut judul: "Berita \":title\" disimpan."

### `AlbumResource` — "Galeri" (`/admin/albums`, sort 2, `Heroicon::OutlinedPhoto`)

- Halaman `ListAlbums`, `CreateAlbum`, `EditAlbum`. Form: `title`, `slug`, `category_id` (wajib, kategori `album`), `description`.
- Tabel: `title`, `category.name`, `photos_count` ("Jumlah foto"), `updated_at` (`j M Y`). `defaultSort('updated_at', 'desc')`. Filter kategori. Hapus via `DeleteAlbum` dengan konfirmasi "Album dan semua fotonya dihapus permanen." (bukan soft delete). Empty state "Belum ada album." + [Buat album].
- `PhotosRelationManager` (di `EditAlbum`): kolom `ImageColumn` (disk `public`, thumbnail kotak), `caption` (placeholder `-`), `sort_order`. `defaultSort('sort_order')`. Header action "Unggah foto" (`primary`): `FileUpload` multiple, `image()`, `storeFiles(false)`, `maxFiles(AddAlbumPhotos::MAX_FILES_PER_UPLOAD)`, mime/ukuran sama, resize browser 1600px; `caption` opsional → `AddAlbumPhotos`. Aksi baris: `EditAction` (caption, sort_order → `UpdatePhoto`), `DeleteAction` (konfirmasi, → `DeletePhoto`). Tanpa drag reorder (lihat pertanyaan #7). Empty state "Album ini belum punya foto." + [Unggah foto].

### `VideoResource` — "Video" (`/admin/videos`, sort 3, `Heroicon::OutlinedFilm`)

- Halaman `ManageVideos` (modal). Form: `title`, `youtube` (label "Tautan atau ID YouTube"), `description`, `is_featured` (Toggle "Tampilkan di beranda"), `sort_order`. Edit mengisi `youtube` dengan `watchUrl`.
- Tabel: `title`, `youtube_id` (font mono, `url` ke `watchUrl` dengan `openUrlInNewTab`), `is_featured` (IconColumn boolean), `sort_order`. Urutan default sama dengan `Video::ordered()`. Hapus dengan konfirmasi → `DeleteVideo`. Empty state "Belum ada video."

### `StaticPageResource` — "Halaman" (`/admin/static-pages`, sort 4, `Heroicon::OutlinedDocument`)

- Halaman `ListStaticPages` dan `EditStaticPage` saja; `getPages()` tidak mendaftarkan route `create` (tanpa create/delete; `canCreate()` dan `canDelete()` false untuk semua, termasuk super-admin). Form: `slug` (disabled, tampil saja), `title`, `body` (RichEditor, konfigurasi toolbar sama dengan post).
- Tabel: `title`, `slug`, `updated_at` (`j M Y, H.i`), aksi `EditAction`.

### `CategoryResource` — "Kategori" (`/admin/categories`, sort 5, `Heroicon::OutlinedTag`)

- Halaman `ManageCategories` (modal). Form: `type` (Select "Berita"/"Galeri"; disabled saat edit bila sedang dipakai), `name`, `slug`.
- Tabel: `name`, `type` (label), `slug`, jumlah pemakaian (`posts_count` dengan `withTrashed` + `albums_count`; dihitung via `withCount` dengan closure `withTrashed`). Filter `type`. `DeleteAction` disembunyikan bila dipakai, dan memanggil `DeleteCategory` (error tampil sebagai notifikasi `danger` bila lolos).

### Halaman `SchoolProfile` — "Profil sekolah" (`/admin/school-profile`, grup "Pengaturan", sort 1, `Heroicon::OutlinedBuildingLibrary`)

- `App\Filament\Pages\SchoolProfile` (pola `FinanceReport`: `canAccess()` cek permission, `getNavigationItems()` mengecek ulang). **Tidak** terikat saklar modul `konten` (profil dipakai kuitansi dan modul lain).
- Form diisi dari `GetSchoolProfile`. Section `section_identity`: `name`, `tagline`, `logo` (FileUpload image, `storeFiles(false)`, maks 1024 KB, pratinjau logo saat ini, toggle `remove_logo`). Section `section_contact`: `address` (Textarea, `columnSpanFull`), `phone`, `whatsapp`, `email`. Section `section_social`: `instagram_url`, `facebook_url`, `youtube_url`.
- Satu tombol utama "Simpan profil" → `UpdateSchoolProfile`; error dipetakan ke `data.<field>`. Notifikasi "Profil sekolah disimpan."

### Teks `lang/id/pondok.php` (kunci baru)

`navigation.konten`, `navigation.pengaturan`; blok `post`, `album`, `photo`, `video`, `static_page` (termasuk `defaults.profil|visi-misi|kontak`), `category`, `school_profile`, dan `konten.errors.*` untuk semua reason di atas: `invalid_type`, `title_required`, `too_long`, `slug_invalid`, `slug_taken`, `invalid_category`, `invalid_unit`, `body_required`, `youtube_invalid`, `youtube_required`, `invalid_status`, `published_at_invalid`, `image_invalid`, `name_required`, `category_name_taken`, `category_type_locked`, `category_in_use`, `photos_required`, `photos_too_many`, `sort_order_invalid`, `media_source_invalid`, `phone_invalid`, `email_invalid`, `url_invalid`. Pesan menyebut cara memperbaiki (§9), mis. `image_invalid` → "Gunakan gambar JPG, PNG, atau WEBP berukuran maksimal :max KB."

### Design guide §4

Tambahkan baris: Konten | draft | `gray`; Konten | terbit | `success`; Konten | terjadwal (dihitung, bukan status) | `info`.

## Permission & cakupan

### Permission baru

- `RolePermissionSeeder::RESOURCE_MODELS` ditambah `Category`, `Post`, `Album`, `Video`, `StaticPage` (6 aksi standar → mis. `view-any:post`, `update:static-page`).
- `EXTRA_RESOURCE_ACTIONS['Post'] = ['restore', 'restoreAny']` → `restore:post`, `restore-any:post`.
- `PAGE_PERMISSIONS` ditambah `view:school-profile` (format page Shield; mencakup membuka **dan** menyimpan halaman, karena halaman ini hanya form ubah).
- Foto tidak punya permission sendiri: kelola foto mengikuti `update:album` (relation manager dan Action dipanggil dari halaman edit album).
- Policy dibuat dengan `php artisan shield:generate` untuk kelima model; `PostPolicy` memakai `restore`/`restoreAny`. Test "every permission that a generated policy checks" tetap lulus.

### Pembagian ke peran

| Peran | Izin baru |
|---|---|
| super-admin | semua (otomatis `allPermissions()`) |
| admin-konten | `Category`, `Post`, `Album`, `Video`: 6 aksi; `Post`: + `restore`, `restore-any`; `StaticPage`: `view-any`, `view`, `update` |
| admin-sekolah | `view:school-profile` (PRD 2: pengaturan profil milik Admin Sekolah; keputusan orkestrator) |
| peran lain | tidak ada |

Dataset `admin-konten` di `RolePermissionSeederTest` menjadi: `view-dashboard`, `view-content-metrics`, 6 aksi × `category`/`post`/`album`/`video`, `restore:post`, `restore-any:post`, `view-any:static-page`, `view:static-page`, `update:static-page`. Dataset `admin-sekolah` ditambah `view:school-profile` (baris lain peran itu tidak berubah). File ini juga sedang diubah F008/F009/F013: **tambahkan**, jangan menimpa baris peran lain.

### Cakupan jenjang

Konten **tidak** memakai `HasUnitScope`: konten bersifat publik dan `unit_user` di CLAUDE.md hanya untuk `Student`, `Invoice`, `Applicant` dan turunannya. Admin konten bercakupan SD tetap melihat dan mengelola semua post (lihat pertanyaan #2).

### Saklar modul

`SettingSeeder::ENABLED_BY_DEFAULT` ditambah `konten` (F009 menambah `psb`, F013 `dokumen` di baris yang sama; gabungkan). `BelongsToModule` (`getModule() = 'konten'`) pada kelima resource. Modul mati → grup "Konten" hilang, URL kelima resource 403. Halaman `SchoolProfile` tetap tersedia. Action tetap bisa dipanggil dari kode.

Catatan (review #1 #7): `SettingSeeder` memakai `firstOrCreate`, sehingga instalasi atau DB dev yang sudah di-seed menyimpan `modules.konten.enabled = false` dan tidak berubah oleh seed ulang. Modul diaktifkan lewat `Setting::set('modules.konten.enabled', true)` (atau halaman pengaturan modul bila sudah ada); **tidak** ada migration data. Disebut di laporan akhir green-coder.

## Kriteria penerimaan (bisa dites)

Fixture bersama (helper `konten*` di **akhir** `tests/Pest.php`, bagian terpisah): `UnitSeeder`, `RolePermissionSeeder`, `SettingSeeder`, `StaticPageSeeder`, `Storage::fake('public')` dan `Storage::fake('private')`, waktu dibekukan `2026-10-15 10:00` Asia/Jakarta, `kontenUser(string $role, array $unitCodes = [])` (pola `keuanganUser`), `kontenAssertRejected(Closure, string $reason, ?string $field)` (pola `keuanganAssertRejected` dengan pesan `pondok.konten.errors.*`). Gambar uji `UploadedFile::fake()->image('a.jpg', 800, 600)->size(500)` kecuali disebut lain.

### Skema & seeder

- AC-1: Setelah migrasi, tabel `categories`, `posts`, `albums`, `photos`, `videos`, `pages` ada dengan kolom di "Model data"; `posts` punya `deleted_at`; tabel lain tidak. Insert dua post dengan slug sama → `UniqueConstraintViolationException`, juga bila post pertama soft-deleted. Dua album dengan slug sama → gagal. Dua kategori `type` sama dan slug/nama sama → gagal; slug sama dengan `type` berbeda → berhasil.
- AC-2: FK: menghapus kategori yang dirujuk post (termasuk soft-deleted) atau album → `QueryException`; menghapus unit yang dirujuk post → `QueryException`; menghapus user penulis → post tetap ada dengan `author_id` NULL; menghapus album lewat query → baris `photos` ikut terhapus. `posts.achievement_id` boleh diisi angka sembarang (tanpa FK).
- AC-3: `StaticPageSeeder` pada DB kosong → tepat tiga baris `pages` dengan slug `profil`, `visi-misi`, `kontak`, judul "Profil", "Visi & Misi", "Kontak", body `''`. Seed ulang setelah body `profil` diubah → tetap tiga baris dan body tidak berubah. `ContentCategorySeeder` membuat kategori album "Fasilitas" dan "Kegiatan", idempotent. `DatabaseSeeder` memanggil keduanya.
- AC-4: Instalasi baru (`settings` kosong lalu `SettingSeeder`) → `Setting::moduleEnabled('konten')` true; `modules.konten.enabled` yang sudah `false` tidak ditimpa seed ulang; modul lain di `ENABLED_BY_DEFAULT` tetap aktif.

### Permission & akses

- AC-5: `RolePermissionSeeder` → `admin-konten` dan `admin-sekolah` memiliki tepat daftar di "Pembagian ke peran" (dataset); peran selain `admin-konten` dan super-admin tidak mendapat permission `*:post`, `*:album`, `*:video`, `*:category`, `*:static-page`; peran selain `admin-sekolah` dan super-admin (termasuk `admin-konten`) tidak mendapat `view:school-profile`; super-admin memiliki semuanya; seed ulang tidak menggandakan.
- AC-6: `admin-konten` dan super-admin: `GET /admin/posts`, `/admin/posts/create`, `/admin/albums`, `/admin/videos`, `/admin/static-pages`, `/admin/categories` → 200. `bendahara`, `admin-sekolah`, `admin-psb`, `kesantrian`, `musyrif`, `ustadz-tahfidz`, `petugas-pos` → 403 pada keenamnya, dan `/admin` mereka tidak memuat teks `pondok.navigation.konten`.
- AC-6a: `admin-sekolah` dan super-admin: `GET /admin/school-profile` → 200 dan `/admin` memuat `pondok.school_profile.title`. `admin-konten`, `bendahara`, `admin-psb`, `kesantrian`, `musyrif`, `ustadz-tahfidz`, `petugas-pos` → 403 dan `/admin` mereka tidak memuat `pondok.school_profile.title`.
- AC-7: `modules.konten.enabled = false` → super-admin 403 pada `/admin/posts`, `/admin/albums`, `/admin/videos`, `/admin/static-pages`, `/admin/categories`, dan `/admin` tidak memuat `pondok.navigation.konten`; `/admin/school-profile` tetap 200. Saat aktif, teks grup tampil.
- AC-8: `admin-konten` dengan cakupan `unit_user` = SD melihat di `ListPosts` post jenjang SLTP dan post umum (unit NULL) — konten tidak dibatasi jenjang.

### `SavePost`

- AC-9: `admin-konten` memanggil `SavePost` dengan `type` berita, `title` "Wisuda Tahfidz 2026", body `<p>Alhamdulillah</p>`, `status` draft → satu baris: `slug` `wisuda-tahfidz-2026`, `author_id` = user, `published_at` NULL, `unit_id` NULL, `cover_path` NULL.
- AC-10: Sudah ada post slug `wisuda-tahfidz-2026` yang soft-deleted → post baru berjudul sama tanpa slug eksplisit mendapat `wisuda-tahfidz-2026-2`; post ketiga `-3`. Judul "الحمد لله" tanpa slug → slug transliterasi `alhmd-llh`. Judul simbol saja "★★★" tanpa slug → slug `post`; post kedua berjudul "★★★" → `post-2`. Slug eksplisit post 201 karakter → `slug`/`too_long`.
- AC-11: Slug eksplisit "Profil Baru!" → disimpan `profil-baru`. Slug eksplisit yang dipakai post lain (termasuk soft-deleted) → `slug`/`slug_taken`, tidak ada baris baru. Slug eksplisit "!!!" → `slug`/`slug_invalid`. Mengubah post dengan slug miliknya sendiri → berhasil.
- AC-12: Validasi menolak tanpa menulis baris: `type` "artikel" → `type`/`invalid_type`; `title` spasi saja → `title`/`title_required`; `title` 256 karakter → `title`/`too_long`; `status` "publish" → `status`/`invalid_status`; `category_id` milik kategori `album` → `category_id`/`invalid_category`; `unit_id` 9999 → `unit_id`/`invalid_unit`; `excerpt` 256 karakter setelah strip tag → `excerpt`/`too_long`; `published_at` "besok", "tomorrow", atau "next monday" → `published_at`/`published_at_invalid`; `published_at` "2026-10-20 08:00" dan "2026-10-20 08:00:00" diterima.
- AC-13: Body `<p>Halo</p><script>alert(1)</script><img src="x" onerror="alert(1)"><a href="javascript:alert(1)">klik</a><iframe src="https://evil.test"></iframe>` → `body` tersimpan memuat `<p>Halo</p>` dan teks `klik`, serta tidak memuat `<script`, `onerror`, `javascript:`, `<iframe`. Body `<p> </p>` atau `<p><br></p>` → `body`/`body_required`. Body ASCII 500.001 byte → `body`/`too_long`.
- AC-13a (multibyte): body `<p>` + 250.000 × "ب" + `</p>` (500.007 byte, 250.007 karakter) → `body`/`too_long`, tidak ada baris. Body `<p>` + 200.000 × "ب" + `</p>` (400.007 byte) → tersimpan dan `strip_tags(body)` sama persis dengan 200.000 × "ب" (tidak terpotong). Kasus yang sama berlaku untuk `UpdateStaticPage` (AC-31).
- AC-13b (skema media): body berisi `<img src="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=">` atau `<img src="data:image/png;base64,iVBORw0KGgo=">` → `body`/`media_source_invalid`, tidak ada baris; body dengan `<img src="https://example.test/a.jpg" alt="a">` atau `<img src="/storage/a.jpg">` → tersimpan dan memuat `src` tersebut. Body tersimpan mana pun tidak pernah memuat `src="data:`. Kasus tambahan: `<img src="/a.jpg" srcset="/a.jpg 1x, data:image/png;base64,AAA 2x">` → `body`/`media_source_invalid`; `<a href="data:text/html;base64,AAA">x</a>` → tersimpan tanpa `data:` (dibuang sanitizer).
- AC-13c (kunci perilaku konfigurasi): body `<p style="color: red" class="lead">x</p>` → tersimpan memuat `style="color: red"` dan `class="lead"` (perilaku diterima, pertanyaan #1). Bila versi Filament mengubah konfigurasi, test ini gagal dan keputusan ditinjau ulang.
- AC-14: `youtube_url` berikut disimpan `https://www.youtube.com/watch?v=dQw4w9WgXcQ` dan `youtubeId()` = `dQw4w9WgXcQ`: `https://youtu.be/dQw4w9WgXcQ?si=abc`, `https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10`, `https://m.youtube.com/shorts/dQw4w9WgXcQ`, `https://www.youtube.com/embed/dQw4w9WgXcQ`. Ditolak dengan `youtube_url`/`youtube_invalid`: `https://vimeo.com/123`, `https://www.youtube.com/watch?v=abc`, `javascript:alert(1)`, `https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ`.
- AC-15: `cover` gambar jpg 500 KB → `cover_path` diawali `posts/covers/`, berakhiran `.jpg`, file ada di disk `public`, disk `private` kosong. `cover` PDF (`UploadedFile::fake()->create('a.pdf', 100, 'application/pdf')`), SVG, atau gambar 2049 KB → `cover`/`image_invalid`, tidak ada baris dan tidak ada file baru di disk `public`.
- AC-16: Mengubah post dengan `cover` baru → `cover_path` berganti dan file lama hilang dari disk `public`. `remove_cover = true` → `cover_path` NULL dan file hilang. Soft delete post → file cover tetap ada. Gagal validasi saat ubah (mis. `title` kosong) dengan `cover` baru → `cover_path` lama tetap, file lama tetap, dan file baru tidak tertinggal di disk.
- AC-17: `status` terbit tanpa `published_at` → `published_at` = `2026-10-15 10:00`. `status` terbit dengan `published_at` `2026-10-20 08:00` → disimpan apa adanya. Post terbit dengan `published_at` `2026-10-01 07:00` diubah dengan `published_at` kosong (status tetap terbit) → `published_at` tetap `2026-10-01 07:00`. User lain (super-admin) mengubah post → `author_id` tetap user pembuat.
- AC-18: `Post::published()` pada data: A terbit 2026-10-14, B terbit 2026-10-20 (terjadwal), C draft dengan `published_at` 2026-10-01, D terbit 2026-10-01 lalu soft-deleted → hanya A. Dengan argumen `$now` 2026-10-21 → A dan B. `displayStatus()`: A `terbit`, B `terjadwal`, C `draft`.

### UI post

- AC-19: Livewire `CreatePost` sebagai `admin-konten`: isi form valid (termasuk `cover` fake image) lalu `create` → tanpa error, baris tersimpan lewat `SavePost` (author = user, slug otomatis, cover di disk `public`). Isi slug yang dipakai → error pada `data.slug` dengan pesan `slug_taken`, tidak ada baris baru.
- AC-20: `ListPosts` menampilkan badge "Draft", "Terbit", "Terjadwal" sesuai AC-18, dan "Umum" untuk post tanpa jenjang. Post soft-deleted tidak tampil secara bawaan, tampil dengan `TrashedFilter` "Dengan yang terhapus".
- AC-21: `admin-konten` memanggil aksi `delete` di `EditPost` → `deleted_at` terisi, baris tetap ada, file cover tetap. Di `EditPost` untuk post terhapus, `assertActionVisible('restore')` untuk `admin-konten`; memanggil aksi itu → `deleted_at` NULL. User uji dengan permission `view-any:post`, `view:post`, `update:post`, `delete:post` tetapi **tanpa** `restore:post` → `assertActionHidden('restore')` pada post terhapus. Untuk post tidak terhapus, `assertActionHidden('restore')` dan `assertActionVisible('delete')` bagi `admin-konten`.

### Kategori

- AC-22: `SaveCategory` type `album`, name "Fasilitas" → slug `fasilitas`. Slug eksplisit kategori 121 karakter → `slug`/`too_long`; 120 karakter → tersimpan. Nama 100 karakter tanpa slug → tersimpan, slug otomatis ≤ 120 karakter; nama 101 karakter → `name`/`too_long`. Name "Fasilitas" lagi type `album` → `name`/`category_name_taken`; type `post` → berhasil. `type` "video" → `type`/`invalid_type`. Mengubah `type` kategori yang dipakai → `type`/`category_type_locked`.
- AC-23: `DeleteCategory` pada kategori yang dipakai post soft-deleted atau oleh album → `category`/`category_in_use`, baris tetap; kategori tak terpakai → terhapus. Di `ManageCategories`, aksi hapus tersembunyi untuk kategori terpakai dan terlihat untuk yang tidak.

### Galeri

- AC-24: `SaveAlbum` tanpa `category_id` atau dengan kategori `post` → `category_id`/`invalid_category`. Judul "Fasilitas Asrama" → slug `fasilitas-asrama`; album kedua judul sama → `fasilitas-asrama-2`.
- AC-25: `AddAlbumPhotos` dengan 3 gambar valid pada album yang sudah punya foto `sort_order` 1 → 3 baris baru `sort_order` 2, 3, 4, path diawali `albums/{album_id}/`, file ada di disk `public`. 3 berkas dengan satu PDF → `photos`/`image_invalid`, jumlah baris dan file tidak berubah. 0 berkas → `photos`/`photos_required`. 21 berkas → `photos`/`photos_too_many`.
- AC-26: `UpdatePhoto` caption 256 karakter → `caption`/`too_long`; `sort_order` -1 atau 40000 → `sort_order`/`sort_order_invalid`; nilai valid tersimpan.
- AC-27: `DeletePhoto` → baris dan file hilang. `DeleteAlbum` pada album 2 foto → album, 2 baris foto, dan kedua file hilang; kategori tetap.
- AC-28: Livewire `PhotosRelationManager` di `EditAlbum` sebagai `admin-konten`: aksi "Unggah foto" dengan 2 gambar → 2 baris foto; aksi hapus foto → baris hilang. Pengguna `bendahara` mendapat 403 di `/admin/albums/{id}/edit`.

### Video

- AC-29: `SaveVideo` dengan `youtube` `https://youtu.be/dQw4w9WgXcQ` atau `dQw4w9WgXcQ` → `youtube_id` `dQw4w9WgXcQ`; kosong → `youtube`/`youtube_required`; `https://vimeo.com/1` → `youtube`/`youtube_invalid`; `title` kosong → `title`/`title_required`; tanpa `sort_order` → 0; `sort_order` "abc" → `sort_order_invalid`. `embedUrl()` = `https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ`. Aksi hapus di `ManageVideos` (lewat `DeleteVideo`) → baris hilang.
- AC-30: `Video::ordered()` atas V1 (featured, sort 2), V2 (featured, sort 1), V3 (tidak featured, sort 0) → urutan V2, V1, V3.

### Halaman statis

- AC-31: `UpdateStaticPage` pada `profil` dengan body berisi `<script>` → tersimpan tanpa `<script`; body `<img src="data:image/png;base64,iVBORw0KGgo=">` → `body`/`media_source_invalid`; body 250.000 × "ب" dalam `<p>` (> 500.000 byte) → `body`/`too_long`; body kosong → berhasil (`''`); title kosong → `title`/`title_required`; `slug` "beranda" di data → slug tetap `profil`.
- AC-32: `ListStaticPages` untuk `admin-konten` dan super-admin tidak menampilkan tombol buat; `/admin/static-pages/create` → 404 (route tidak didaftarkan); tidak ada aksi hapus pada baris maupun `EditStaticPage`. `admin-konten` menyimpan perubahan lewat `EditStaticPage` → body tersimpan tersanitasi.

### Profil sekolah

- AC-33: `UpdateSchoolProfile` dengan name "Ma'had Al-Kitab", phone "0812-3456-7890", whatsapp "+62 812 3456 7890", email "info@pondokcontoh.sch.id", address, instagram `https://instagram.com/pondokcontoh` → `Setting::get('school.name')` "Ma'had Al-Kitab", `school.phone` "081234567890", `school.whatsapp` "+6281234567890", key lain sesuai; tagline kosong → `Setting::get('school.tagline')` NULL.
- AC-34: Ditolak dan **tidak ada key yang berubah** (nilai sebelumnya tetap): name kosong → `name`/`name_required`; email "bukan-email" → `email`/`email_invalid`; instagram `javascript:alert(1)` atau `ftp://x.test` → `instagram_url`/`url_invalid`; phone "abc" atau "123" → `phone`/`phone_invalid`; address 501 karakter → `address`/`too_long`.
- AC-35: `logo` png 300 KB → `school.logo_path` diawali `school/`, file di disk `public`; mengganti logo menghapus file lama; `remove_logo` → `school.logo_path` NULL dan file hilang. `logo` SVG atau png 1025 KB → `logo`/`image_invalid`, `school.logo_path` lama tetap.
- AC-36: Perubahan profil mencatat satu activity (log name `konten`, causer = user, properti `changed` hanya berisi key yang berubah). Menyimpan tanpa perubahan tidak menambah activity.
- AC-37: `GetSchoolProfile` saat hanya `school.name` yang ada → `name` terisi, field lain NULL, `logo_url` NULL; saat `school.name` NULL → `name` = `config('app.name')`; `logo_url` = URL disk `public` bila logo ada.
- AC-38: Livewire `SchoolProfile` sebagai `admin-sekolah`: form terisi dari settings; ubah `name` lalu `save` → tersimpan dan notifikasi sukses; email tidak valid → error pada `data.email`, settings tidak berubah. Kuitansi F007 (`GET /admin/receipts/{id}`) setelah alamat diubah lewat halaman ini memuat alamat baru (regresi key).

### Bahasa

- AC-39: Halaman `/admin/posts`, `/admin/posts/create`, `/admin/albums`, `/admin/videos`, `/admin/static-pages`, `/admin/categories`, `/admin/school-profile` sebagai super-admin tidak memuat string `pondok.` (tidak ada kunci lang mentah).

## Rencana test

| AC | File | Nama test (usulan) |
|---|---|---|
| 1–2 | `tests/Feature/Database/KontenConstraintsTest.php` | `AC-1: enforces unique slugs including soft-deleted posts`, `AC-1: scopes category uniqueness per type`, `AC-2: restricts deleting referenced categories and units`, `AC-2: nulls the author when a user is deleted`, `AC-2: cascades photos when an album is deleted` |
| 3 | `tests/Feature/Database/Seeders/StaticPageSeederTest.php` | `AC-3: seeds the three static pages without overwriting edits`, `AC-3: seeds default album categories idempotently` |
| 4 | `tests/Feature/Database/Seeders/SettingSeederTest.php` (atau file yang ada) | `AC-4: enables the konten module on fresh install without overriding` |
| 5 | `tests/Feature/Database/Seeders/RolePermissionSeederTest.php` | dataset `admin-konten` dan `admin-sekolah` diperbarui; `AC-5: grants content permissions only to admin-konten and super-admin`, `AC-5: grants school profile only to admin-sekolah and super-admin` |
| 6–8 | `tests/Feature/Filament/KontenAccessTest.php` | `AC-6: lets admin-konten open content pages`, `AC-6: forbids content pages to other roles` (dataset peran), `AC-6a: limits school profile to admin-sekolah` (dataset peran), `AC-7: hides konten when the module is disabled but keeps school profile`, `AC-8: does not scope posts by unit` |
| 9–18 | `tests/Feature/Actions/Konten/SavePostTest.php` | satu `it` per AC termasuk AC-13a/13b/13c (dataset untuk AC-12, AC-14) |
| 13, 13b, 13c, 31 | `tests/Feature/Actions/Konten/SanitizeContentHtmlTest.php` | `strips scripts, event handlers, javascript urls and iframes`, `treats empty markup as blank`, `detects data and non-http media sources` (dataset), `keeps style and class attributes` |
| 14, 29 | `tests/Feature/Actions/Konten/ParseYoutubeIdTest.php` | `parses supported youtube urls` (dataset), `rejects non-youtube or malformed input` (dataset) |
| 18 | `tests/Feature/Models/PostTest.php` | `AC-18: published scope excludes drafts, scheduled and trashed posts`, `AC-18: display status` |
| 19–21 | `tests/Feature/Filament/Resources/Posts/PostResourceTest.php` | `AC-19: creates a post through the form`, `AC-19: shows slug_taken on the slug field`, `AC-20: shows status badges and Umum`, `AC-21: soft deletes and restores a post through actions`, `AC-21: hides restore without restore permission` |
| 22–23 | `tests/Feature/Actions/Konten/CategoryActionsTest.php`, `tests/Feature/Filament/Resources/Categories/CategoryResourceTest.php` | `AC-22: …`, `AC-23: blocks deleting categories in use` |
| 24–27 | `tests/Feature/Actions/Konten/AlbumActionsTest.php` | `AC-24`…`AC-27` |
| 28 | `tests/Feature/Filament/Resources/Albums/PhotosRelationManagerTest.php` | `AC-28: uploads and deletes photos from the album page` |
| 29–30 | `tests/Feature/Actions/Konten/SaveVideoTest.php`, `tests/Feature/Models/VideoTest.php` | `AC-29`, `AC-30: orders featured videos first` |
| 31–32 | `tests/Feature/Actions/Konten/UpdateStaticPageTest.php`, `tests/Feature/Filament/Resources/StaticPages/StaticPageResourceTest.php` | `AC-31`, `AC-32: static pages cannot be created or deleted` |
| 33–37 | `tests/Feature/Actions/Konten/UpdateSchoolProfileTest.php`, `tests/Feature/Actions/Konten/GetSchoolProfileTest.php` | satu `it` per AC (dataset untuk AC-34) |
| 38 | `tests/Feature/Filament/Pages/SchoolProfileTest.php` | `AC-38: saves the school profile from the page`, `AC-38: receipt reads the updated address` |
| 39 | `tests/Feature/Filament/KontenAccessTest.php` | `AC-39: renders content pages without raw translation keys` |

## Urutan implementasi

1. Migration `categories`, `posts`, `albums`, `photos`, `videos`, `pages` + model + factory. Dalam commit yang sama perbarui `docs/erd.md` bagian Konten Publik: `posts.author_id` nullable (`nullOnDelete`), `posts.achievement_id` sementara tanpa FK (FK ditambah F015), unique (`type`, `name`) dan (`type`, `slug`) di `categories`, panjang slug, dan daftar key `school.*` (`school.logo_path` menggantikan contoh `school.logo`). Test: AC-1, AC-2.
2. `StaticPageSeeder`, `ContentCategorySeeder`, `DatabaseSeeder`, `SettingSeeder::ENABLED_BY_DEFAULT`. Test: AC-3, AC-4.
3. Permission: `RESOURCE_MODELS`, `EXTRA_RESOURCE_ACTIONS`, `PAGE_PERMISSIONS`, peran `admin-konten` dan `admin-sekolah`, update `RolePermissionSeederTest`, dan **policy dibuat manual** (`php artisan make:policy <Model>Policy --model=<Model>`, isi mengikuti pola policy yang ada: `$user->can('<aksi>:<model>')`, `PostPolicy` + `restore`/`restoreAny`). `shield:generate` tidak dipakai di langkah ini karena resource belum ada. Test: AC-5 (termasuk test "every permission that a generated policy checks").
4. Action pendukung: `SanitizeContentHtml`, `ParseYoutubeId`, `GenerateSlug`, `StorePublicImage` + teks error `konten.errors.*`.
5. `SavePost`, `DeletePost`, `RestorePost` + scope/helper `Post`. Test: AC-9–AC-18.
6. `SaveCategory`, `DeleteCategory`. Test: AC-22, bagian Action AC-23.
7. `SaveAlbum`, `AddAlbumPhotos`, `UpdatePhoto`, `DeletePhoto`, `DeleteAlbum`. Test: AC-24–AC-27.
8. `SaveVideo`, `DeleteVideo` + `Video::ordered()`/`embedUrl()`. Test: AC-29, AC-30.
9. `UpdateStaticPage`. Test: AC-31.
10. `UpdateSchoolProfile`, `GetSchoolProfile`. Test: AC-33–AC-37.
11. Resource `PostResource` + grup navigasi `konten` + `BelongsToModule`. Test: AC-19–AC-21, bagian AC-6/7/8.
12. `CategoryResource`, `AlbumResource` + `PhotosRelationManager`, `VideoResource`, `StaticPageResource`. Test: AC-23 (UI), AC-28, AC-32, sisa AC-6/7.
13. Halaman `SchoolProfile` + grup `pengaturan`. Test: AC-38, bagian AC-6/7.
14. `lang/id/pondok.php` lengkap, baris §4 design guide, checklist §0. Test: AC-39. Pint.

## Risiko & pertanyaan terbuka

Setiap pertanyaan punya default yang dipakai bila user tidak memutuskan lain.

1. **Sanitasi HTML.** Dipakai `Str::sanitizeHtml()` dari `filament/support` (berbasis `symfony/html-sanitizer`, sudah terpasang sebagai dependency Filament, tanpa perubahan `composer.json`). Konfigurasi bawaan Filament mengizinkan atribut `style` dan `class` di semua elemen: tidak bisa menjalankan skrip, tetapi bisa mengubah tampilan (mis. teks tersembunyi). *Default:* pakai konfigurasi Filament apa adanya; `data:` pada media ditolak lewat `hasDisallowedMediaSource` (keputusan orkestrator, tanpa dependency baru); `style`/`class` diterima dan dikunci AC-13c; F017 mengikuti kontrak di "Ruang lingkup" (wadah `isolate overflow-hidden` + sanitasi ulang saat render). Alternatif (butuh keputusan): menulis konfigurasi sendiri `HtmlSanitizerConfig` tanpa `style` — itu memakai `symfony/html-sanitizer` langsung sehingga sebaiknya dideklarasikan di `composer.json` (dependency eksplisit baru).
2. **Cakupan jenjang untuk konten.** *Default:* tidak ada; semua admin konten melihat semua post. `unit_id` hanya label/filter untuk portal.
3. **`posts.author_id` nullable + `nullOnDelete`.** ERD menulis FK tanpa "nullable". Dengan `restrictOnDelete`, menghapus user yang pernah menulis berita akan gagal dengan error database di `UserResource`. *Default:* nullable + `nullOnDelete` (penyimpangan kecil dari ERD; catat di ERD bila disetujui).
4. **Permission profil sekolah.** *Diputuskan orkestrator:* satu permission page `view:school-profile` (akses + simpan) untuk `admin-sekolah` dan super-admin (PRD 2: pengaturan profil milik Admin Sekolah); `admin-konten` tidak mendapatkannya.
5. **Profil sekolah tidak ikut saklar modul `konten`.** *Default:* ya, selalu tersedia (dipakai kuitansi). Grup navigasi baru "Pengaturan".
6. **Nama panel.** `brandName` panel masih `config('app.name')`. *Diputuskan:* tidak diubah di F016 dan tidak ada entri baru.
7. **Urutan foto.** *Default:* angka `sort_order` diedit manual; tanpa drag-reorder (`->reorderable()` Filament menulis langsung tanpa Action).
8. **Resize gambar.** Tanpa resize server (GD/Imagick tidak dijamin di cPanel). *Default:* batas 2 MB (logo 1 MB) + resize di browser oleh FilePond bawaan Filament (lebar maks 1600 px). Bila browser gagal resize, validasi server tetap berlaku.
9. **Kategori bawaan.** *Default:* `ContentCategorySeeder` membuat kategori album "Fasilitas" dan "Kegiatan" (PRD 3.1 "fasilitas, kegiatan"); tanpa kategori post bawaan.
10. **Hapus album = hapus permanen.** Album/foto/video bukan tabel soft delete (CLAUDE.md). *Default:* hapus permanen dengan konfirmasi yang menjelaskan akibatnya.
11. **Berkas sementara Livewire.** Gambar konten bersifat publik, jadi tidak ada risiko privasi; tetapi berkas `livewire-tmp` yang tidak jadi disimpan dibersihkan oleh Livewire (24 jam). Tidak ada tindakan tambahan.
12. **Konflik file bersama.** `RolePermissionSeeder(Test)`, `SettingSeeder`, `lang/id/pondok.php`, `tests/Pest.php`, `DatabaseSeeder` juga diubah F008/F009/F013. Implementer menambah blok, tidak menimpa.

## Riwayat revisi

- 2026-10-15 — DRAFT pertama.
- 2026-10-15 — Revisi 1 (keputusan orkestrator): `view:school-profile` dipindah dari `admin-konten` ke `admin-sekolah` (tabel peran, dataset seeder, AC-5, AC-6 dipecah menjadi AC-6 dan AC-6a, AC-38, rencana test, urutan langkah 3, pertanyaan #4). Pertanyaan #6 ditutup tanpa entri baru. Default pertanyaan lain (1–3, 5, 7–10) diterima. Status tetap DRAFT.
- 2026-10-15 — Revisi 2 (Review #1 CHANGES_REQUESTED): #1 AC-10 memakai "★★★" untuk fallback, judul Arab menjadi slug transliterasi `alhmd-llh`. #2 `SanitizeContentHtml::hasDisallowedMediaSource` + error `media_source_invalid` (opsi tanpa dependency, keputusan orkestrator), klaim `data:` diperbaiki, AC-13b. #3 batas body `strlen` byte (`MAX_INPUT_BYTES`) di `SavePost` dan `UpdateStaticPage`, AC-13a multibyte, AC-31 diperluas. #4 `GenerateSlug` menerima `$maxLength` per model (120/200/200), AC-22 dan AC-10 menguji `too_long`. Minor: kontrak F017 + catatan `storage:link` (#5, #11), AC-13c mengunci `style`/`class` (#5), policy manual di langkah 3 (#6), catatan `firstOrCreate` (#7), parse `published_at` ketat dan nilai lama dipertahankan saat dikosongkan (#8, AC-12, AC-17), Action `DeletePost`/`RestorePost`/`DeleteVideo` (#9), AC-32 pasti 404 dan AC-21 menguji visibilitas aksi (#10), langkah 1 memperbarui `docs/erd.md` (#12). Status kembali DRAFT.

## Review #1 — 2026-10-15

Verdict: CHANGES_REQUESTED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | AC-10 / `GenerateSlug` | `Str::slug('الحمد لله')` menghasilkan `alhmd-llh` (transliterasi ASCII Laravel), bukan kosong, sehingga fallback `post` tidak pernah tercapai untuk aksara Arab. Implementasi yang benar akan gagal AC-10 (terverifikasi dengan `Str::slug`). Hanya input tanpa huruf/angka (mis. "!!!", emoji) yang menghasilkan basis kosong. | Ganti contoh fallback di AC-10 dengan judul simbol saja (mis. "★★★"), dan putuskan eksplisit bahwa judul Arab menjadi slug transliterasi. Tambahkan satu kasus uji judul Arab dengan hasil `alhmd-llh`. |
| 2 | major | Ringkasan `SanitizeContentHtml` / AC-13 | Klaim "membuang URL `data:` pada `href`/`src`" salah untuk `src`. Konfigurasi Filament (`SupportServiceProvider` baris 111-127) memakai `allowSafeElements()` dan `allowedMediaSchemes` bawaan Symfony `['http','https','data']` (`HtmlSanitizerConfig` baris 76). `<img src="data:image/svg+xml;base64,...">` lolos. Ini melewati aturan "SVG ditolak" dan batas ukuran upload, dan membuat body membengkak. AC-13 tidak menguji `data:`. | Pilih salah satu dan tulis jelas di rencana: (a) config sendiri tanpa skema `data` untuk media (`symfony/html-sanitizer` dideklarasikan eksplisit di `composer.json`, ini butuh persetujuan karena dependency baru), atau (b) pasca-proses/tolak `src="data:` di `SanitizeContentHtml` tanpa dependency baru. Tambahkan AC: `<img src="data:image/svg+xml;base64,...">` tidak ada di body tersimpan. Perbaiki juga teks klaim. |
| 3 | major | `SanitizeContentHtml` / AC-13 (batas panjang) | `withMaxInputLength(500000)` Symfony dalam **byte**, bukan karakter, dan memotong diam-diam. Rencana menulis "panjang mentah" dan AC-13 hanya menguji 500.001 karakter ASCII. Teks Arab (2 byte per huruf) yang di bawah 500.000 karakter tetap bisa lewat 500.000 byte lalu terpotong di tengah karakter. Konten Arab adalah kasus utama pesantren. | Cek dengan `strlen()` (byte), bukan `mb_strlen`. Tambahkan kasus AC-13: body Arab multibyte > 500.000 byte tetapi < 500.000 karakter → `body`/`too_long`, dan body multibyte di bawah batas tersimpan utuh. Sama untuk `UpdateStaticPage`. |
| 4 | major | `categories.slug` vs `GenerateSlug` | Kolom `categories.slug` `string(120)`, tetapi `GenerateSlug` menerima slug eksplisit hingga 200 dan basis hingga 190 karakter. Di MySQL strict, slug kategori 121-200 karakter gagal dengan error DB; SQLite (dev dan test) tidak menegakkan panjang, jadi tidak tertangkap (CLAUDE.md: portabel MySQL/SQLite/Postgres). | Beri `GenerateSlug` parameter batas panjang per model (120 untuk kategori, 200 untuk post dan album, 50 untuk halaman bila perlu), atau samakan kolom jadi `string(200)`. Tambahkan AC: slug eksplisit kategori 121 karakter → `slug`/`too_long`. |
| 5 | minor | Sanitasi / pertanyaan #1 | Konfigurasi Filament mengizinkan `style` dan `class` di semua elemen (mis. `position:fixed` untuk overlay palsu), dan `img` dengan `src` http/https eksternal (pelacak). Berisiko rendah (hanya penulis staf), dan default sudah diputuskan, tetapi kontrak F017 belum tertulis. | Tulis di "Tidak termasuk"/kontrak F017: portal merender body di wadah dengan `isolate overflow-hidden` dan menyanitasi ulang saat render; `title`, `excerpt`, `caption`, `description` adalah teks polos dan wajib di-escape (`{{ }}`). Pertimbangkan satu AC yang mengunci perilaku `style` yang diterima (agar perubahan versi Filament terdeteksi). |
| 6 | minor | Urutan implementasi langkah 3 | `shield:generate` membaca resource Filament, tetapi resource baru dibuat di langkah 11-13. Langkah 3 bergantung pada langkah berikutnya (checklist 10). | Di langkah 3 hanya ubah seeder, dataset test, dan buat policy manual (`make:policy`, pola `->can('<aksi>:<model>')` seperti policy yang ada); jalankan `shield:generate` hanya bila resource sudah ada, atau pindahkan pembuatan policy ke langkah 11-13 per resource (dataset AC-5 tetap di langkah 3). |
| 7 | minor | AC-4 / `SettingSeeder` | Seeder memakai `firstOrCreate`; instalasi atau DB dev yang sudah di-seed sudah punya `modules.konten.enabled = false`, sehingga mengubah `ENABLED_BY_DEFAULT` tidak mengaktifkannya. Pola ini sama dengan fitur sebelumnya, tetapi tidak disebut. | Tambahkan catatan di rencana: DB yang sudah ada mengaktifkan modul lewat panel/`Setting::set`; tidak perlu migration data. |
| 8 | minor | AC-12 `published_at` | `Carbon::parse` menerima string relatif ("tomorrow", "next monday") sebagai tanggal valid; hanya "besok" yang ditolak. Input Filament berupa tanggal-waktu terformat. | Parse ketat (`Carbon::createFromFormat` / hanya `CarbonInterface` atau `Y-m-d H:i:s`), dan uji "tomorrow" ditolak. Rencanakan juga perilaku menghapus `published_at` pada post yang sudah terbit (saat ini menjadi `now()`, mengubah tanggal terbit): tulis keputusannya dan uji. |
| 9 | minor | Arsitektur (CLAUDE.md "Filament hanya memanggil Action") | `DeleteAction`/`RestoreAction` bawaan Filament memanggil `$record->delete()`/`restore()` langsung untuk post, dan hapus video, tanpa Action class, sementara album, foto, dan kategori punya Action. Tidak konsisten. | Tambahkan `DeletePost`, `RestorePost`, `DeleteVideo` (tipis) atau catat sebagai pengecualian sadar untuk operasi tanpa logika. |
| 10 | minor | AC-32 dan AC-21 | AC-32 menulis "404 atau 403" (tidak deterministik). AC-21 menguji tombol pulihkan "lewat policy" saja, bukan tampilan aksi di `EditPost`. | Tetapkan satu kode respons (route `create` tidak didaftarkan → 404) dan uji `assertActionHidden`/`assertActionVisible` untuk `RestoreAction` per peran. |
| 11 | minor | Kontrak F017 / deployment | Tidak ada kontrak untuk daftar album (`withCount('photos')`, `coverPhoto`), penulis `author_id` NULL (nullOnDelete), dan ketergantungan `Storage::url()` pada `php artisan storage:link` (symlink sering dimatikan di cPanel) dan `APP_URL`. | Tambahkan satu paragraf kontrak untuk F017 dan satu catatan deployment (symlink `public/storage`, atau konfigurasi `url` disk `public`). |
| 12 | minor | ERD | Penyimpangan dari `docs/erd.md` (`author_id` nullable + `nullOnDelete`, unique `categories`, key `school.logo_path` vs `school.logo` di ERD, `posts.achievement_id` tanpa FK sementara) hanya dicatat "bila disetujui". ERD berprioritas di atas CLAUDE.md. | Tambahkan langkah eksplisit di urutan implementasi: perbarui `docs/erd.md` (tabel `posts`, `categories`, key settings) bersama commit migration. |

Catatan positif (tidak perlu aksi): cakupan `acceptance` entri F016 dan PRD 3.1 lengkap; dependensi F001 `done`; soft delete hanya `posts`; SVG ditolak lewat deteksi MIME server dengan nama berkas acak (uuid) di disk `public`; slug unik DB mencakup baris terhapus; key `school.address|phone|logo_path` cocok dengan `ShowPaymentReceipt`; `view:school-profile` untuk `admin-sekolah` sesuai PRD 2; timezone app `Asia/Jakarta` konsisten dengan AC-17; koordinasi file bersama (seeder, lang, Pest.php) sudah dijelaskan.

## Review #2 — 2026-10-15

Verdict: CHANGES_REQUESTED

Verifikasi perbaikan Review #1 (semua 12 temuan ditangani): #1 AC-10 kini benar (`★★★` → `post`, Arab → `alhmd-llh`); #2 `hasDisallowedMediaSource` + AC-13b — saya uji `Str::sanitizeHtml` langsung: output memakai `src="..."` berkutip ganda dengan entitas (`&#61;`), `data:` dipertahankan (jadi pemeriksaan pasca-sanitasi memang perlu dan berfungsi), `srcset` memang lolos sanitizer (sudah tercakup regex), teks `src="data:"` di node teks ter-escape sehingga tidak memicu positif palsu; #3 byte vs karakter (400.007 byte Arab tersimpan utuh, terverifikasi); #4 `$maxLength` per model; #5–#12 ditangani sesuai saran.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | AC-22 vs `SaveCategory` | AC-22 menulis "Nama 120 karakter tanpa slug → slug otomatis ≤ 120 karakter", tetapi `SaveCategory` menolak `name` > 100 (`name`/`too_long`; kolom `name` string(100)). AC tidak dapat dipenuhi implementasi yang benar. | Ubah menjadi nama 100 karakter (diterima, slug otomatis ≤ 120 karena basis dipotong `maxLength - 10`), dan tambahkan nama 101 karakter → `name`/`too_long`. |
| 2 | minor | `hasDisallowedMediaSource` | `srcset` dipecah per koma, padahal URL `data:` memuat koma; hasil tetap benar (skema `data` terdeteksi pada potongan pertama) tetapi AC-13b tidak mengunci `srcset`. | Tambahkan satu kasus dataset: `<img src="/a.jpg" srcset="/a.jpg 1x, data:image/png;base64,AAA 2x">` → `media_source_invalid`. |
| 3 | minor | AC-13b | Tidak ada kasus atribut `href` bertipe `data:`/`javascript:` pada tautan setelah perubahan; sudah tertutup AC-13 untuk `javascript:`, tetapi `data:` pada `<a href>` tidak diuji. | Tambahkan `<a href="data:text/html;base64,AAA">x</a>` → tersimpan tanpa `data:` (dibuang sanitizer). |

Catatan: setelah temuan #1 diperbaiki, rencana siap disetujui (tidak ada blocker, major tersisa hanya #1). Temuan #2 dan #3 boleh diterapkan sekaligus oleh PM tanpa putaran review baru bila orkestrator setuju.

- 2026-10-15 — Revisi 3 (orkestrator, atas Review #2): AC-22 memakai nama 100 karakter (diterima) dan 101 karakter (`name`/`too_long`); AC-13b ditambah kasus `srcset` dan `<a href="data:">`. Reviewer menyatakan rencana siap APPROVED setelah major #1 diperbaiki dan minor boleh diterapkan tanpa review ketiga; status diubah ke APPROVED.
