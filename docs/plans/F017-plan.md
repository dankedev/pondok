# F017 portal-publik — Portal publik

Status rencana: APPROVED

Owner: diklaim orkestrator di `feature_list.json` (product-manager tidak mengubah file itu pada siklus ini). Dependensi tertulis: F016 dan F010 (keduanya rencana APPROVED, belum diimplementasi). Dari F010 dipakai: layout `resources/views/layouts/public.blade.php`, komponen `x-ui.*`, route `psb.register`, helper `AdmissionWave::openForRegistration()`. Red-tester tidak boleh mulai sebelum F016 dan F010 terimplementasi di repo (lihat Risiko #1).

## Ringkasan & tujuan

Pengunjung umum (calon wali, wali santri, alumni, masyarakat) membuka situs sekolah dari HP dan bisa:

- melihat **beranda**: identitas sekolah, ajakan PSB bila pendaftaran dibuka, kabar terbaru, prestasi terbaru, video unggulan, dan galeri terbaru;
- membaca **berita, kegiatan, dan prestasi** (satu daftar `posts` terbit, disaring per jenis, kategori, dan jenjang, berpaginasi) serta detailnya lewat slug;
- melihat **galeri** (album dan foto), **video** YouTube, dan **halaman statis** profil, visi-misi, kontak;
- menemukan situs lewat mesin pencari: `<title>`, meta description, Open Graph dari `settings`, `sitemap.xml`, `robots.txt`.

Portal hanya membaca data yang sudah diterbitkan oleh F016. Tidak ada form, tidak ada akun, tidak ada data santri. Halaman portal bersifat **stateless** (tanpa sesi dan cookie) sehingga aman di-cache browser/proksi selama beberapa menit, dan dikirim dengan header keamanan termasuk CSP yang tetap mengizinkan embed YouTube.

Acuan: PRD 3.1 (portal, berita prestasi dari modul Prestasi), PRD 4 (shared hosting, UU PDP); ERD "Konten Publik (CMS)" dan "Prestasi Santri" (publikasi lewat `posts.achievement_id`); CLAUDE.md (Blade + Livewire + Tailwind, tanpa Node runtime, modul bisa dimatikan, route mengikuti saklar modul); design guide §0–§13; kontrak F017 di `docs/plans/F016-plan.md` ("Kontrak untuk F017"); layout dan komponen F010 (`docs/plans/F010-plan.md` "Aset dan layout publik"); catatan entri F017 ("pakai ulang layouts/public.blade.php dan components/ui/*").

## Ruang lingkup

- Termasuk:
  - Route publik bernama `portal.*` di `routes/web.php` (menggantikan route `/` → `welcome`), controller invokable `App\Http\Controllers\Portal\*` yang hanya memanggil Action.
  - Action baca `app/Actions/Konten/` untuk query portal (daftar post, detail post, beranda, galeri, video, halaman statis, sitemap) dan helper tampilan kecil (ringkasan teks, format telepon).
  - Memperluas layout F010 `resources/views/layouts/public.blade.php` (header + navigasi, footer kontak, meta SEO/OG, skip link) dan menambah komponen `x-ui.empty-state` serta komponen portal `x-portal.*`.
  - View Blade portal `resources/views/portal/**`, tampilan paginasi bertoken, halaman 404 `resources/views/errors/404.blade.php`.
  - Middleware `SetPortalCacheHeaders` (Cache-Control publik + ETag/304 hanya untuk 200) dan `SetPortalSecurityHeaders` (CSP + header keamanan), serta `ForcePortalRootUrl` (URL absolut dari `APP_URL`) pada grup route portal; grup portal tanpa middleware sesi/cookie/CSRF.
  - Parameter status opsional pada `EnsureModuleIsEnabled` (portal konten mati → 404).
  - `sitemap.xml` dan `robots.txt` dinamis tanpa dependency; menghapus `public/robots.txt` statis.
  - CSS isi artikel (`.portal-prose`) di `resources/css/app.css` memakai token; `@source` untuk view portal sudah tercakup bawaan Tailwind (view di `resources/views`).
  - Kunci config `pondok.portal.cache_max_age` dan `pondok.portal.csp_enabled`.
  - Teks `lang/id/pondok.php` blok `portal.*`; ikon baru di tabel §3 design guide.
  - Penyesuaian `tests/Feature/ExampleTest.php` (oleh red-tester) karena `/` kini merender layout publik.
  - **Keputusan orkestrator:** `sitemap.xml`, `robots.txt`, cache HTTP, dan CSP/header keamanan diterima sebagai bagian F017 (melengkapi `acceptance` entri dan PRD 3.1 "SEO dasar, cache sederhana, keamanan").
- Tidak termasuk (eksplisit):
  - Perubahan skema database (tidak ada migration). Tidak ada setting SEO baru (`seo.*`); semua meta dari key `school.*` F016.
  - Halaman rekap prestasi publik dari tabel `achievements` (tingkat, peringkat, daftar peserta) dan kartu prestasi yang membaca `posts.achievement_id`. **Keputusan orkestrator:** "berita prestasi mengambil data dari modul Prestasi" (PRD 3.1) dipenuhi oleh post bertipe `prestasi` yang dibuat F015 (`PublishAchievementAsPost`) lalu diterbitkan admin konten; F017 menampilkannya sebagai post biasa. Rekap prestasi publik **ditunda tanpa entri baru** (Risiko #4).
  - Pratinjau draf atau post terjadwal oleh admin di portal (portal stateless, tidak mengenali user login).
  - Pencarian teks, RSS/Atom, komentar, berbagi ke media sosial (tombol share), lightbox/slider JS, peta (embed Google Maps), JSON-LD/structured data, multi bahasa.
  - Cache sisi server (hasil query/HTML) — hanya cache HTTP. Tidak memakai Redis.
  - Header keamanan/CSP untuk route `/psb*` (Livewire F010) dan panel `/admin`.
  - Mengubah Action/kontrak F016 selain menambah satu helper statis `Video::embedUrlFor(string $id)` (lihat Model data).
  - Mode noindex untuk instalasi staging.
  - Panduan instalasi `storage:link` dan pemaketan `public/build` (F019; dicatat di Risiko).

## Model data

Tidak ada tabel atau kolom baru. Portal membaca tabel F016 (`posts`, `categories`, `albums`, `photos`, `videos`, `pages`, `settings`) dan F009/F010 (`admission_waves`) lewat model dan scope yang sudah dikontrakkan:

| Kebutuhan | Sumber (kontrak) |
|---|---|
| Post terbit | `Post::published()` (status `terbit`, `published_at <= now()`, tidak soft-deleted), `Post::ofType()`, `coverUrl()`, `youtubeId()`, relasi `category`, `unit` |
| Album | `Album::query()->withCount('photos')->with('coverPhoto', 'category')`, `photos()` (urut `sort_order`, `id`), `Photo::url()` |
| Video | `Video::ordered()`, `embedUrl()`, `watchUrl()` |
| Halaman statis | `StaticPage::findBySlug()`, `StaticPage::SLUGS` |
| Profil sekolah | `GetSchoolProfile::handle()` (`name`, `tagline`, `address`, `phone`, `whatsapp`, `email`, `instagram_url`, `facebook_url`, `youtube_url`, `logo_url`) |
| Sanitasi isi | `SanitizeContentHtml::handle()`, `SanitizeContentHtml::isBlank()` |
| PSB dibuka | `AdmissionWave::openForRegistration(CarbonInterface $today)` (F010: menerima pendaftaran dan belum penuh) |
| Saklar modul | `Setting::moduleEnabled('konten')`, `Setting::moduleEnabled('psb')` |

Tambahan kecil pada model F016 (bagian dari F017):

- `Video::embedUrlFor(string $youtubeId): string` (statis) → `https://www.youtube-nocookie.com/embed/{id}`; `embedUrl()` mendelegasikan ke sini. Dipakai juga untuk `youtube_url` pada post (`$post->youtubeId()`).

Konfigurasi baru di `config/pondok.php`:

```php
'portal' => [
    'cache_max_age' => (int) env('PORTAL_CACHE_MAX_AGE', 300), // detik; 0 = tanpa cache publik
    'csp_enabled' => (bool) env('PORTAL_CSP', true),
],
```

Konstanta paginasi di Action terkait: `ListPublicPosts::PER_PAGE = 9`, `ListPublicAlbums::PER_PAGE = 12`, `ShowPublicAlbum::PHOTOS_PER_PAGE = 24`, `ListPublicVideos::PER_PAGE = 9`. Batas sitemap `BuildSitemap::MAX_POSTS = 1000` (terbaru).

## Action class

Semua di `app/Actions/Konten/`. Action portal **tidak menulis** apa pun ke database dan **tidak memakai** user login. Action yang menerima filter tidak valid melempar `NotFoundHttpException` lewat `abort(404)` (pemanggilnya controller HTTP).

### Pembacaan query string (controller, review #1 #4)

Controller portal membaca parameter lewat helper bersama `App\Http\Controllers\Portal\Concerns\ReadsPortalQuery` (trait) sebelum memanggil Action:

- `jenis`, `kategori`, `jenjang`: `$request->query($key)`. Tidak ada / `''` → NULL. Nilai **bukan string skalar** (array, mis. `?jenis[]=berita`, `?kategori[x]=1`) → `abort(404)`. String → di-trim lalu diteruskan.
- `page`: tidak ada **atau string kosong** (`?page=`) → 1 (review #2 #4). Ada dan cocok `^[1-9][0-9]{0,5}$` → integer. Selain itu (`0`, `-1`, `abc`, `1.5`, `?page[]=2`, terlalu panjang) → `abort(404)`. Action menerima `int $page` yang sudah valid dan meneruskannya eksplisit ke `paginate(..., page: $page)` (paginator tidak membaca request sendiri).
- Parameter lain diabaikan.

### `SummarizeHtml::handle(?string $html, int $limit = 160): string`

- Sisipkan spasi **hanya di sekitar tag blok** (review #2 #3): pembuka dan penutup `p`, `div`, `br`, `li`, `h1`–`h6`, `tr`, `td`, `th`, `blockquote` (regex tidak peka huruf besar-kecil `/<\/?(?:p|div|br|li|h[1-6]|tr|td|th|blockquote)\b[^>]*>/i` → `' $0 '`), agar teks antar-blok tidak menempel (`<p>Halo</p><p>Dunia</p>` → "Halo Dunia") sementara tag inline (`strong`, `b`, `em`, `a`, `span`) tidak menyisipkan spasi (`Halo<strong>,</strong>` → "Halo,"), lalu `strip_tags`, `html_entity_decode(..., ENT_QUOTES | ENT_HTML5, 'UTF-8')`, rapikan whitespace berurutan (termasuk `\u{00A0}`) menjadi satu spasi, trim, lalu `Str::limit($text, $limit)` (akhiran `…`, multibyte aman). Mengembalikan **teks polos** (view tetap meng-escape).
- Dipakai untuk ringkasan kartu bila `excerpt` NULL, meta description, dan cuplikan profil di beranda.

### `FormatPhoneForDisplay::handle(?string $digits): ?string` dan `whatsappUrl(?string $digits): ?string`

- Input adalah nilai tersimpan F016 (sudah dinormalisasi `^\+?[0-9]{8,15}$`). NULL/kosong → NULL.
- Tampilan (design guide §5): diawali `0` → kelompok 4-4-sisa dipisah `-` (`081234567890` → `0812-3456-7890`); diawali `+62` → `+62 ` + kelompok 3-4-sisa (`+6281234567890` → `+62 812-3456-7890`); selain itu dikembalikan apa adanya.
- `whatsappUrl`: `0…` → `https://wa.me/62…` (buang `0` depan); `+…` → `https://wa.me/…` tanpa `+`.
- Tautan `tel:` memakai nilai tersimpan apa adanya.

### `ListPublicPosts::handle(array $filters, int $page = 1): array`

`$filters`: `array{jenis?: ?string, kategori?: ?string, jenjang?: ?string}` (sudah disaring controller menjadi string atau NULL, lihat "Pembacaan query string").

1. `jenis` terisi dan bukan anggota `Post::TYPES` (perbandingan ketat `in_array(..., true)`) → 404.
2. `kategori` terisi: cari `Category::ofType('post')->where('slug', ...)`; tidak ada → 404.
3. `jenjang` terisi: normalisasi **di PHP** `Str::upper($jenjang)`, lalu cocokkan dengan koleksi `Unit::query()->orderBy('sort_order')->get()` lewat `firstWhere('code', ...)` (kode unit disimpan huruf besar; tanpa `LOWER()`/`LIKE`/collation spesifik DB). Tidak ada → 404. Filter **persis** `unit_id = unit` (post umum tidak ikut; Risiko #5).
4. Query `Post::published()` + filter, `with(['category', 'unit'])`, urut `published_at` desc lalu `id` desc, `paginate(PER_PAGE, page: $page)->withPath(route('portal.posts.index'))->withQueryString()`.
5. `$page > 1` dan `$page > lastPage()` → 404.
6. Kembalian `array{posts: LengthAwarePaginator, type: ?string, category: ?Category, unit: ?Unit, categories: Collection<Category>, units: Collection<Unit>}`. `categories` = kategori `post` yang punya minimal satu post terbit (untuk chip filter); `units` = semua unit urut `sort_order`.

### `FindPublicPost::handle(string $slug): Post`

- `Post::published()->where('slug', $slug)->with(['category', 'unit'])->first()` → NULL → 404. Draf, terjadwal, dan terhapus semuanya 404 (tidak membedakan).
- Mengembalikan post; controller menyiapkan `bodyHtml = new HtmlString(SanitizeContentHtml::handle($post->body))` (sanitasi ulang saat render, kontrak F016).

### `GetPortalHomeData::handle(?CarbonInterface $today = null): array`

Kembalian `array{school: array, psbOpen: bool, profileSummary: ?string, latestNews: Collection<Post>, latestAchievements: Collection<Post>, featuredVideos: Collection<Video>, latestAlbums: Collection<Album>, contentEnabled: bool}`.

- `school` = `GetSchoolProfile::handle()`.
- `psbOpen` = `Setting::moduleEnabled('psb')` **dan** `Route::has('psb.register')` **dan** `AdmissionWave::openForRegistration($today ?? today())->isNotEmpty()`. Urutan dievaluasi pendek (tidak ada query gelombang bila modul mati atau route tidak ada).
- Bila `contentEnabled` (`Setting::moduleEnabled('konten')`) false: semua koleksi konten kosong dan `profileSummary` NULL, **tanpa query** tabel konten.
- Bila aktif:
  - `profileSummary` = `SummarizeHtml(StaticPage::findBySlug('profil')?->body, 300)`; kosong → NULL.
  - `latestNews` = 6 post terbit bertipe `berita` atau `kegiatan`, terbaru.
  - `latestAchievements` = 3 post terbit bertipe `prestasi`, terbaru.
  - `featuredVideos` = `Video::ordered()->where('is_featured', true)->limit(3)`.
  - `latestAlbums` = 4 album dengan `photos_count > 0`, urut `updated_at` desc, `with('coverPhoto')`.

### `ListPublicAlbums::handle(?string $categorySlug, int $page = 1): array`

- `categorySlug` terisi tetapi bukan kategori `album` → 404.
- Album **yang punya foto saja** (`has('photos')`), `withCount('photos')`, `with(['coverPhoto', 'category'])`, urut `updated_at` desc lalu `id` desc, `paginate(PER_PAGE, page: $page)->withPath(route('portal.albums.index'))->withQueryString()`; halaman di luar jangkauan (> 1) → 404.
- Kembalian `array{albums, category: ?Category, categories}` (`categories` = kategori `album` yang punya album berfoto).

### `ShowPublicAlbum::handle(string $slug, int $page = 1): array`

- Album dengan slug itu yang punya foto; tidak ada atau tanpa foto → 404.
- Foto `photos()->paginate(PHOTOS_PER_PAGE, page: $page)->withPath(route('portal.albums.show', $album->slug))`; halaman > 1 di luar jangkauan → 404.
- Kembalian `array{album: Album, photos: LengthAwarePaginator}`.

### `ListPublicVideos::handle(int $page = 1): LengthAwarePaginator`

- `Video::ordered()->paginate(PER_PAGE, page: $page)->withPath(route('portal.videos.index'))`; halaman > 1 di luar jangkauan → 404.

### `ShowPublicStaticPage::handle(string $slug): array`

- `slug` bukan anggota `StaticPage::SLUGS` atau baris tidak ada → 404.
- Kembalian `array{page: StaticPage, bodyHtml: ?HtmlString, school: array}`; `bodyHtml` NULL bila `SanitizeContentHtml::isBlank()` atas body tersanitasi, selain itu `HtmlString` hasil sanitasi ulang.

### `BuildSitemap::handle(): list<array{loc: string, lastmod: ?string}>`

- Selalu: `route('portal.home')`.
- Bila modul `psb` aktif dan `Route::has('psb.register')`: `route('psb.register')`.
- Bila modul `konten` aktif: `portal.posts.index`, `portal.albums.index`, `portal.videos.index`; tiap halaman statis yang body-nya tidak kosong (`portal.pages.show`, lastmod `updated_at`); post `published()` maksimal `MAX_POSTS` terbaru (lastmod `updated_at`); album berfoto (lastmod `updated_at`).
- `lastmod` format `toAtomString()` (W3C). Tidak ada URL dengan query string.
- Semua `loc` dibangun dari `config('app.url')` (lihat "URL kanonik dari `APP_URL`").

## UI (Filament / Blade)

Rujukan design guide: §1 (tenang, ringan), §2 (token, tanpa `dark:`, tanpa warna mentah), §3 (Instrument Sans lewat `@fonts`, skala teks, Heroicon Outlined `<x-heroicon-o-… />`), §5 (tanggal `j M Y` locale `id`, telepon), §6 portal (mobile-first, `mx-auto max-w-6xl px-4 sm:px-6`, `py-12`/`py-16`, `rounded-lg`, `shadow-sm` maks., target sentuh 44px, foto `aspect-video object-cover loading="lazy"` + `alt`), §7 (kosong, modul mati), §9 (sapaan "Anda" di portal), §10 (aksesibilitas), §12 (tanpa library JS, tanpa CDN runtime, tanpa ilustrasi makhluk bernyawa), §13 (alur anti-ui-slop: implementer memanggil `/anti-ui-slop` bila tersedia, tetap di dalam batas panduan). Checklist §0 wajib sebelum selesai.

Tidak ada perubahan panel Filament.

### Kontrak desain (§13.3) — beranda dan daftar berita

- **Pengguna & tujuan:** calon wali di HP Android ingin tahu sekolah ini seperti apa dan apakah pendaftaran dibuka; wali santri ingin membaca kabar terbaru.
- **Aksi utama:** "Daftar santri baru" (bila PSB dibuka); bila tidak, tidak ada CTA PSB dan aksi utama menjadi "Baca kabar terbaru". Sekunder: "Lihat profil", tautan kontak/WhatsApp.
- **Urutan informasi beranda:** (1) identitas: logo, nama sekolah (`h1`), slogan; label accent "Pendaftaran santri baru dibuka" + tombol primary (satu-satunya elemen accent); (2) cuplikan profil + "Selengkapnya"; (3) Kabar terbaru (6 kartu); (4) Prestasi terbaru (3 kartu); (5) Video unggulan; (6) Galeri terbaru; footer kontak. Seksi tanpa data **disembunyikan** di beranda (beranda bukan halaman daftar; halaman daftar tetap punya empty state).
- **State:** modul konten mati (beranda hanya identitas + PSB + kontak; tautan konten hilang dari navigasi; URL konten 404); konten kosong (seksi hilang, empty state di halaman daftar); PSB tutup (tanpa label accent dan tombol daftar); profil sekolah belum diisi (nama fallback `config('app.name')`, tanpa logo: tampilkan nama saja).
- **360px:** satu kolom; header berisi nama sekolah + tombol menu `<details>`/`<summary>` (tanpa JS) yang membuka daftar tautan vertikal bertinggi ≥ 44px; kartu satu kolom, `sm:grid-cols-2`, `lg:grid-cols-3`; chip filter dapat dibungkus (`flex-wrap`), tidak ada scroll horizontal; tombol utama `w-full sm:w-auto`.

### Layout `resources/views/layouts/public.blade.php` (milik F010, diperluas)

Dipakai sebagai anonymous component `<x-layouts::public>` (namespace Livewire `layouts`, sudah terdaftar di `config/livewire.php`) dan sebagai layout Livewire F010. Props (semuanya opsional, kompatibel dengan pemakaian F010):

| Prop | Bawaan | Isi |
|---|---|---|
| `title` | NULL | judul halaman; `<title>` = `"{title} — {school.name}"`, atau `school.name` (+ `" — {tagline}"` bila ada) bila NULL |
| `description` | tagline, atau `__('pondok.portal.meta.default_description', ['name' => school.name])` | meta description dan `og:description` (teks polos, maks. 160) |
| `canonical` | `route('portal.home')` (berbasis `APP_URL`) | `<link rel="canonical">` dan `og:url`; halaman mengirim `route(...)` miliknya |
| `ogType` | `website` | `article` untuk detail post |
| `ogImage` | `logo_url` atau NULL | URL absolut; tag `og:image` dan `twitter:card=summary_large_image` hanya bila ada |
| `noindex` | false | `<meta name="robots" content="noindex">` (halaman 404) |

Isi `<head>`: `<meta charset>`, `<meta name="viewport" content="width=device-width, initial-scale=1">`, `<title>`, description, canonical, `og:site_name`, `og:locale` `id_ID`, `og:title`, `og:description`, `og:type`, `og:url`, `og:image` (bila ada), favicon bawaan, `@fonts`, `@vite(['resources/css/app.css'])` (tanpa `app.js`; portal tidak butuh JS). Semua nilai lewat `{{ }}`.

Larangan di layout (review #1 #2; layout dipakai bersama `/psb` yang bersesi dan portal yang di-cache `public`):
- Tidak memuat `@livewireStyles`, `@livewireScripts`, `@livewireScriptConfig`, `<meta name="csrf-token">`, `@csrf`, atau `csrf_token()`. Bila F010 sudah menulisnya di layout, F017 **menghapusnya**: halaman Livewire F010 mengandalkan auto-inject aset Livewire 4 (`inject_assets` bawaan true), yang menyuntik skrip dan CSRF hanya ke respons yang benar-benar merender komponen Livewire.
- Tidak memanggil `session()`, `old()`, `auth()`/`@auth`, atau `$errors`.
- Regresi dijaga AC-28 (portal bebas token/aset Livewire, `/psb` tetap memuat aset Livewire).

Struktur `<body class="bg-gray-50 text-gray-900 font-sans">`:
1. Skip link `<a href="#konten">` `pondok.portal.nav.skip_to_content` (tersembunyi sampai fokus, `focus-visible:ring-2 focus-visible:ring-primary-500`).
2. `<header>`: logo (`<img alt="{school.name}">` bila ada) + nama sekolah (bukan `h1`) tertaut ke beranda. Daftar tautan: Beranda, Profil, Berita, Galeri, Video, Kontak (lima terakhir hanya bila modul konten aktif), dan tombol primary "Daftar" ke `route('psb.register')` bila modul PSB aktif dan route ada (tanpa cek gelombang, agar header tidak query tiap halaman; halaman `/psb` F010 menangani "belum dibuka"). Daftar tautan dirender dua kali dari satu partial (review #1 #10):
   - desktop: `<nav class="hidden md:flex" aria-label="{pondok.portal.nav.label}">`;
   - mobile: `<details class="md:hidden">` dengan `<summary>` "Menu" + ikon `Bars3` (`aria-hidden`), berisi `<nav aria-label="{pondok.portal.nav.mobile_label}">` dengan tautan vertikal ≥ 44px.
   Label kedua `<nav>` (dan `<nav>` paginasi/filter) **berbeda satu sama lain**. Tautan aktif mendapat `aria-current="page"`; penentuan aktif memakai `request()->routeIs(...)` yang aman bila `request()->route()` NULL (halaman 404 tanpa route → tidak ada tautan aktif).
3. `<main id="konten">{{ $slot }}</main>`.
4. `<footer>`: nama sekolah, alamat (ikon `MapPin`), telepon (`tel:`, format `FormatPhoneForDisplay`, ikon `Phone`), WhatsApp (`wa.me`, ikon `ChatBubbleLeftRight`), email (`mailto:`, ikon `Envelope`), media sosial sebagai tautan teks "Instagram", "Facebook", "YouTube" (`rel="noopener"`, tanpa ikon merek). Baris yang NULL tidak dirender. Tautan "Visi & misi" bila konten aktif.

Header/footer membaca `GetSchoolProfile` sekali per request (Setting sudah di-cache per request oleh F033).

### Komponen

- Pakai ulang F010: `x-ui.button`, `x-ui.badge`, `x-ui.card`, `x-ui.alert`. Tidak membuat varian baru.
- Baru `resources/views/components/ui/empty-state.blade.php`: slot ikon (Heroicon Outlined), satu kalimat, aksi opsional.
- Baru `resources/views/components/portal/`:
  - `post-card` (props `post`): sampul `aspect-video object-cover loading="lazy" alt=""` (dekoratif, judul di bawahnya) atau blok placeholder `bg-gray-100` dengan ikon `Newspaper` (`aria-hidden`); badge jenis (`x-ui.badge color="gray"`, label `pondok.post.types.*`); judul `h3` (atau `h2` di halaman daftar; prop `headingLevel`) tertaut ke detail; tanggal `<time datetime="{atom}">j M Y</time>`; ringkasan `excerpt` atau `SummarizeHtml(body, 160)`. Tanpa nama penulis.
  - `album-card` (props `album`): foto sampul `coverPhoto`, judul, kategori, `trans_choice('pondok.portal.albums.photos_count', n)`.
  - `video-embed` (props `youtubeId`, `title`): `<iframe src="{Video::embedUrlFor}" title="{title}" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" class="aspect-video w-full rounded-lg">` + tautan teks "Tonton di YouTube" (`watchUrl`) sebagai cadangan.
  - `filter-chips` (props daftar `{label, url, active}`): `<nav aria-label>` berisi tautan chip `rounded-full`, aktif `aria-current="true"` + `bg-primary-600 text-white`.
- Paginasi: view `resources/views/portal/partials/pagination.blade.php` (dipanggil `->onEachSide(1)->links('portal.partials.pagination')`), teks dari `lang/id/pagination.php`, `<nav aria-label="{pondok.portal.pagination_label}">`, hanya kelas token, target sentuh ≥ 44px, tanpa `dark:`.

### Halaman

| Route | View | Isi |
|---|---|---|
| `portal.home` | `portal/home` | sesuai kontrak desain. Label accent "Pendaftaran santri baru dibuka" + tombol "Daftar santri baru" ke `route('psb.register')` hanya bila `psbOpen`. Tiap seksi punya `h2` dan tautan "Lihat semua" ke daftar terkait (berita, `?jenis=prestasi`, video, galeri). |
| `portal.posts.index` | `portal/posts/index` | `h1` sesuai filter jenis (`pondok.portal.posts.titles.{all,berita,kegiatan,prestasi}`); chip jenis (Semua, Berita, Kegiatan, Prestasi); chip kategori (bila ada); chip jenjang (Semua jenjang + nama unit); grid `x-portal.post-card`; paginasi; empty state "Belum ada berita yang diterbitkan." (kalimat per filter tidak dibedakan). `title` layout = judul `h1`; `canonical` = URL dengan filter yang dikenali + `page` bila > 1. |
| `portal.posts.show` | `portal/posts/show` | badge jenis, `h1` judul, `<time>` tanggal terbit, kategori (tertaut ke daftar terfilter) dan jenjang bila ada; sampul (alt = judul) bila ada; `excerpt` sebagai lead (`text-lg text-gray-600`) bila ada; video YouTube bila `youtube_url`; body di `<div class="portal-prose isolate overflow-hidden break-words">{!! $bodyHtml !!}</div>` (**satu-satunya** `{!! !!}` di portal, selain halaman statis); tautan "Kembali ke daftar". Layout: `ogType` `article`, `ogImage` = `coverUrl()` atau logo, `description` = excerpt atau `SummarizeHtml(body)`. |
| `portal.albums.index` | `portal/albums/index` | `h1` "Galeri"; chip kategori album; grid `x-portal.album-card`; paginasi; empty state "Belum ada album foto." |
| `portal.albums.show` | `portal/albums/show` | `h1` judul album, kategori, deskripsi (teks polos, `whitespace-pre-line`); grid foto `<figure>`: `<a href="{Photo::url}">` membungkus `<img aspect-video object-cover loading="lazy" alt="{caption atau pondok.portal.albums.photo_alt (judul album, nomor urut)}">`, `<figcaption>` bila caption; paginasi. |
| `portal.videos.index` | `portal/videos/index` | `h1` "Video"; daftar `x-portal.video-embed` + judul `h2` + deskripsi (teks polos); paginasi; empty state "Belum ada video." |
| `portal.pages.show` | `portal/pages/show` | `h1` = `page.title`; body di wadah `portal-prose isolate overflow-hidden break-words` bila tidak kosong; untuk `kontak` selalu ditambah kartu kontak dari profil sekolah (baris yang ada saja); empty state "Halaman ini belum diisi." bila body kosong **dan** (bukan `kontak` atau tidak ada data kontak). `profil` menautkan ke "Visi & misi" dan sebaliknya. |
| (404) | `errors/404` | layout publik, `noindex`, `h1` "Halaman tidak ditemukan", satu kalimat, tombol "Kembali ke beranda"; tautan "Baca berita terbaru" hanya bila modul konten aktif. Tanpa detail teknis. |

`portal-prose` (di `resources/css/app.css`, `@layer components` + `@apply` kelas token). **Pengurungan gaya (review #1 #3, keputusan orkestrator):** aturan `.portal-prose` memuat `contain: paint;` dan `isolation: isolate;` (setara kelas `isolate`). `contain: paint` menjadikan wadah sebagai containing block untuk turunan `position: fixed`/`absolute` dan memotong lukisan di luar batasnya, sehingga atribut `style="position:fixed;inset:0"` yang masih diizinkan sanitizer F016 (AC-13c F016) tidak bisa menutupi header, footer, atau bagian halaman lain. Koreksi klaim sebelumnya: `isolate` + `overflow-hidden` saja **tidak** mengurung elemen `fixed` (hanya membuat stacking context dan memotong overflow elemen non-fixed). Kelas `isolate overflow-hidden` tetap dipasang di markup, tetapi pengamannya adalah `contain: paint`. Gaya lain: jarak paragraf, `h2` `text-2xl`/`h3` `text-lg` `font-semibold`, daftar berbutir/bernomor, `blockquote` dengan border kiri `border-gray-300`, tautan `text-primary-700 underline`, `img` `max-w-full h-auto rounded-lg`, `table` dibungkus `overflow-x-auto` (CSS `display:block; overflow-x:auto` pada `table`). Tanpa `!important`, tanpa nilai arbitrer, tanpa `dark:`.

### `sitemap.xml` dan `robots.txt`

- `GET /sitemap.xml` → `Content-Type: application/xml; charset=UTF-8`, `<?xml version="1.0" encoding="UTF-8"?>` + `<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">`, satu `<url><loc>…</loc>[<lastmod>…</lastmod>]</url>` per entri `BuildSitemap`. `loc` di-escape XML (`htmlspecialchars(..., ENT_XML1)`); view `portal/sitemap.blade.php` mencetak deklarasi XML lewat `{!! '<?xml …?>' !!}` agar aman dari short open tag.
- `GET /robots.txt` → `Content-Type: text/plain; charset=UTF-8`:

```
User-agent: *
Disallow: /admin
Disallow: /psb/status
Disallow: /psb/berkas/
Sitemap: {route('portal.sitemap')}
```

- `public/robots.txt` dihapus (berkas statis akan didahulukan server web dan menutupi route).

### Teks `lang/id/pondok.php` (kunci baru, blok `portal`)

`portal.nav.{label, mobile_label, skip_to_content, menu, home, profile, vision, news, gallery, videos, contact, register}`; `portal.meta.default_description`; `portal.home.{psb_open_badge, psb_cta, profile_title, read_profile, latest_news, latest_achievements, featured_videos, latest_albums, see_all}`; `portal.posts.{titles.{all,berita,kegiatan,prestasi}, filter_types_label, filter_categories_label, filter_units_label, all_types, all_units, empty, back, published_on, category, unit}`; `portal.albums.{title, filter_label, all_categories, empty, photos_count (trans_choice), photo_alt}`; `portal.videos.{title, empty, watch_on_youtube}`; `portal.pages.{empty}`; `portal.contact.{title, address, phone, whatsapp, email, social, instagram, facebook, youtube}`; `portal.errors.{not_found_title, not_found_body, back_home, read_news}`; `portal.pagination_label`; `portal.footer.{rights}` (mis. ":name"). Label jenis post memakai `pondok.post.types.*` milik F016. Sapaan "Anda" bila menyapa (§9), tanpa emoji, kapital hanya di awal kalimat.

### Design guide

Tambah baris di tabel ikon §3: Galeri `Photo`, Video `Film`, Alamat `MapPin`, Telepon `Phone`, Email `Envelope`, WhatsApp `ChatBubbleLeftRight`, Menu `Bars3`. Tidak ada token warna atau status baru.

## Route & middleware

`routes/web.php` (route `/` → `welcome` dihapus, `resources/views/welcome.blade.php` dihapus):

```php
Route::name('portal.')
    ->withoutMiddleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, ValidateCsrfToken::class])
    ->middleware([ForcePortalRootUrl::class, SetPortalSecurityHeaders::class, SetPortalCacheHeaders::class])
    ->group(function () {
        Route::get('/', ShowHome::class)->name('home');
        Route::get('/sitemap.xml', ShowSitemap::class)->name('sitemap');
        Route::get('/robots.txt', ShowRobots::class)->name('robots');

        Route::middleware(EnsureModuleIsEnabled::class.':konten,404')->group(function () {
            Route::get('/berita', ListPosts::class)->name('posts.index');
            Route::get('/berita/{slug}', ShowPost::class)->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('posts.show');
            Route::get('/galeri', ListAlbums::class)->name('albums.index');
            Route::get('/galeri/{slug}', ShowAlbum::class)->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('albums.show');
            Route::get('/video', ListVideos::class)->name('videos.index');
            Route::get('/{page}', ShowStaticPage::class)->whereIn('page', StaticPage::SLUGS)->name('pages.show');
        });
    });
```

Daftar `withoutMiddleware` dicocokkan per nama kelas dengan isi grup `web` Laravel 13 (`Illuminate\Foundation\Configuration\Middleware::getMiddlewareGroups()`): `EncryptCookies`, `AddQueuedCookiesToResponse`, `StartSession`, `ShareErrorsFromSession`, `Illuminate\Foundation\Http\Middleware\PreventRequestForgery` (review #1 #1; `ValidateCsrfToken` hanya subclass `@deprecated` dan ikut dicantumkan sebagai pengaman bila aplikasi mengganti grup). `SubstituteBindings` tetap berjalan. Bila versi Laravel menambah middleware sesi/cookie lain ke grup `web`, AC-28 akan gagal dan daftar ini diperbarui.

Route F010 `/psb*` tetap memakai grup `web` penuh (sesi, CSRF, Livewire) dan **tidak** mendapat kedua middleware portal. Route `/{page}` didaftarkan **setelah** route F010 dan route panel tidak terpengaruh karena dibatasi `whereIn`.

Query string: `jenis`, `kategori`, `jenjang`, `page`; aturan validasi di "Pembacaan query string" (nilai non-skalar dan `page` tidak valid → 404). Parameter lain diabaikan.

### URL kanonik dari `APP_URL` (review #1 #5)

Respons portal di-cache `public`, jadi URL absolut buatan aplikasi di dalamnya tidak boleh bergantung pada header `Host` permintaan. Middleware tersendiri `App\Http\Middleware\ForcePortalRootUrl` (review #2 #5; dijalankan **pertama** di grup portal, sebelum `SetPortalSecurityHeaders`):
- `$root = rtrim((string) config('app.url'), '/')`. Kosong → tidak melakukan apa-apa (pakai host permintaan).
- `$scheme = parse_url($root, PHP_URL_SCHEME)`. Bila NULL/tidak dikenal (mis. `APP_URL=alkhair.sch.id`), root diberi skema permintaan saat ini (`$request->getScheme().'://'.$root`) dan `forceScheme` **tidak** dipanggil; bila `http`/`https`, panggil `URL::forceRootUrl($root)` dan `URL::forceScheme($scheme)`.
- Akibatnya `route()`/`url()` untuk canonical, `og:url`, sitemap, robots, tautan navigasi, **dan tautan paginasi** memakai `APP_URL`.

**Paginasi (review #2 #1):** `Paginator::currentPathResolver` bawaan memakai `request()->url()` (host dari header `Host`) dan tidak terpengaruh `forceRootUrl`. Karena itu semua paginator portal wajib `->withPath(route('portal.<nama>', <parameter>))`: `ListPublicPosts` → `route('portal.posts.index')`, `ListPublicAlbums` → `route('portal.albums.index')`, `ShowPublicAlbum` → `route('portal.albums.show', $album->slug)`, `ListPublicVideos` → `route('portal.videos.index')` (tetap `withQueryString()` di daftar yang berfilter).

**Batas:** `og:image` (logo/sampul) berasal dari `Storage::disk('public')->url()`, yang memakai `filesystems.disks.public.url` (dihitung dari `APP_URL` saat config dimuat), bukan `forceRootUrl`; tidak terpengaruh header `Host`, tetapi benar hanya bila `APP_URL` benar **sebelum** `config:cache` (panduan F019). `canonical` dibangun dari `route(<nama route>, <parameter>)` + query filter yang dikenali, bukan `url()->current()`.

### `EnsureModuleIsEnabled` (perubahan kecil)

Signature `handle(Request $request, Closure $next, string $module, string $status = '403')`. `$status === '404'` → `abort(404)` tanpa pesan; selain itu perilaku lama (403 dengan pesan). Pemakaian yang ada tidak berubah.

### `App\Http\Middleware\SetPortalCacheHeaders`

- Hanya bila method `GET`/`HEAD`, status 200, dan `config('pondok.portal.cache_max_age') > 0`: `$response->setPublic()`, `setMaxAge(max_age)`, `setEtag(md5($response->getContent()))`, lalu `$response->isNotModified($request)` (Symfony; mengubah menjadi 304 tanpa body bila `If-None-Match` cocok).
- Status lain (404, 500) dan `max_age = 0` → header tidak disentuh (bawaan Laravel `no-cache, private`).

### `App\Http\Middleware\SetPortalSecurityHeaders`

Selalu (semua status, termasuk 404 yang dilempar di dalam route portal). Header ini **hanya** untuk respons dari route portal; 404 untuk URL tanpa route (mis. `/tidak-ada-halaman`) atau route lain (`/psb/...`, `/admin/...`) memakai view 404 yang sama tetapi tanpa header portal (review #1 #9):
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin` (embed YouTube butuh referer asal; jangan `no-referrer`)
- `X-Frame-Options: SAMEORIGIN`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`

Bila `config('pondok.portal.csp_enabled')` true **dan** `! Vite::isRunningHot()` (dev server Vite memakai origin lain):

```
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' https: http:; font-src 'self'; frame-src https://www.youtube-nocookie.com; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'
```

Alasan tiap pengecualian: `style-src 'unsafe-inline'` karena `@fonts` mencetak `<style>` inline dan body tersanitasi F016 boleh memuat atribut `style` (AC-13c F016); `img-src https: http:` karena body boleh memuat gambar eksternal http/https (F016 menolak `data:`); `frame-src` hanya domain nocookie yang dipakai `embedUrlFor`. Tidak ada `script-src` inline; portal tidak memuat JS.

## Permission & cakupan

- **Tidak ada permission baru.** Portal untuk tamu; tidak membaca user login (grup tanpa sesi).
- **Tidak ada cakupan jenjang** (`unit_user`): konten publik tidak memakai `HasUnitScope` (F016). `jenjang` hanya filter tampilan.
- **Saklar modul:**
  - `modules.konten.enabled` false → `/berita*`, `/galeri*`, `/video`, `/profil`, `/visi-misi`, `/kontak` **404** (bukan 403, agar pengunjung tidak melihat pesan admin); navigasi dan seksi konten beranda hilang; sitemap hanya beranda (+ PSB). Beranda, `sitemap.xml`, `robots.txt` selalu tersedia.
  - `modules.psb.enabled` false atau route `psb.register` tidak ada → tidak ada tombol "Daftar" di header dan tidak ada CTA PSB di beranda; `/psb` tidak masuk sitemap.
  - Modul `prestasi` tidak memengaruhi portal: post bertipe `prestasi` adalah konten (Risiko #4).
- **Data pribadi:** portal tidak pernah meng-query `students`, `guardians`, `applicants`, `achievements`, `achievement_student`, `documents`, `invoices`, atau `users`; nama penulis post tidak ditampilkan. Yang tampil hanya isi yang ditulis/diterbitkan admin konten.

## Kriteria penerimaan (bisa dites)

Fixture bersama (helper `portal*` di **akhir** `tests/Pest.php`, bagian terpisah): `$this->withoutVite()` di `beforeEach` setiap file test portal; `UnitSeeder`, `SettingSeeder`, `StaticPageSeeder`, `ContentCategorySeeder`; `Setting::set('modules.konten.enabled', true)`; `Setting::set('school.name', "Ma'had Al-Khair")`, `school.tagline` "Mencetak generasi Qur'ani"; `Storage::fake('public')`; waktu dibekukan `2026-10-15 10:00` Asia/Jakarta. Helper `portalPost(array $attributes = [])` (factory `Post` terbit, `published_at` 2026-10-14 08:00 bawaan), `portalAlbumWithPhotos(int $count = 2, array $attributes = [])`, `portalPsbOpen()` (memakai `pendaftaranFixture()` F010: modul PSB aktif dan gelombang `W1` terbuka). Data dibuat langsung lewat factory (bukan lewat `SavePost`) agar test sanitasi ulang bisa memasukkan HTML mentah.

### Route, saklar modul, 404

- AC-1: `GET /` sebagai tamu → 200, memuat "Ma'had Al-Khair" di `<title>` dan `h1`, dan "Mencetak generasi Qur'ani". `route('portal.home')` = `url('/')`. `resources/views/welcome.blade.php` tidak ada.
- AC-2: Modul konten aktif → `GET /berita`, `/berita/{slug post terbit}`, `/galeri`, `/galeri/{slug album berfoto}`, `/video`, `/profil`, `/visi-misi`, `/kontak` → 200. Modul konten mati → kedelapannya **404** (bukan 403), sedangkan `/`, `/sitemap.xml`, `/robots.txt` tetap 200; beranda tidak memuat tautan `route('portal.posts.index')`, `route('portal.albums.index')`, `route('portal.videos.index')`, dan tidak memuat judul post terbit yang ada.
- AC-3: `EnsureModuleIsEnabled` tanpa parameter status tetap 403 (regresi): route panel/PSB yang memakai `:psb` saat modul PSB mati → 403 (test F010 yang ada tetap lulus).
- AC-4: 404 seragam untuk detail post: slug tidak ada, post `draft`, post `terbit` dengan `published_at` 2026-10-20 (terjadwal), post terbit yang soft-deleted, dan slug huruf besar (`/berita/Wisuda`) → 404. Setelah `travelTo('2026-10-20 08:00')` post terjadwal → 200.
- AC-5: Halaman 404 portal: memuat `pondok.portal.errors.not_found_title` (terjemahan), `<meta name="robots" content="noindex">`, tautan ke `route('portal.home')`, `lang="id"`, dan tidak memuat teks exception, nama kelas, atau path berkas (`NotFoundHttpException`, `app/`, `vendor/`). Juga untuk URL tak terdaftar (`/tidak-ada-halaman`).
- AC-6: Filter tidak valid → 404: `/berita?jenis=artikel`, `/berita?kategori=tidak-ada`, `/berita?kategori={slug kategori bertipe album}`, `/berita?jenjang=smk`, `/berita?page=5` saat hanya ada 1 halaman. Nilai non-skalar dan `page` tidak valid → **404, bukan 500**: `/berita?jenis[]=berita`, `/berita?kategori[]=a`, `/berita?kategori[x]=1`, `/berita?jenjang[]=sltp`, `/berita?page=abc`, `/berita?page=0`, `/berita?page=-1`, `/berita?page[]=2`. `/berita?page=1` dan `/berita?page=` tanpa post → 200 dengan empty state (`page` kosong = halaman 1). `/berita?jenjang=sltp` dan `?jenjang=SLTP` keduanya diterima.
- AC-6a (galeri, setelah route galeri ada): `/galeri?kategori={slug kategori bertipe post}`, `/galeri?kategori[]=a`, `/galeri?page=abc`, `/galeri/{slug album tanpa foto}`, `/video?page=0` → 404.

### Daftar & detail berita

- AC-7: `GET /berita` dengan post terbit A (berita, 2026-10-14), B (kegiatan, 2026-10-13), C (prestasi, 2026-10-12), D draft, E terjadwal 2026-10-20, F soft-deleted → memuat judul A, B, C dalam urutan itu (posisi string), tidak memuat D, E, F. Tanggal A tampil "14 Okt 2026" dalam `<time datetime="2026-10-14T08:00:00+07:00">`.
- AC-8: Paginasi: 10 post terbit → halaman 1 memuat 9 judul terbaru, `?page=2` memuat judul ke-10 saja; tautan halaman berikutnya mempertahankan filter (`?jenis=berita&page=2` saat filter jenis aktif).
- AC-9: Filter: `?jenis=kegiatan` hanya B dan `h1` = `pondok.portal.posts.titles.kegiatan`; `?kategori=tahfidz` (kategori post "Tahfidz") hanya post kategori itu; `?jenjang=sltp` hanya post `unit_id` SLTP (post umum dan SD tidak tampil); kombinasi `?jenis=berita&jenjang=sltp` menerapkan keduanya. Chip filter aktif memuat `aria-current="true"`.
- AC-10: Kartu post tanpa `excerpt` menampilkan ringkasan teks dari body (≤ 160 karakter + `…`, tanpa tag HTML); kartu dengan `excerpt` menampilkan excerpt. Kartu tidak memuat nama penulis (`users.name` penulis tidak muncul di daftar maupun detail).
- AC-11: Detail post menampilkan judul `h1`, badge jenis berlabel `pondok.post.types.*`, tanggal, kategori bertaut `/berita?kategori=…`, nama jenjang bila ada, sampul `<img src="{coverUrl()}" alt="{judul}"` bila ada, dan body di dalam elemen berkelas `portal-prose isolate overflow-hidden` (pengurungan `contain: paint` dijaga AC-32).
- AC-12: Escape & sanitasi ulang: post dibuat langsung di DB dengan `title` `<b>Judul</b>`, `excerpt` `<i>x</i>`, dan `body` `<p>Halo</p><script>alert(1)</script><img src="x" onerror="alert(1)">` → detail memuat `&lt;b&gt;Judul&lt;/b&gt;` dan `<p>Halo</p>`, serta tidak memuat `<script>alert(1)`, `onerror`, `<b>Judul</b>`. `Setting school.name` `<script>x</script>` → `<title>` dan `og:site_name` memuat versi ter-escape, tidak ada `<script>x</script>` mentah di respons beranda.
- AC-13: Post dengan `youtube_url` `https://www.youtube.com/watch?v=dQw4w9WgXcQ` → detail memuat `<iframe` dengan `src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"`, `loading="lazy"`, `title=` (tidak kosong), `referrerpolicy="strict-origin-when-cross-origin"`, dan tautan `https://www.youtube.com/watch?v=dQw4w9WgXcQ`. Tidak ada iframe ke `www.youtube.com/embed`.

### Beranda

- AC-14: PSB: `portalPsbOpen()` → beranda memuat `pondok.portal.home.psb_open_badge` dan tautan `route('psb.register')` berlabel `pondok.portal.home.psb_cta`. Modul PSB mati → keduanya tidak ada dan header tidak memuat `pondok.portal.nav.register`. Modul aktif tetapi gelombang ditutup (`is_open` false) atau penuh (kuota tercapai, sesuai `openForRegistration` F010) → badge dan CTA beranda tidak ada, tombol "Daftar" header tetap ada.
- AC-15: Kabar terbaru: 7 post terbit berita/kegiatan + 1 prestasi → seksi kabar memuat 6 judul berita/kegiatan terbaru (bukan yang ketujuh dan bukan prestasi); seksi prestasi memuat judul post prestasi; draf/terjadwal tidak muncul di seksi mana pun.
- AC-16: Video unggulan: V1 featured, V2 featured, V3 tidak → beranda memuat embed V1 dan V2, tidak memuat `youtube_id` V3. Galeri terbaru: album berfoto tampil, album tanpa foto tidak.
- AC-17: Cuplikan profil: body halaman `profil` `<p>Pondok kami berdiri sejak 1990.</p>` → beranda memuat "Pondok kami berdiri sejak 1990." dan tautan `route('portal.pages.show', 'profil')`; body kosong → seksi profil tidak ada. Tanpa post, video, album, dan profil → beranda tetap 200, hanya identitas + footer, tanpa judul seksi kosong (`latest_news`, `featured_videos`, `latest_albums` tidak muncul).
- AC-18: Query beranda saat modul konten mati tidak menyentuh tabel konten: dengan `DB::listen`, tidak ada query ke `posts`, `albums`, `videos`, `pages`.

### Galeri, video, halaman statis

- AC-19: `/galeri`: album berfoto A1 (kategori Fasilitas, 3 foto) dan A2 (Kegiatan) tampil dengan judul, kategori, `trans_choice` jumlah foto ("3 foto"), dan `<img` sampul `loading="lazy"`; album tanpa foto tidak tampil; `?kategori=fasilitas` hanya A1. Tanpa album berfoto → empty state `pondok.portal.albums.empty`.
- AC-20: `/galeri/{A1}`: memuat `h1` judul, deskripsi ter-escape, 3 `<img` dengan `src` dari `Photo::url()` urut `sort_order`, setiap `<img` punya `alt` tidak kosong (caption atau `photo_alt`), dan `<figcaption>` untuk foto bercaption. Album 30 foto → halaman 1 memuat 24 foto, `?page=2` memuat 6.
- AC-21: `/video`: video urut `Video::ordered()`; setiap video dirender sebagai iframe nocookie dengan `title` = judul video; deskripsi ter-escape. Tanpa video → empty state `pondok.portal.videos.empty`. 10 video → halaman 1 memuat 9.
- AC-22: `/profil` dengan body tersimpan `<h2>Sejarah</h2><p>Isi</p>` → memuat `h1` "Profil" dan `<h2>Sejarah</h2>`; body DB berisi `<script>` → tidak dirender (sanitasi ulang). Body kosong → `pondok.portal.pages.empty`. `/kontak` dengan body kosong tetapi `school.phone` `081234567890`, `school.whatsapp` `+6281234567890`, `school.email` terisi → tidak ada empty state; memuat "0812-3456-7890", `href="tel:081234567890"`, `href="https://wa.me/6281234567890"`, `href="mailto:…"`. `/beranda` (bukan slug statis) → 404.

### SEO

- AC-23: Meta beranda: `<title>` "Ma'had Al-Khair — Mencetak generasi Qur'ani" (versi ter-escape HTML), `meta name="description"` = tagline, `link rel="canonical"` = `url('/')`, `og:site_name`, `og:title`, `og:type` `website`, `og:locale` `id_ID`; logo di-set → `og:image` = `logo_url` absolut; tanpa logo → tidak ada tag `og:image`. Tanpa tagline → description = `pondok.portal.meta.default_description` dengan nama sekolah.
- AC-24: Meta detail post: `<title>` "{judul} — Ma'had Al-Khair", description = excerpt (atau ringkasan body ≤ 160 karakter bila excerpt NULL), `og:type` `article`, `og:image` = `coverUrl()` bila ada sampul, canonical = `route('portal.posts.show', slug)` walau diminta dengan query `?utm_source=x`. Dengan `config(['app.url' => 'https://alkhair.sch.id'])` dan permintaan ber-header `Host: evil.test`, `canonical` dan `og:url` diawali `https://alkhair.sch.id/` dan respons tidak memuat `evil.test`. `og:image`: test menyetel `config(['filesystems.disks.public.url' => 'https://alkhair.sch.id/storage'])` **sebelum** `Storage::fake('public')`, lalu assert `og:image` = `https://alkhair.sch.id/storage/{cover_path}` (tidak bergantung `forceRootUrl`).
- AC-24a (paginasi & Host): `config(['app.url' => 'https://alkhair.sch.id'])`, 10 post berita terbit, album 30 foto. `GET /berita?jenis=berita` dan `GET /galeri/{slug}` dengan header `Host: evil.test` → tautan halaman berikutnya `https://alkhair.sch.id/berita?jenis=berita&page=2` (resp. `https://alkhair.sch.id/galeri/{slug}?page=2`) dan seluruh respons tidak memuat `evil.test`. `config(['app.url' => 'alkhair.sch.id'])` (tanpa skema) → `GET /` dan `GET /berita` 200 (bukan 500), canonical diawali `http://alkhair.sch.id/` (skema permintaan test).
- AC-25: `GET /sitemap.xml` → 200, `Content-Type` diawali `application/xml`, dapat di-parse `simplexml_load_string`, memuat `loc` beranda, `/berita`, `/galeri`, `/video`, post terbit A (dengan `lastmod` W3C), album berfoto, `/profil` bila body terisi; tidak memuat draf, terjadwal, soft-deleted, album tanpa foto, halaman statis kosong. Modul konten mati → hanya beranda (+ `/psb` bila PSB aktif dan route ada). Dengan `Host: evil.test`, semua `loc` tetap diawali `config('app.url')` dan respons tidak memuat `evil.test`.
- AC-26: `GET /robots.txt` → 200, `Content-Type` diawali `text/plain`, memuat `Disallow: /admin`, `Disallow: /psb/status`, dan `Sitemap: {config('app.url')}/sitemap.xml` (juga dengan header `Host` lain). Berkas `public/robots.txt` tidak ada.

### Cache HTTP, stateless, header keamanan

- AC-27: Respons 200 portal (`/`, `/berita`, detail post, `/sitemap.xml`) memuat `Cache-Control` berisi `public` dan `max-age=300`, serta header `ETag`. Permintaan ulang dengan `If-None-Match` = ETag tersebut → 304 tanpa body. Respons 404 portal tidak memuat `public` di `Cache-Control`. `config(['pondok.portal.cache_max_age' => 0])` → respons 200 tanpa `public` dan tanpa `ETag`.
- AC-28: Stateless: respons portal untuk `GET` 200 (`/`, `/berita`, detail post, `/sitemap.xml`), `HEAD /` dan `HEAD /berita`, serta 404 dari route portal (`/berita/tidak-ada`, `/berita?jenis=artikel`) tidak memuat header `Set-Cookie` sama sekali (tidak ada cookie sesi maupun `XSRF-TOKEN`), juga ketika permintaan membawa cookie sesi user admin yang login. HTML 200 portal (`/`, `/berita`, detail post) tidak memuat `csrf-token`, `data-csrf`, `XSRF`, `_token`, maupun `livewire` (tidak peka huruf besar-kecil). Sebaliknya `GET /psb` (F010, modul PSB aktif) tetap 200, mengirim `Set-Cookie` sesi, tidak memuat `Cache-Control: public`, dan memuat aset Livewire (`livewire` pada tag `<script`, mis. `livewire.js`/`livewire.min.js`, dan atribut `wire:`) — regresi auto-inject.
- AC-29: Respons portal memuat `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN`, dan `Content-Security-Policy` yang memuat `frame-src https://www.youtube-nocookie.com`, `script-src 'self'`, `object-src 'none'`, `frame-ancestors 'self'`, dan tidak memuat `unsafe-eval`. `config(['pondok.portal.csp_enabled' => false])` → tanpa header CSP (header lain tetap). `GET /admin/login` dan `GET /psb` tidak memuat CSP portal.

### Aksesibilitas, bahasa, desain, privasi

- AC-30: Setiap halaman portal 200 (beranda, daftar & detail berita, galeri & album, video, ketiga halaman statis) dan halaman 404: tepat satu `<h1`; `<html lang="id"`; `meta name="viewport" content="width=device-width, initial-scale=1"`; skip link `href="#konten"` dan `<main id="konten"`; setiap `<nav` punya `aria-label` dan nilai `aria-label` antar-`<nav>` pada satu halaman **unik**; `<details` menu mobile ada; setiap `<img` punya atribut `alt`; setiap `<iframe` punya `title` tidak kosong; tautan navigasi halaman aktif memuat `aria-current="page"`.
- AC-31: Halaman-halaman di AC-30 tidak memuat kunci lang mentah (`pondok.`) dan tidak memuat teks bahasa Inggris bawaan paginasi ("Previous", "Next", "Showing").
- AC-32: Guard statis: tidak ada berkas di `resources/views/portal/**`, `resources/views/components/portal/**`, `resources/views/components/ui/empty-state.blade.php`, `resources/views/layouts/public.blade.php`, `resources/views/errors/404.blade.php` yang memuat `dark:`, kelas warna mentah (`/\b(?:bg|text|border|ring|from|to|fill|stroke)-(?:red|green|emerald|amber|sky|zinc|slate|blue|yellow|indigo|neutral|stone)-\d{2,3}\b/`), hex warna (`/#[0-9a-fA-F]{3,8}\b/` di dalam atribut `class`/`style`), atau `!important`. Hanya satu `{!! !!}` untuk body di masing-masing view `posts/show` dan `pages/show` (selain deklarasi XML sitemap). `resources/css/app.css` memuat blok aturan `.portal-prose` yang berisi `contain: paint` (regex `/\.portal-prose\s*\{[^}]*contain:\s*paint/`). Layout publik tidak memuat `@livewireStyles`, `@livewireScripts`, `csrf_token`, `csrf-token`, atau `@csrf`.
- AC-33: Privasi: dengan santri "Zainab Rahmawati" (NIS terisi), pendaftar "Fathimah Azzahra" bernomor `PSB-2027-0001`, dan user penulis "Admin Konten Satu" sebagai `author` post terbit → `/`, `/berita`, detail post, `/sitemap.xml` tidak memuat "Zainab Rahmawati", NIS-nya, "Fathimah Azzahra", "PSB-2027-0001", maupun "Admin Konten Satu". Post dengan `author_id` NULL tetap 200.
- AC-34: `FormatPhoneForDisplay`: `081234567890` → `0812-3456-7890`; `+6281234567890` → `+62 812-3456-7890`; `0215551234` → `0215-5512-34`; NULL → NULL. `whatsappUrl('081234567890')` → `https://wa.me/6281234567890`; `whatsappUrl('+6281234567890')` → `https://wa.me/6281234567890`. `SummarizeHtml('<p>A&amp;B</p>  <p>C</p>', 160)` → `A&B C`; `SummarizeHtml('<p>Halo</p><p>Dunia</p>')` → `Halo Dunia`; `SummarizeHtml('Satu<br>Dua')` → `Satu Dua`; `SummarizeHtml('<ul><li>Satu</li><li>Dua</li></ul>')` → `Satu Dua`; `SummarizeHtml('Halo<strong>,</strong> dunia')` → `Halo, dunia`; `SummarizeHtml('Ba<b>ca</b>')` → `Baca`; `SummarizeHtml('<p>&nbsp;</p>')` → `''`; teks 200 huruf Arab → 160 karakter + `…` tanpa karakter terpotong (valid UTF-8).
- AC-35: 404 global: `GET /tidak-ada-halaman` (tanpa route) dan `GET /psb/tidak-ada` (modul PSB aktif) → 404 (bukan 500), memuat `pondok.portal.errors.not_found_title` (terjemahan) dan `<html lang="id"`, tanpa `aria-current="page"`, tanpa teks exception. Respons ini tidak wajib memuat header keamanan portal (header portal hanya untuk route portal).

## Rencana test

| AC | File | Nama test (usulan) |
|---|---|---|
| 1–6, 6a, 35 | `tests/Feature/Http/Portal/PortalRoutingTest.php` | `AC-1: renders the home page for guests`, `AC-2: hides content routes with 404 when konten is disabled` (dataset URL), `AC-3: keeps 403 for module middleware without status`, `AC-4: returns 404 for unpublished posts` (dataset), `AC-5: renders a safe 404 page`, `AC-6: rejects invalid filters with 404` (dataset, termasuk nilai non-skalar dan `page` tidak valid), `AC-6a: rejects invalid gallery and video queries with 404` (dataset), `AC-35: renders the global 404 page outside portal routes` |
| 7–13 | `tests/Feature/Http/Portal/PostPagesTest.php` | `AC-7: lists only published posts newest first`, `AC-8: paginates and keeps filters`, `AC-9: filters by type, category and unit`, `AC-10: summarizes posts without author`, `AC-11: shows post detail`, `AC-12: escapes text and re-sanitizes body`, `AC-13: embeds youtube with nocookie` |
| 14–18 | `tests/Feature/Http/Portal/HomePageTest.php` | `AC-14: shows the PSB call to action only when registration is open` (dataset), `AC-15: shows latest news and achievements`, `AC-16: shows featured videos and albums with photos`, `AC-17: shows profile summary and hides empty sections`, `AC-18: skips content queries when konten is disabled` |
| 19–22 | `tests/Feature/Http/Portal/GalleryVideoPageTest.php` | `AC-19`, `AC-20`, `AC-21`, `AC-22: renders static pages and contact info` |
| 23–26 | `tests/Feature/Http/Portal/SeoTest.php` | `AC-23: sets home meta tags`, `AC-24: sets article meta tags`, `AC-24a: builds pagination and canonical urls from APP_URL`, `AC-25: builds the sitemap`, `AC-26: serves robots.txt` |
| 27–29 | `tests/Feature/Http/Portal/PortalHeadersTest.php` | `AC-27: sets public cache headers and etag`, `AC-28: keeps portal stateless`, `AC-29: sends security headers and csp` |
| 30–32 | `tests/Feature/Http/Portal/PortalAccessibilityTest.php` | `AC-30: renders accessible markup` (dataset halaman), `AC-31: has no raw translation keys`, `AC-32: uses design tokens only in portal views` |
| 33 | `tests/Feature/Http/Portal/PortalPrivacyTest.php` | `AC-33: does not expose personal data` |
| 34 | `tests/Feature/Actions/Konten/PortalHelpersTest.php` | `AC-34: formats phone numbers` (dataset), `AC-34: summarizes html` |
| — | `tests/Feature/ExampleTest.php` | disesuaikan red-tester: panggil `withoutVite()` dan seed setting minimal agar `GET /` tetap 200 (bukan menghapus test). |

## Urutan implementasi

0. Gerbang: F016 dan F010 sudah terimplementasi (model/Action F016, layout + `x-ui.*` + route `psb.*` + `openForRegistration` F010). Bila belum, berhenti dan laporkan.
1. `config/pondok.php` blok `portal`; parameter status `EnsureModuleIsEnabled`; trait `ReadsPortalQuery`. Test: AC-3.
2. Helper `SummarizeHtml`, `FormatPhoneForDisplay`, `Video::embedUrlFor`. Test: AC-34.
3. Middleware `ForcePortalRootUrl`, `SetPortalSecurityHeaders`, dan `SetPortalCacheHeaders`; grup route portal stateless (pengecualian `PreventRequestForgery` dkk.) dengan `ShowHome` sederhana; hapus route/view `welcome`; hapus `@livewireStyles/@livewireScripts`/token CSRF dari layout bila ada; perbarui `ExampleTest` (red-tester). Test: AC-1, AC-27, AC-28, AC-29, bagian `APP_URL` tanpa skema AC-24a.
4. Perluas layout publik (head SEO, header/nav `<details>`, footer kontak, skip link) + `x-ui.empty-state` + view 404. Test: AC-5, AC-23, AC-30 (beranda & 404), AC-35.
5. `GetPortalHomeData` + view beranda + komponen `x-portal.post-card`, `album-card`, `video-embed`. Test: AC-14–AC-18.
6. `ListPublicPosts`, `FindPublicPost` + view daftar/detail + `filter-chips` + paginasi bertoken + `.portal-prose` (dengan `contain: paint`) di `app.css`. Test: AC-4, AC-6–AC-13, AC-24.
7. `ListPublicAlbums`, `ShowPublicAlbum`, `ListPublicVideos`, `ShowPublicStaticPage` + view. Test: AC-2, AC-6a, AC-19–AC-22, AC-24a (paginasi).
8. `BuildSitemap`, `ShowSitemap`, `ShowRobots`; hapus `public/robots.txt`. Test: AC-25, AC-26.
9. `lang/id/pondok.php` lengkap, ikon §3 design guide, privasi & guard desain. Test: AC-31, AC-32, AC-33, sisa AC-30.
10. Alur anti-ui-slop + checklist §0: `npm run build` sekali (minta izin user; tanpa dev server), cek `https://pondok.test` di desktop dan 360px (beranda, daftar, detail, galeri, video, kontak, 404). **Dengan CSP aktif** (build, bukan Vite hot) buka beranda dan detail post yang memuat gambar eksternal https + embed YouTube, pastikan konsol browser tanpa pelanggaran CSP (font, CSS, gambar, iframe). Bila ada aset yang di-inline Vite sebagai `data:` (font/gambar kecil) dan diblokir, tambahkan `data:` hanya pada direktif yang perlu (`font-src`/`img-src`) dan catat di laporan serta Riwayat revisi. Uji juga body dengan `style="position:fixed;inset:0"` tetap terkurung di wadah. Bila tidak bisa dirender, laporkan "belum dicek visual/CSP belum dicek". Pint.

## Risiko & pertanyaan terbuka

Setiap pertanyaan punya default yang dipakai bila user tidak memutuskan lain.

1. **Dependensi F010.** `depends_on` F017 kini memuat F016 dan F010. F017 baru mulai RED setelah keduanya terimplementasi (langkah 0).
2. **Modul konten mati → 404 atau 403?** *Default:* 404 untuk route portal konten (pengunjung umum tidak perlu tahu ada modul yang dimatikan), lewat parameter `EnsureModuleIsEnabled:konten,404`. Beranda tetap hidup dengan identitas + PSB + kontak.
3. **Cache HTTP.** *Default:* `Cache-Control: public, max-age=300` + ETag/304 untuk respons 200, hanya pada grup portal yang **stateless** (tanpa sesi/cookie) supaya aman bila ada proksi/CDN. Akibat: perubahan konten (terbit, ubah, tarik) bisa terlambat terlihat hingga 5 menit di browser yang sudah memuat halaman; dapat diatur `PORTAL_CACHE_MAX_AGE` (0 = mati). Tanpa cache sisi server.
4. **Prestasi publik.** *Keputusan orkestrator:* "berita prestasi mengambil data dari modul Prestasi" (PRD 3.1) dipenuhi oleh post bertipe `prestasi` yang dibuat F015 (`PublishAchievementAsPost`); portal menampilkannya di `/berita?jenis=prestasi` + seksi beranda, tidak terikat saklar modul `prestasi`, tanpa membaca tabel `achievements` (rekap membawa nama santri, UU PDP). Halaman rekap prestasi publik **ditunda tanpa entri baru**.
5. **Filter jenjang.** *Default:* persis jenjang terpilih; post "umum" (`unit_id` NULL) tidak ikut. Alternatif: jenjang + umum.
6. **CSP.** *Default:* kebijakan di "Route & middleware", aktif bawaan, dapat dimatikan `PORTAL_CSP=false`, otomatis tidak dikirim saat Vite dev server berjalan. `style-src 'unsafe-inline'` diterima (font inline + atribut `style` yang diizinkan F016). `img-src` mengizinkan http/https eksternal karena body F016 boleh memuatnya. Hanya untuk portal, bukan `/psb` (Livewire/Alpine) dan `/admin` (Filament).
7. **Sesi dihapus dari grup portal.** Risiko: kode di layout bersama (F010) yang memanggil `csrf_token()`/`session()`/`$errors` atau `@livewireScripts` akan error di portal atau membocorkan token ke HTML yang di-cache publik. *Default:* layout bebas markup Livewire/CSRF, halaman Livewire F010 memakai auto-inject; dijaga AC-28 (portal bebas token, `/psb` tetap memuat aset Livewire) dan AC-32 (guard statis layout). Bila suatu saat `inject_assets` dimatikan, layout harus dipecah dua (portal vs Livewire).
7a. **Gaya inline di body.** Sanitizer F016 mengizinkan atribut `style`. *Keputusan orkestrator:* wadah body memakai `contain: paint` + `isolation: isolate` sehingga `position:fixed` terkurung di dalam wadah; konfigurasi sanitizer F016 tidak diubah. Sisa risiko: gaya tetap bisa mengubah tampilan **di dalam** wadah (penulis adalah staf tepercaya).
7b. **Header `Host`.** URL absolut buatan aplikasi di respons yang di-cache dibangun dari `APP_URL` (`ForcePortalRootUrl` + `withPath` paginator), bukan dari header `Host`; `og:image`/URL gambar mengikuti `filesystems.disks.public.url`. `APP_URL` yang salah atau di-cache sebelum diisi berarti canonical/sitemap/gambar salah (panduan F019).
8. **Album tanpa foto.** *Default:* disembunyikan dari daftar, beranda, dan sitemap; URL-nya 404.
9. **Halaman statis kosong.** *Default:* 200 dengan empty state (kecuali `kontak` yang punya data kontak), tidak masuk sitemap.
10. **Penulis post.** *Default:* tidak pernah ditampilkan (privasi staf, dan `author_id` bisa NULL).
11. **Video di daftar.** *Default:* iframe `loading="lazy"` per video, 9 per halaman; tanpa thumbnail dari `i.ytimg.com` (menghindari aset pihak ketiga tambahan). Bila terlalu berat di HP kelas bawah, opsi lanjutan "klik untuk memuat" butuh JS (di luar lingkup).
12. **Foto galeri.** *Default:* tanpa lightbox; tiap foto bertaut ke berkas aslinya. Ukuran berkas mengikuti batas F016 (≤ 2 MB, resize browser 1600 px).
13. **`storage:link` dan `public/build`.** Gambar portal bergantung pada symlink `public/storage` dan `APP_URL`; halaman portal bergantung pada `public/build` (manifest Vite + font). Keduanya dicatat untuk panduan rilis F019; test memakai `withoutVite()` dan `Storage::fake`.
14. **`public/robots.txt` dihapus.** Bila server web melayani berkas statis lebih dulu, route tidak pernah terpanggil; berkas dihapus dalam commit yang sama.
15. **ExampleTest bawaan.** `GET /` kini merender layout yang memakai `@vite`; tanpa `public/build` test itu gagal 500. *Default:* red-tester menyesuaikan (`withoutVite()`), tidak menghapus.
16. **Konflik file bersama.** `lang/id/pondok.php`, `tests/Pest.php`, `resources/css/app.css`, `routes/web.php`, `config/pondok.php`, `bootstrap/app.php` juga diubah F010/F016. Implementer menambah blok, tidak menimpa.

## Riwayat revisi

- 2026-10-15 — DRAFT pertama.
- 2026-10-16 — Revisi 1 (Review #1 CHANGES_REQUESTED + keputusan orkestrator): #1 grup stateless mengecualikan `PreventRequestForgery` (plus `ValidateCsrfToken`) sesuai isi grup `web` Laravel 13; AC-28 menguji `HEAD` dan 404 portal tanpa `Set-Cookie`. #2 layout bebas `@livewireStyles/@livewireScripts`/CSRF (auto-inject Livewire untuk `/psb`); AC-28 menguji HTML portal bebas `csrf-token`/`data-csrf`/`XSRF`/`livewire` dan `/psb` tetap memuat aset Livewire; guard statis layout di AC-32. #3 (keputusan orkestrator) `.portal-prose` memakai `contain: paint` + `isolation: isolate`, klaim lama dikoreksi, assert statis di AC-32, Risiko #7a. #4 (keputusan orkestrator) nilai query non-skalar → 404; `page` harus `^[1-9][0-9]{0,5}$`, `page<1`/non-numerik/array → 404 (bagian "Pembacaan query string", AC-6, AC-6a). #5 URL absolut dari `APP_URL` lewat `forceRootUrl`, assert `Host` palsu di AC-24/25/26. #6 `SummarizeHtml` memisahkan blok dengan spasi, contoh baru di AC-34. #7 normalisasi jenjang di PHP (`Str::upper` + koleksi unit). #8 langkah 10 memeriksa pelanggaran CSP dan `data:`. #9 AC-35 404 global; header portal hanya untuk route portal. #10 dua `<nav>` berlabel unik (desktop `hidden md:flex`, mobile `<details class="md:hidden">`), assert di AC-30, kunci `portal.nav.mobile_label`. #11 kasus galeri/video AC-6 dipindah ke AC-6a (langkah 7). #12 (keputusan orkestrator) berita prestasi PRD 3.1 dipenuhi post `prestasi` F015, rekap publik ditunda tanpa entri; sitemap/robots/cache/CSP diterima sebagai bagian F017 (Ruang lingkup, Risiko #4). #13 klausa `&` di AC-25 dihapus, diganti assert `Host`. Risiko #1 diperbarui: `depends_on` sudah memuat F010. Jumlah AC: 36 (tambah AC-6a, AC-35). Status kembali DRAFT.
- 2026-10-16 — Revisi 2 (Review #2 CHANGES_REQUESTED + keputusan orkestrator): #1 semua paginator portal `->withPath(route(...))`, AC-24a menguji tautan paginasi `/berita?jenis=berita` dan `/galeri/{slug}` dengan `Host: evil.test`. #2 assert Host palsu di AC-24 dibatasi ke `canonical`/`og:url`; `og:image` dites dengan `filesystems.disks.public.url` disetel sebelum `Storage::fake`; batas `og:image` dicatat. #3 `SummarizeHtml` hanya menyisipkan spasi di sekitar tag blok (`p`, `div`, `br`, `li`, `h1`–`h6`, `tr`, `td`, `th`, `blockquote`), contoh `Halo<strong>,</strong>` → "Halo," di AC-34. #4 `?page=` kosong = halaman 1 (AC-6). #5 `forceRootUrl` dipisah ke middleware `ForcePortalRootUrl` dengan fallback skema permintaan bila `APP_URL` tanpa skema (tanpa `forceScheme`), AC-24a. Jumlah AC: 37 (tambah AC-24a). Status kembali DRAFT.

## Review #1 — 2026-10-15

Verdict: CHANGES_REQUESTED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | Route & middleware (grup stateless), AC-28 | Daftar `withoutMiddleware` memuat `ValidateCsrfToken`, padahal di Laravel 13 grup `web` memakai `Illuminate\Foundation\Http\Middleware\PreventRequestForgery` (`vendor/.../Configuration/Middleware.php` baris ~490); `ValidateCsrfToken` hanya subclass `@deprecated`. Pengecualian dicocokkan per nama kelas, jadi `PreventRequestForgery` tetap berjalan: untuk GET ia menambahkan cookie `XSRF-TOKEN` lewat `addCookieToResponse` (butuh sesi, bisa error atau menghasilkan `Set-Cookie`). Rencana akan gagal di AC-28 atau memaksa implementer mengakali. | Ganti dengan `PreventRequestForgery::class` (boleh sekaligus `ValidateCsrfToken::class`). Tulis bahwa daftar dicocokkan dengan isi grup `web` Laravel 13: `EncryptCookies`, `AddQueuedCookiesToResponse`, `StartSession`, `ShareErrorsFromSession`, `PreventRequestForgery`. Tambahkan ke AC-28: tidak ada `Set-Cookie` pada `HEAD` dan pada 404 dari route portal. |
| 2 | major | Layout publik bersama F010, AC-28 | Layout dipakai bersama `/psb` (sesi, Livewire) dan portal yang di-cache `public` 5 menit. F010 menulis layout dengan `@livewireStyles/@livewireScripts "bila diperlukan"` (F010 plan baris 245); rencana ini hanya melarang `csrf_token()`/`session()` secara prosa. Tidak ada AC yang memastikan HTML portal bebas token CSRF atau markup bergantung sesi, padahal itu risiko cache publik yang harus ditangkap. Sebaliknya, bila F017 menghapus `@livewireScripts`, tidak ada test yang menjamin `/psb` masih memuat skrip Livewire. | Tulis eksplisit: layout tidak memuat `@livewireStyles/@livewireScripts`, `<meta name="csrf-token">`, atau `@csrf`; Livewire F010 mengandalkan auto-inject (atau layout dibagi dua). Tambahkan ke AC-28: HTML 200 portal (`/`, `/berita`, detail) tidak memuat `csrf-token`, `data-csrf`, `XSRF`, `livewire`, dan `GET /psb` tetap memuat aset Livewire. |
| 3 | major | Detail post & halaman statis (wadah `portal-prose isolate overflow-hidden`), AC-11/AC-22/AC-32 | Mitigasi `style` dari F016 secara teknis tidak bekerja: `isolate` dan `overflow-hidden` tidak mengurung elemen `position: fixed` (kotak penampung elemen fixed hanya dibentuk oleh `transform`, `filter`, `contain: paint`, dsb.). Body masih boleh memuat `style="position:fixed;inset:0;..."` (AC-13c F016), sehingga overlay/tautan palsu bisa menutupi seluruh halaman termasuk header dan footer. Klaim "membatasi" di rencana dan kontrak F016 menyesatkan. | Beri wadah `contain: paint` (di `.portal-prose` pada `app.css`, tetap `isolate`), atau buang `position` lewat konfigurasi sanitizer (butuh keputusan karena F016 sudah diputuskan). Koreksi kalimat klaimnya. Tambahkan assert statis AC-32 bahwa aturan `.portal-prose` memuat `contain: paint`. |
| 4 | major | `ListPublicPosts`/controller, AC-6 | `$filters` bertipe string "nilai mentah query string", tetapi `/berita?jenis[]=a`, `?kategori[x]=1`, `?jenjang[]=sltp` menghasilkan array pada `$request->query()`. Tanpa penanganan eksplisit, `in_array`/`where`/`strtoupper` melempar `TypeError` atau `QueryException` sehingga endpoint publik 500. `?page=abc`/`?page[]=1` hanya disebut "dianggap halaman 1" tanpa AC. | Tetapkan: nilai filter bukan string skalar dinyatakan tidak valid (404, konsisten dengan AC-6) atau diabaikan; tulis di Action. `page` diambil dengan `$request->integer('page', 1)` minimum 1. Tambahkan ke AC-6: `?jenis[]=berita`, `?kategori[]=a`, `?jenjang[]=sltp` → 404 (bukan 500); `?page=abc`, `?page=0`, `?page[]=2` → 200 halaman 1. |
| 5 | minor | `Host` header dan cache publik | `canonical` (`url()->current()`), `og:url`, `route()` di sitemap/robots memakai host permintaan, lalu respons di-cache `public`. Host palsu pada shared hosting tanpa vhost eksklusif bisa menanamkan URL asing ke respons tersimpan. Tidak ada `TrustHosts`. | Bangun URL kanonik, `og:*`, sitemap, dan robots dari `config('app.url')` (mis. `URL::forceRootUrl` pada grup portal), atau minimal catat di panduan F019. Satu assert di AC-24/AC-25: `Host` lain tetap menghasilkan URL dari `APP_URL`. |
| 6 | minor | `SummarizeHtml`, AC-34 | `strip_tags` menempelkan teks antar-blok: `<p>Halo</p><p>Dunia</p>` menjadi "HaloDunia" (ringkasan kartu dan meta description rusak). AC-34 memakai contoh dengan spasi antar tag sehingga lolos. | Sisipkan spasi sebelum `strip_tags`, lalu rapikan spasi; tambahkan kasus `<p>Halo</p><p>Dunia</p>` → "Halo Dunia" dan `<br>`/`<li>` di AC-34. |
| 7 | minor | Filter jenjang (`ListPublicPosts` langkah 3) | "Tidak peka huruf besar-kecil" dengan `LOWER(code)`/collation berperilaku beda di MySQL vs Postgres/SQLite. | Normalisasi di PHP: `Str::upper($jenjang)` dibanding `units.code` huruf besar, atau cocokkan lewat koleksi unit. Jangan `LIKE`/`LOWER` spesifik DB. |
| 8 | minor | CSP & langkah 10 | Header CSP baru diuji di browser pada langkah 10, tetapi langkah itu tidak menyebut pemeriksaan pelanggaran CSP. `img-src`/`font-src` tanpa `data:` memblokir aset yang di-inline Vite. Test Pest hanya memeriksa teks header. | Tambahkan ke langkah 10: buka beranda dan detail post (gambar eksternal + embed YouTube) dengan CSP aktif, pastikan konsol tanpa pelanggaran; putuskan soal `data:` bila ada. Laporkan "belum dicek visual" bila tak bisa dijalankan. |
| 9 | minor | 404 global (`errors/404.blade.php`) | View dipakai semua 404 aplikasi, termasuk URL tanpa route (tanpa grup `web`/sesi), `/admin/...`, dan `/psb/...`. `request()->route()` bisa NULL untuk `aria-current`; 404 tanpa route tidak menerima header keamanan portal. | Tambahkan AC kecil: `/tidak-ada-halaman` dan `/psb/tidak-ada` → 404 (bukan 500) berlayout publik; nyatakan header keamanan hanya untuk 404 dari route portal. |
| 10 | minor | Navigasi `<details>` | `<details>` hanya untuk < `md` tetapi tanpa JS `<details>` tertutup menyembunyikan tautan di desktop kecuali ada markup ganda; dua `<nav>` ber-`aria-label` sama membingungkan pembaca layar. AC-30 hanya cek keberadaan `<nav aria-label=`. | Tulis struktur: `<nav>` desktop (`hidden md:flex`) dan `<details>` mobile (`md:hidden`) dengan label berbeda; assert AC-30 bahwa `aria-label` antar `<nav>` unik. |
| 11 | minor | Urutan implementasi, AC-6 | Langkah 6 menyebut "Test: AC-6", tetapi AC-6 memuat `/galeri?kategori=...` dan `/galeri/{slug}` yang baru ada di langkah 7. | Pindahkan baris galeri AC-6 ke langkah 7. |
| 12 | minor | Cakupan vs PRD 3.1 | PRD 3.1: "Berita prestasi mengambil data dari modul Prestasi". Risiko #4 menutupnya lewat post `prestasi` (F015 `PublishAchievementAsPost`); rekap publik `achievements` ditunda dan, karena orkestrator tidak membuat entri baru, kesenjangan itu tidak tercatat di mana pun. Sitemap/robots/cache/CSP juga melebihi `acceptance` entri. | Ubah teks Risiko #4 dan "Tidak termasuk": rekap prestasi publik adalah keputusan orkestrator (ditunda, tanpa entri). Tambahkan kalimat bahwa sitemap/robots/cache/CSP diterima sebagai bagian F017. |
| 13 | minor | AC-25 | "Judul/slug dengan `&` tidak merusak XML": slug dibatasi `[a-z0-9-]`, jadi `&` tak pernah muncul di `loc`; AC tidak menguji hal nyata. | Ganti dengan assert escape XML terhadap `APP_URL` berkarakter khusus, atau hapus klausa itu. |

Ringkasan: 0 blocker, 4 major, 9 minor. Sudah benar: `EnsureModuleIsEnabled` dengan status default `'403'` tidak mengubah satu-satunya pemakaian lama (`AdminPanelProvider` memakai `:keuangan`); `Setting::moduleEnabled` default false (seeder F016 harus menyetel `modules.konten.enabled`); 404 dari middleware tetap melewati middleware luar (header keamanan); `depends_on` F017 sudah memuat F010; tidak ada tabel privat yang dibaca; `{!! !!}` terbatas pada body tersanitasi ulang.

## Review #2 — 2026-10-16

Verdict: CHANGES_REQUESTED

Verifikasi Review #1: semua 13 temuan tertangani di teks (PreventRequestForgery dikecualikan, layout bebas Livewire/CSRF + AC-28 diperluas, `contain: paint` + assert AC-32, query non-skalar dan `page` tidak valid → 404 dengan AC-6/6a, `forceRootUrl`, `SummarizeHtml`, normalisasi jenjang di PHP, langkah 10 memeriksa CSP, AC-35, label `<nav>` unik, AC galeri dipindah). Dua masalah baru muncul dari perbaikan #5.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | "Host header" (`forceRootUrl`), paginasi, AC-24/25/26 | `URL::forceRootUrl` tidak memengaruhi tautan paginasi: `Paginator::currentPathResolver` memakai `request()->url()` (host dari header `Host`). Halaman `/berita`, `/galeri`, `/video`, `/galeri/{slug}` yang di-cache `public` tetap memuat tautan `?page=N` berhost `evil.test`; assert Host palsu hanya ada di detail post, sitemap, dan robots, sehingga celah ini lolos. Pernyataan "URL absolut tidak bergantung header Host" jadi tidak benar. | Wajibkan `->withPath(route('portal.<nama>', ...))` pada semua paginator portal (atau set `Paginator::currentPathResolver` di middleware portal). Tambahkan ke AC-24/25 satu assert: `GET /berita?jenis=berita` dan `/galeri/{slug}` dengan 2 halaman dan `Host: evil.test` → tautan paginasi dan seluruh respons tidak memuat `evil.test`. |
| 2 | major | AC-24 (`og:image` diawali `config('app.url')`) | Tidak bisa dites seperti tertulis: `Storage::url()` disk `public` memakai `filesystems.disks.public.url`, dihitung saat config dimuat dari `APP_URL`, dan `Storage::fake('public')` menyalin config itu. `config(['app.url' => ...])` saat runtime tidak mengubahnya, sementara `forceRootUrl` tidak menyentuh `Storage::url`. Red-tester akan menulis assert yang gagal karena alasan salah dan green-coder tidak punya jalan sah memperbaikinya. | Ubah AC: test menyetel `config(['filesystems.disks.public.url' => 'https://alkhair.sch.id/storage'])` sebelum `Storage::fake('public')` lalu assert `og:image`; atau batasi assert Host palsu ke `canonical` dan `og:url` dan nyatakan `og:image` bergantung pada `filesystems.disks.public.url` (panduan F019: `APP_URL` benar sebelum `config:cache`). |
| 3 | minor | `SummarizeHtml` | `preg_replace('/</', ' <')` juga memecah teks di tengah kata atau sebelum tanda baca: `Halo<strong>,</strong>` → "Halo ,", `Hal<b>o</b>` → "Hal o". | Terima sebagai batasan dan tulis, atau sisipkan spasi hanya sebelum tag blok (`p|div|br|li|h1-6|tr|td|blockquote`) dan tutup-tag blok. Tambah satu kasus di AC-34. |
| 4 | minor | "Pembacaan query string" | `?page=` (string kosong) tidak disebut: `jenis=''` → NULL, tetapi `page=''` tidak cocok regex dan jatuh ke 404 secara implisit; crawler dan formulir sering mengirim parameter kosong. | Tetapkan eksplisit (saran: `page=''` diperlakukan seperti tidak ada → halaman 1) dan tambahkan ke AC-6. |
| 5 | minor | `SetPortalSecurityHeaders` | Satu middleware sekarang mengatur header keamanan sekaligus `forceRootUrl`/`forceScheme` (tanggung jawab ganda), dan `forceScheme` dari `parse_url(app.url)` bernilai NULL bila `APP_URL` tanpa skema. | Pisahkan menjadi `ForcePortalRootUrl` (atau tetap, tetapi tulis fallback: abaikan `forceScheme` bila skema NULL) dan beri AC kecil `APP_URL` tanpa skema tidak 500. |

Ringkasan: 0 blocker, 2 major, 3 minor. Perbaikan kedua major kecil dan tidak mengubah arsitektur; setelah itu rencana layak APPROVED.

## Review #3 — 2026-10-16

Verdict: APPROVED

Verifikasi Review #2: semua lima temuan tertangani. Paginator portal memakai `withPath(route(...))` (posts, albums, album show, videos) dengan AC-24a; assert Host di AC-24 dibatasi ke `canonical`/`og:url` dan `og:image` dites lewat `filesystems.disks.public.url` yang disetel sebelum `Storage::fake`; `SummarizeHtml` hanya memberi spasi di tag blok dengan kasus uji di AC-34; `?page=` kosong = halaman 1 (AC-6); `ForcePortalRootUrl` terpisah dengan fallback skema untuk `APP_URL` tanpa skema. Tidak ada blocker atau major baru.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | `ForcePortalRootUrl` | `URL::forceRootUrl`/`forceScheme` adalah state statis proses. Aman di PHP-FPM/cPanel (satu request per proses) dan di Pest (aplikasi dibuat ulang per test), tetapi bocor bila suatu saat dijalankan di proses panjang (Octane, dilarang proyek ini). | Cukup dicatat; tidak perlu tindakan. Bila red-tester menemukan kebocoran antar-test pada `/psb`, reset di `afterEach`. |
| 2 | minor | `SummarizeHtml` | Tag blok yang tidak terdaftar (`section`, `article`, `dd`, `figcaption`) masih menempelkan teks. Sanitizer Filament jarang meloloskannya, dampak rendah. | Opsional: tambahkan ke daftar tag blok. |
| 3 | minor | AC-24a | Kasus `APP_URL` tanpa skema mengharapkan canonical `http://alkhair.sch.id/`; ini bergantung skema permintaan test (`http`). | Tulis dengan `https://` pada request test agar jelas, bila red-tester menganggapnya ambigu. |

Ringkasan: 0 blocker, 0 major, 3 minor (catatan saja). Rencana siap untuk red-tester setelah F016 dan F010 terimplementasi (langkah 0).

## Adendum implementasi (keputusan user, 16 Okt 2026)

- F017 menggantikan beranda sementara (`home.blade.php`, commit a20ea6f). Elemen pentingnya dipertahankan di portal: tautan "Masuk" ke login panel di header (`pondok.portal.nav.login`, AC-36), tautan cek status PSB di footer bila modul PSB aktif (`pondok.portal.footer.psb_status`), dan tautan verifikasi surat di footer bila modul dokumen aktif (`pondok.portal.footer.verify_letter`). Diuji di `tests/Feature/Http/HomePageTest.php`.
- Varian "Buka panel" untuk user login tidak dibuat karena portal stateless; Filament mengarahkan user yang sudah login dari halaman login ke panel.
- Tautan sosial dari `settings` hanya dirender bila skemanya http/https (temuan VERIFY).
