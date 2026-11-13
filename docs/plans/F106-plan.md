# F106 konten-kait-tema — Kait tema untuk pil, slider, panel menu, kartu, dan halaman dalam

Issue: #144
Status rencana: Direvisi, menunggu verifikasi
PR ditujukan ke `dev` (cabang `feat/f106-kait-tema`), memakai `Refs #144`. Jalur penuh (menyentuh kontrak markup yang dipakai tema pihak ketiga).
Sumber celah: butir 1-6 dan 10 di `pondok-themes-work/CELAH-SISTEM.md` (repo privat, di luar repo ini).

## Ringkasan & tujuan
Menambah kait `data-pt*` yang masih hilang agar CSS tema tidak memakai selektor posisi yang rapuh. Murni aditif: atribut di view yang sudah ada, tanpa Action, migration, skema `theme.json`, uang, atau hak akses. Tema tanpa CSS menghasilkan HTML sama persis (byte) dengan sebelumnya. Kait F105 tidak diubah namanya.

## Ruang lingkup
- Termasuk: kait di bawah, `ThemeComponents::PARTS` + konstanta `SHARED_VARIANTS`, `docs/tema-kontrak-markup.md`, test.
- Tidak termasuk: `nth`/`source` pada `feed_list` (celah 7) dan `url()` pada custom property (celah 8), issue terpisah bila diminta; kait lanjutan di dalam isi seksi preset `blocks` selain tombol; portal wali, form PSB Livewire, error page; varian struktur hero baru; perubahan tampilan.

## Mekanisme
- `ThemeComponents::hooks()`/`active()` apa adanya. View `styled/*` memakai `$pt(...)` dan `$ptActive` (dari `portal/theme/node.blade.php`, diwarisi include).
- **`components/ui/button.blade.php` dan `components/ui/badge.blade.php` TIDAK diubah** (dipakai `errors/layout.blade.php:33-39` dan `errors/404.blade.php:4,6` yang sengaja tanpa database; `hooks()` memanggil `Setting::get`). Pemanggil bertema mengoper atribut, pola `styled/video.blade.php:5`: `:data-pt="$ptActive ? 'button' : null" :data-pt-variant="$ptActive ? $variant : null"`. View non-`styled` (`psb/info`, `videos/index`, `themes/*/blocks`) mendefinisikan `$ptActive ??= ThemeComponents::active();` di `@php` atasnya (hanya route portal ber-tema).
- Pemanggil tombol (hasil grep `x-ui.button`): `styled/hero-center:23`, `hero-split:24`, `cta-band:14`, `cta-card:14`, `themes/default/blocks/hero:25` dan `cta:23`, `themes/centered/blocks/hero:20`, `psb/info:49`. Pil hero: `hero-center:9`, `hero-split:10` (badge preset `blocks` dan badge `psb/info` tak diberi kait).
- Komponen yang hanya hidup di route ber-tema (`post-card`, `album-card`, `partials/pagination`, `filter-chips`) memanggil `hooks()` langsung (`<article{{ hooks }} {{ $attributes }}>`, byte sama bila kosong). `x-portal.breadcrumb` juga dipakai portal wali, jadi kaitnya dioper pemanggil (`:data-pt-part="$ptActive ? 'breadcrumb' : null"`).
- **Kartu video: tidak diekstrak jadi komponen.** Tiga pemakai (`feed_list-cards:9-12`, `feed_list-magazine:13-16`, `videos/index:14-20`) berbeda tag akar (`div` vs `article`), level judul (h3 vs h2), dan deskripsi; ekstraksi butuh tiga prop dan mengubah byte non-CSS. Tiap situs diberi kait langsung.

## Model data
Tanpa migration. `ThemeComponents::SHARED_VARIANTS` = `['card' => ['post','album','video'], 'button' => ['primary','secondary','inverse','inverse-secondary']]`. `PARTS` diperluas per paket.

