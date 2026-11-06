# F108 konten-kait-elemen-tema — Kait tema untuk elemen yang masih tanpa kait

Issue: #148
Status rencana: APPROVED (Review #1 direvisi satu kali; tanpa blocker)
PR ditujukan ke `dev` (cabang `feat/f108-kait-elemen-tema`), memakai `Refs #148`. Jalur penuh (menyentuh kontrak markup yang dipakai tema pihak ketiga).
Sumber celah: butir 12 di `pondok-themes-work/CELAH-SISTEM.md` (repo privat di luar repo ini). Lanjutan F106 (`docs/plans/F106-plan.md`).

## Ringkasan & tujuan
Menambah kait `data-pt-part` pada elemen yang kini hanya bisa ditata lewat selektor posisional (`> div:last-child`, `> p`, `> section`). Murni aditif: satu atribut per elemen di view yang sudah ada, plus `PARTS` dan dokumen. Pola F106 persis: kait hanya tercetak bila tema ber-CSS aktif; tema tanpa CSS identik byte dengan fixture golden; kait F105/F106 tidak diubah namanya.

## Ruang lingkup
- Termasuk: kait di tabel bawah, `ThemeComponents::PARTS`, `docs/tema-kontrak-markup.md`, test.
- Tidak termasuk (di luar ruang lingkup): migrasi CSS empat tema ke kait baru (repo `pondok-themes-work` terpisah, tanpa PR di sini; dicek manual saja); tampilan tema tanpa CSS; butir 7 (`nth`/`source` `feed_list`) dan 8 (`url()` pada custom property); varian `page`; kait pada `x-ui.card` untuk halaman error/Livewire; perubahan tampilan apa pun.

## Mekanisme (sama dengan F106)
- View `styled/*` dan `portal/*` memakai `$pt(part: '...')` / `$ptActive` yang sudah ada. Komponen kartu (`post-card`, `album-card`) sudah punya `$pt`.
- **`components/ui/card.blade.php` TIDAK diubah**: dipakai `livewire/psb/application-status` (portal PSB, sebagian tanpa tema) dan harus aman tanpa `Setting::get`. Kartu kontak di `pages/show` dioper dari pemanggil: `<x-ui.card ... :data-pt-part="$ptActive ? 'contact' : null">` (`$attributes` sudah menyatu ke `<section>`; nama `contact`, bukan `card`, agar tak bentrok dengan komponen `card`). `$ptActive` sudah ada di `pages/show` (dipakai breadcrumb).
- `page` tanpa varian. Bagian `page` baru hanya sah di dalam akar `data-pt="page"`.

## Tabel kait baru (13 nama)
| Komponen | Kait | Elemen pembawa (view) |
|---|---|---|
| `card` | `body` | `div.flex.flex-1.flex-col` pembungkus isi di bawah media: `components/portal/post-card`, `album-card`. Kartu video tidak punya pembungkus isi (anak langsung akar), tanpa kait |
| `text_image` | `inner` | `div` pembungkus grid (anak `section`; kelas grid hanya ada bila `image`): `styled/text_image` baris 3 |
| `text_image` | `content` | `div.min-w-0.space-y-4` pembungkus teks (anak `inner`): baris 8. Dua tingkat, karena markup nyata memang dua `div` bersarang (`media` dan `content` bersaudara di dalam `inner`); nama `content` mengikuti `hero` |
| `cta` | `inner` | satu tingkat: `div.mx-auto.max-w-3xl` (band) dan `div.mx-auto.w-full.max-w-3xl` (card, kartu putih bergaris) anak `section`: `styled/cta-band`, `cta-card` |
| `quote` | `figure` | `figure` (kedua varian): `styled/quote-plain`, `quote-framed` |
| `quote` | `mark` | `div[aria-hidden]` garis hias, **hanya varian `framed`** (opsional): `quote-framed` |
| `footer_widgets` | `name` | `p` nama lembaga: mode ringkas `theme/footer-widgets` dan widget Teks `footer-widgets/text` (opsional: tergantung mode/widget) |
| `footer_widgets` | `logo` | `span` pembungkus logo (hanya bila logo ada, widget Teks; opsional): `footer-widgets/text` |
| `page` | `meta` | `div` baris meta detail berita (selalu ada, memuat tanggal): `posts/show`; `p` kategori album (bila kategori): `albums/show`. Elemennya bervariasi (`div` vs `p`), dicatat di dokumen |
| `page` | `lead` | `p` kutipan berita (`posts/show`, bila `excerpt`) dan `p` deskripsi album (`albums/show`, bila ada) (opsional) |
| `page` | `cover` | `img` sampul berita (`posts/show`, bila ada) (opsional) |
| `page` | `back` | `a` tautan kembali, selalu ada: `posts/show`, `albums/show` |
| `page` | `contact` | `section` kartu kontak (`x-ui.card`, dioper): `pages/show`, hanya halaman kontak (opsional) |

**Aturan tunggal:** hanya kait yang SELALU tercetak pada seed lengkap masuk `ThemeComponents::PARTS`; yang bersyarat data atau mode ditandai `*opsional:*` di `docs/tema-kontrak-markup.md` (test dokumen mengizinkannya). `PARTS` bertambah: `card: body`; `text_image: inner, content`; `cta: inner`; `quote: figure`; `page: meta, back` (diverifikasi di kode: `div` meta `posts/show` dan tautan kembali `posts/show`/`albums/show` tanpa `@if`). Opsional, dokumen saja: `quote: mark`; `footer_widgets: name, logo`; `page: lead, cover, contact`. Dokumen juga mencatat bahwa `meta` bervariasi (`div` di `posts/show`, `p` di `albums/show`).

## Action class, model, UI
Tanpa Action, migration, route, resource, widget, permission, `unit_user`, atau teks `lang/id` baru. `design-guide.md` tetap (tanpa perubahan tampilan). Tidak ada saklar modul baru; `ModuleComplianceTest` harus tetap lulus.

## Kriteria penerimaan
Pengecekan bagian memeriksa DOM di dalam akar `data-pt="<komponen>"` (DomCrawler), bukan `str_contains` global.
- **AC-1** Kartu: di akar `card` varian `post` dan `album` (beranda `feed_list` cards, `/berita`, `/galeri`), `body` membungkus `title` dan `meta`; `summary` hanya di kartu `post`; `body` tidak ada pada kartu `video`.
- **AC-2** `text_image` ber-CSS: `inner` anak langsung akar, `content` anak langsung `inner`, `media` dan `content` bersaudara; tanpa gambar `inner` tetap tercetak dan `content` ada di dalamnya.
- **AC-3** `cta` (band dan card): `inner` anak langsung akar, memuat `heading` dan `actions`.
- **AC-4** `quote` (plain dan framed): `figure` ada dan memuat `text`; `mark` ada hanya di `framed` dan tidak di `plain`.
- **AC-5** Footer: mode ringkas (route `psb.*` / 404 portal) mencetak `name` di akar `footer_widgets`; mode penuh dengan widget Teks mencetak `name`, dan `logo` hanya bila logo ada (tanpa logo, tak ada `logo`). Footer tanpa CSS (kedua mode) tidak memuat `data-pt`; tanpa golden footer baru.
- **AC-6** Detail berita ber-CSS: akar `page` memuat `meta`, `lead` (hanya bila `excerpt`), `cover` (hanya bila sampul), `back`. Tanpa `excerpt` dan tanpa sampul, ketiganya absen.
- **AC-7** Detail album: akar `page` memuat `meta` (bila kategori), `lead` (bila deskripsi), `back`. Halaman kontak: `contact` pada `section` kartu kontak di dalam akar `page`; halaman biasa (bukan kontak) tanpa `contact`.
- **AC-8** Tanpa CSS: HTML beranda dan halaman dalam sama persis dengan fixture golden `tests/Fixtures/portal-html/*` (tak ada `data-pt`, tanpa spasi sisa); `PortalThemeGoldenTest` hijau, ditambah fixture golden halaman kontak (bila fixture ada belum memuatnya), TETAP direkam dari kode sebelum perubahan.
- **AC-9** Kompatibilitas: semua pasangan komponen-bagian F105 dan F106 yang beku di `ThemeMarkupContractTest`/`ThemeMarkupHooksTest` tetap ada dan tercetak; tak ada nama lama diubah.
- **AC-10** Sinkron: tiap bagian di `PARTS` ada di tabel `docs/tema-kontrak-markup.md`; bagian opsional (`mark`, `name`, `logo`, `lead`, `cover`, `contact`) ada di dokumen bertanda *opsional:* dan tidak di `PARTS`; dokumen memuat catatan `inner`+`content` pada `text_image` dan `meta` bervariasi; test dokumen yang ada hijau.
- **AC-11** Validator: satu assert di test validator CSS yang ada bahwa selektor `[data-pt="page"] [data-pt-part="contact"]` dan `[data-pt="quote"] [data-pt-part="mark"]` diterima (`ValidateThemeCss` tidak membaca `PARTS`).
- **AC-12** `x-ui.card` tidak diubah: test PSB yang ada (`application-status`) dan `ModuleComplianceTest` lulus.

## Rencana test
- Tambah ke `tests/Feature/Http/Portal/ThemeMarkupHooksTest.php`: AC-1..AC-7 (helper `portalSeedContent`, `themePkg`, `navSet`, `blkSet` yang sudah ada), AC-9 lewat daftar beku yang diperluas.
- `PortalThemeGoldenTest` (AC-8), `ThemeMarkupContractTest` (AC-10), test validator yang ada (AC-11, satu assert), `ModuleComplianceTest` dan test PSB yang ada (AC-12); tanpa test query-count baru.
- Fase RED: tulis AC-1..7 dulu (gagal karena atribut belum ada); fixture golden tidak direkam ulang kecuali untuk halaman kontak, direkam dari kode SEBELUM perubahan.

## Paket kerja green-coder
- **P1 kartu + text_image + cta (hijau: AC-1, 2, 3, 8 untuk beranda/berita/galeri):** `PARTS` (hanya yang selalu tercetak), baris dokumen, `post-card`, `album-card`, `styled/text_image`, `cta-band`, `cta-card`.
- **P2 kutipan + footer (hijau: AC-4, 5, 8):** `quote-plain`, `quote-framed`, `theme/footer-widgets` (mode ringkas), `footer-widgets/text`; `PARTS`/dokumen untuk `quote` dan `footer_widgets`.
- **P3 halaman dalam (hijau: AC-6, 7, 8 penuh, 12):** `posts/show`, `albums/show`, `pages/show` (kartu kontak dioper), `PARTS['page']` (`meta`, `back`); golden kontak.
- **P4 dokumen + verifikasi (hijau: AC-9, 10, 11 [satu assert], `ModuleComplianceTest`):** `docs/tema-kontrak-markup.md`; manual di laporan akhir: `ValidateThemePackage` terhadap empat tema `pondok-themes-work` (di luar CI).

## Risiko & pertanyaan terbuka
- Kartu kontak memakai komponen bersama `x-ui.card` (juga dipakai portal PSB dan halaman tanpa DB): kait dioper pemanggil, komponen tidak disentuh; lupa mengoper berarti kait hilang diam-diam, dijaga AC-7 (`contact`) dan AC-12.
- `ThemeComponents::hooks()` memanggil `Setting::get`; di `footer-widgets/text` (include dari `partials/footer-widgets`) `$pt` sudah terdefinisi, tetapi widget Teks juga dipakai footer inti non-tema: periksa tidak ada pemanggil tanpa `$pt` (grep `portal.footer-widgets.`).
- **P1** `logo` di issue dan celah ada di footer "mode ringkas"; di kode logo (`span`) hanya ada di widget Teks mode penuh, mode ringkas hanya `name`. Rencana: `name` di keduanya, `logo` di mode penuh. Perlu persetujuan karena menyimpang dari kata issue.
- **P2** Satu atau dua tingkat `inner`: rencana memilih `inner` + `content` pada `text_image` (dua `div` nyata) dan satu `inner` pada `cta` (satu `div` nyata). Alternatif `inner-text` ditolak demi konsistensi dengan `hero`/`content`.
- **P3** Kategori album (`p`) diberi `meta`, bukan `lead`, agar `lead` hanya untuk teks pembuka; celah menyebut keduanya "`> p`". Tema yang ingin sama tinggal memilih dua selektor.
- **P4** Kait `body` kartu video tidak ada karena tidak ada pembungkusnya (ekstraksi ditolak sejak F106).

## Riwayat revisi
- 5 Nov 2026: draf awal.
- 5 Nov 2026, revisi 1: menjawab Review #1.

## Review #1 (plan-reviewer): CHANGES_REQUESTED, 0 blocker, 2 major, 4 minor
- Major 1 `PARTS` vs opsional bertentangan: aturan tunggal di atas; `PARTS` hanya yang selalu tercetak, sisanya opsional di dokumen.
- Major 2 `page` part `card` bentrok dengan komponen `card`: diganti `contact` di semua tempat.
- Minor 3 AC-8 footer: assert tanpa `data-pt`, tanpa golden footer; golden kontak direkam sebelum perubahan. Minor 4 AC-11 jadi satu assert. Minor 5 AC-12 tanpa test query-count. Minor 6 AC-1 `summary` hanya kartu `post`.
- Catatan: `logo` hanya di mode penuh (widget Teks); body issue #148 disesuaikan orkestrator.