## Tabel kait baru (24 nama: hero 5, nav 3, kartu 7, halaman 8, tombol 1)
| Komponen | Kait | Elemen pembawa | View |
|---|---|---|---|
| `hero` | `badge` | `x-ui.badge` pil `psbOpen` (kedua varian) | `styled/hero-center`, `hero-split` |
| `hero` | `content` | center: `div.mx-auto.max-w-3xl` (anak `section`); split: `div.min-w-0.space-y-6` | idem |
| `hero` | `hgroup` | `hgroup` | idem |
| `hero` | `slider` / `slide` | `div[role=region]` album / tiap `img` (juga gambar tunggal, tanpa `slider`) | `styled/hero-media` |
| `nav_menu` | `panel` | desktop: `div.absolute` dalam `details` submenu dan Masuk; seluler: `nav` dalam `details[mobile]` | `partials/nav-links`, `partials/nav` |
| `nav_menu` | `group` | desktop: `details.group/menu` (submenu, Masuk); seluler: `div` pembungkus grup | `nav-links` |
| `nav_menu` | `group-label` | seluler: `p` judul grup. Desktop: tanpa kait, pakai `[data-pt-part="group"] > summary` (didokumentasikan) | `nav-links` |
| `card` | akar `post` / `album` | `article` | `components/portal/post-card`, `album-card` |
| `card` | akar `video` | `div.space-y-2` (feed cards, magazine) dan `article.space-y-2` (daftar video) | `styled/feed_list-cards`, `feed_list-magazine`, `portal/videos/index` |
| `card` | `media` | post/album: `img` atau `div` placeholder; video: `x-portal.video-embed` (dioper `:data-pt-part`) | semua pembawa |
| `card` | `meta` | post: `div` badge+tanggal; album: `p` kategori+jumlah foto | post-card, album-card |
| `card` | `badge` | post: `x-ui.badge` kategori (dioper `:data-pt-part`); album: `span` kategori | idem |
| `card` | `date` | post: `time` | post-card |
| `card` | `title` | `h2`/`h3` | semua pembawa |
| `card` | `summary` | post: `p` ringkasan; video: `p` deskripsi, **opsional** (hanya daftar video bila deskripsi ada) | post-card, videos/index |
| `page` | akar (tanpa varian) | pembungkus isi (`div.mx-auto.max-w-*`; `article` di detail berita) | `portal/{posts/index,posts/show,albums/index,albums/show,videos/index,pages/show,psb/info}` |
| `page` | `page-title` | `h1` | ketujuh view |
| `page` | `breadcrumb` | `nav` remah | dioper dari ketujuh view (kecuali `videos/index` yang tak punya remah) |
| `page` | `filters` | `nav` chip filter | `components/portal/filter-chips` |
| `page` | `pagination` | `nav` paginasi | `partials/pagination` |
| `page` | `prose` | `div.portal-prose` | `posts/show`, `pages/show`, `psb/info` |
| `page` | `figure` | `figure` foto album | `albums/show` |
| `page` | `section` | `section` gelombang, syarat, biaya, kontak | `psb/info` |
| `button` | akar, varian = `$variant` | `a`/`button` | dioper pemanggil (lihat Mekanisme) |

Nama bagian halaman dibuat unik: kartu bersarang di `/berita`, `/galeri`, `/video` sehingga `title`/`badge`/`meta` bertabrakan bila dipakai ulang di `page` (opsi A, lihat P5). `PARTS['card']` = media, meta, badge, date, title, summary; `PARTS['page']` = page-title, breadcrumb, filters, pagination, prose, figure, section; `PARTS['button']` = []. Hero: akar `section`; varian `split` memiliki `div` grid tanpa kait (hanya bila ada media) yang anaknya `content` dan `media`. Kait `inner` pada grid itu tidak ditambah (YAGNI): `order` bekerja pada anak, dan `content`/`media` adalah anak langsungnya.

## Action class
Tidak ada. UI: tanpa perubahan tampilan, tanpa teks `lang/id` baru, `design-guide.md` tetap. Tanpa permission, `unit_user`, route, resource, atau widget baru.

## Kriteria penerimaan
Setiap pengecekan bagian memeriksa DOM di dalam akar `data-pt="<komponen>"` (DOMDocument/DomCrawler), bukan `str_contains` global.
- **AC-1** Hero (kedua varian, tema ber-CSS): di dalam akar `hero` ada `badge` (pada pil, saat `psbOpen`), `content`, `hgroup`; `slider` + `slide` per foto untuk album, dan satu `slide` tanpa `slider` untuk gambar tunggal.
- **AC-2** Nav: `panel` pada panel submenu desktop, panel pilihan Masuk desktop, dan `nav` seluler (di dalam `[data-pt-part="mobile"]`); `group` pada tiap submenu/Masuk (desktop dan seluler); `group-label` pada tiap judul grup seluler.
- **AC-3** Kartu: akar `card` bervarian `post`/`album`/`video` di beranda (`feed_list` cards dan magazine, preset `blocks`), `/berita`, `/galeri`, `/video`; bagian sesuai tabel di dalam akar kartu (video: `media`, `title`, `summary` hanya bila ada deskripsi); kartu tanpa sampul/kategori tetap membawa `media` (placeholder) dan tidak mencetak `badge`; `article` detail berita membawa `page`, bukan `card`.
- **AC-4** Halaman dalam: tujuh halaman memuat `data-pt="page"` dan `page-title`; `breadcrumb`, `filters`, `pagination` (hanya > 1 halaman), `prose`, `figure`, `section` ada di halaman yang memuat elemennya, di dalam akar `page`.
- **AC-5** Tombol: tiap `x-ui.button` pada pemanggil bertema (daftar di Mekanisme, termasuk "Daftar sekarang" PSB) membawa `data-pt="button"` dan `data-pt-variant` yang cocok; `button.blade.php` dan `badge.blade.php` tidak berubah.
- **AC-6** Tema tanpa CSS: HTML beranda dan tujuh halaman dalam sama persis dengan fixture yang direkam dari kode SEBELUM perubahan (fase RED, data deterministik, bagian dinamis dinormalkan); tak ada `data-pt` maupun spasi sisa. HTML tema ber-CSS setelah atribut `data-pt*`, `<link>` tema, `data-pt-theme` dibuang sama dengan versi tanpa CSS. Golden `default`/`centered` hijau.
- **AC-7** Kompatibilitas F105: daftar pasangan komponen-bagian F105 (beku di test) tetap ada di `PARTS` dan tetap tercetak pada seed lengkap; tak ada nama diubah.
- **AC-8** Sinkron kode-dokumen: test mengurai `docs/tema-kontrak-markup.md` dan memastikan tiap komponen di `PARTS`/`SHARED_VARIANTS` punya baris tabel yang memuat semua bagian dan varian miliknya (dalam backtick), tanpa nama yang tak ada di `PARTS`.
- **AC-9** Dokumen memuat: urutan hero (`center` = `section` flex kolom dengan anak `content`, `media`; `split` = `section` berisi `div` grid dengan `media` ber-`order-first` di layar kecil; `order` didukung, contoh `order:-1`); catatan bahwa selektor kartu/halaman harus berspesifisitas per komponen (`[data-pt="card"] [data-pt-part="title"]`, `[data-pt="page"] [data-pt-part="page-title"]`); pembeda `nav_menu` part `button` (tautan tombol header) vs komponen `button` (`x-ui.button`); `group-label` desktop; baris `feed_list` (`docs/tema-kontrak-markup.md:52`) tidak lagi menyebut kartu `cards` tanpa kait.
- **AC-10** Render `errors.layout` dan `errors/404` dengan tema ber-CSS aktif tidak memicu query `settings` (hitung query saat render, atau koneksi database dimatikan); `ModuleComplianceTest` lulus.

## Rencana test
- **Fase RED, mengubah test F105** di `tests/Feature/Http/Portal/ThemeMarkupContractTest.php`: tes "real components only" (baris 94-103) menerima kunci `SHARED_VARIANTS`; tes putaran render (106-148) dan tes dokumen (150-171) hanya melintasi komponen slot (`array_intersect_key(PARTS, SLOTS)`) agar kartu/halaman/tombol tidak dituntut muncul di beranda; tambah daftar F105 beku (AC-7). Sebelum menulis view, rekam HTML tujuh halaman + beranda dari cabang `dev` ke `tests/Fixtures/portal-html/*.html` (AC-6).
- Baru `tests/Feature/Http/Portal/ThemeMarkupHooksTest.php`: AC-1..AC-6 dan AC-9, AC-10 (helper `portalSeedContent`, `themePkg`, `navSet`, `blkSet`, `kontenVideo`). Dijalankan juga: `PortalThemeGoldenTest`, `ModuleComplianceTest`.

## Urutan implementasi (4 paket green-coder)
Tiap paket menambah `PARTS`/`SHARED_VARIANTS` dan baris dokumen untuk bagiannya sendiri, sehingga `ThemeMarkupContractTest` tidak pernah merah di tengah jalan.
- **Paket 1, hero + media + nav (hijau: ThemeMarkupContractTest, ThemeMarkupHooksTest AC-1, 2, 7, golden):** `PARTS['hero'|'nav_menu']`, baris dokumen, `hero-center`, `hero-split`, `hero-media`, `nav`, `nav-links`; pil hero lewat atribut oper.
- **Paket 2, kartu + tombol (hijau: AC-3, 5, 6 untuk beranda/berita/galeri/video, 10):** `SHARED_VARIANTS`, `PARTS['card'|'button']`, `post-card`, `album-card`, tiga situs kartu video, semua pemanggil tombol.
- **Paket 3, halaman dalam (hijau: AC-4, 6 penuh):** `PARTS['page']`, tujuh view, `filter-chips`, `pagination`, breadcrumb dioper.
- **Paket 4, dokumen + verifikasi (hijau: AC-8, 9, ModuleComplianceTest):** tabel dan catatan di `docs/tema-kontrak-markup.md`; `docs/rilis.md` bila menyebut selektor posisi. Verifikasi manual di laporan akhir: empat tema `pondok-themes-work` (mihrab, cahaya, tegas, kobalt) tetap lolos `ValidateThemePackage` (validator tidak diubah; folder di luar repo, bukan test CI).

## Risiko & pertanyaan terbuka
- Kartu bersama dipakai di banyak tempat: dijaga fixture AC-6 dan pembatasan kait ke route ber-tema; tombol/badge sengaja tak disentuh (AC-5, AC-10).
- Nilai `inverse-secondary` dan `danger`: hanya varian yang dioper pemanggil bertema yang tercetak.
- **P1** Varian tombol `primary|secondary|inverse` di issue; rencana mencetak apa adanya termasuk `inverse-secondary` (reviewer setuju). Body issue perlu diperbarui.
- **P2** `page` tanpa `data-pt-variant` (YAGNI); halaman dibedakan lewat bagian.
- **P3** `group-label` desktop cukup `[data-pt-part="group"] > summary` (didokumentasikan).
- **P4** Kartu video mencakup `/video` (tidak disebut di issue).
- **P5** Bagian halaman memakai nama unik `page-title` (issue menulis `title`) agar tak bertabrakan dengan `card` bersarang; default rencana opsi A. Alternatif: tetap `title` dan tema menyaring lewat spesifisitas. Menyimpang dari kata issue.

## Review #1 (plan-reviewer): CHANGES_REQUESTED, 1 blocker, 4 major, 5 minor
- Blocker 1 (button/badge dipakai halaman error tanpa database): komponen tidak diubah; kait dioper pemanggil bertema; AC-5 dan AC-10 baru.
- Major 2 (kartu video di tiga tempat): ketiganya dicakup; ekstraksi komponen ditolak dengan alasan di Mekanisme.
- Major 3 (nama `title`/`badge`/`meta` bertabrakan): bagian halaman `page-title`; catatan spesifisitas di AC-9; P5.
- Major 4 (urutan paket merusak test F105): PARTS dan dokumen per paket; test F105 yang berubah disebut di fase RED.
- Major 5 (AC-9 tumpang tindih): dihapus; pengecekan bagian per akar komponen di tiap AC.
- Minor 6 summary video opsional (AC-3); 7 redaksi hero dan keputusan tanpa `inner`; 8 validasi empat tema jadi langkah manual; 9 dokumen baris 52 dan pembeda `button`; 10 fixture sebelum perubahan (AC-6).

## Riwayat revisi
- 3 Nov 2026: draf awal.
- 3 Nov 2026, revisi 1: menjawab Review #1.
