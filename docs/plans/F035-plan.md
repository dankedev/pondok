# F035 portal-identitas-visual — Identitas visual portal (warna logo lembaga, gaya modern ringan)

Status rencana: APPROVED

## Ringkasan & tujuan

Portal publik dan halaman PSB masih memakai emerald, gaya awal F017. F035 mengganti tampilannya ke warna logo lembaga, yaitu navy `#000A3C` sebagai primer dan emas `#F8B800` sebagai aksen. Gayanya mengikuti acuan `docs/brand/referensi-gaya-coursex.md`: ruang kosong lega, bayangan sangat tipis, tombol dan input pill, hiasan cincin dan pola titik, latar section berselang-seling, serta judul besar dan tebal.

Permintaan user (17 Okt 2026): "Lanjutkan F035 (identitas visual) dengan acuan docs/brand/referensi-gaya-coursex.md, warna dari logo lembaga, sekaligus catatan tampilan dari pengecekan F036." Instruksi user sebelumnya: "warnanya aja, layoutnya tetap, tambah sentuhan modern agar tidak kaku dan kotak-kotak."

Hasilnya:
- token `primary-*`, `accent-*`, `gray-*`, font, dan bayangan di `resources/css/app.css` berubah;
- komponen `x-ui.*` dan `x-portal.*`, layout publik, view portal, dan form PSB memakai bentuk baru;
- tiga catatan visual F036 diperbaiki (logo header, nama Arab, slider);
- design guide diperbarui.

Struktur, urutan section, logika, dan teks tidak berubah. Panel admin Filament tidak tersentuh.

**Terus terang soal perilaku:** hampir semua perubahan bersifat visual (kelas CSS). Perilaku yang bisa dan perlu diuji dengan Pest hanya:
1. tidak ada aset eksternal;
2. struktur nama Arab;
3. hiasan bersifat `aria-hidden` dan tidak menambah teks;
4. section kosong tetap tersembunyi (test lama);
5. Filament tetap emerald;
6. pengaman sumber (tanpa emerald, tanpa nilai arbitrer, tanpa `text-accent-*`).

Sisanya diverifikasi visual oleh orkestrator: satu `npm run build`, lalu cek 360px, 1024px, dan desktop di Herd.

## Keputusan utama

### K1. Palet

Skala disusun manual di `@theme` (hex literal, bukan alias palet Tailwind), dengan 950 tepat warna logo. Kontras dihitung dengan rumus WCAG 2.x (relative luminance).

**`primary-*` (navy)**

| Shade | Hex | Kontras di putih | Pemakaian utama |
|---|---|---|---|
| 50 | `#EEF2FB` | 1.12 | latar item nav aktif, badge primary |
| 100 | `#DDE4F6` | 1.27 | teks sekunder di footer navy (14.9:1 di 950) |
| 200 | `#BBC8EC` | 1.67 | pola titik dekoratif, teks redup di footer navy (11.4:1) |
| 300 | `#8EA2DB` | 2.52 | — |
| 400 | `#5F78C4` | 4.23 | — (jangan untuk teks) |
| 500 | `#3D56A8` | 6.79 | cincin fokus di latar terang |
| 600 | `#273E8C` | 9.75 | — |
| 700 | `#1A2C72` | 12.75 | **tautan** dan teks aksen di putih |
| 800 | `#0F1D59` | 15.67 | hover tombol utama |
| 900 | `#061247` | 17.72 | **tombol utama**, chip/halaman aktif, titik langkah |
| 950 | `#000A3C` | 18.94 | warna logo: footer navy, judul hero, teks di atas emas |

**`accent-*` (emas)**

| Shade | Hex | Kontras di putih | Kontras dengan navy 950 |
|---|---|---|---|
| 50 | `#FFF9E5` | 1.05 | 17.98 |
| 100 | `#FFF0BF` | 1.14 | 16.66 |
| 200 | `#FFE28A` | 1.27 | 14.88 |
| 300 | `#FFD452` | 1.42 | 13.34 |
| 400 | `#FFC524` | 1.58 | 11.96 |
| 500 | `#F8B800` | 1.77 | 10.69 |
| 600 | `#D49A00` | 2.49 | 7.60 |
| 700 | `#A67700` | 3.99 | 4.74 |
| 800 | `#7A5700` | 6.58 | 2.88 |
| 900 | `#523A00` | 10.69 | 1.77 |
| 950 | `#2E2100` | 15.75 | 1.20 |

Aturan emas:
- `text-accent-*` **dilarang** di Blade portal, PSB, dan komponen. Dijaga oleh test sumber, AC-6.
- Emas hanya lewat `bg-`, `border-`, `ring-`, `fill-`, `stroke-`, `outline-`, atau `decoration-`.
- Teks di atas emas memakai `text-primary-950`. Pada `accent-400` kontrasnya 11.96:1, pada `accent-500` 10.69:1.
- Cincin fokus di section navy memakai `ring-accent-400` (11.96:1 terhadap navy). `primary-500` di atas navy hanya ±2.8:1.

**`gray-*` → `slate`** (diganti dari zinc). Alasan:
- Slate bernada dingin kebiruan, sehingga teks abu dan latar `gray-50` menyatu dengan navy. Zinc bernada netral keabu-coklatan dan terasa "lepas" di samping navy.
- Kontras tetap aman: `gray-600` `#475569` 7.58:1 di putih (7.24:1 di `gray-50`), `gray-700` 10.35:1, `gray-500` 4.76:1.
- Latar section muda `gray-50` `#F8FAFC` setara `#FBFBFB` acuan.
- Teks isi memakai `gray-600`/`gray-700`. `gray-500` hanya untuk ikon. `gray-400` tidak untuk teks.
- Hanya portal/PSB yang terdampak, karena Filament tidak memuat `app.css` (K5).

**Status `success`/`warning`/`danger`/`info`:** tidak berubah (green, amber, red, sky). Warning amber dan aksen emas berdekatan warnanya. Pembedanya ada pada bentuk: badge aksen berlatar emas padat dengan teks navy, sedangkan badge warning berlatar `warning-50` dengan teks `warning-800`. Lihat Risiko.

### K2. Font: Plus Jakarta Sans

Instrument Sans diganti **Plus Jakarta Sans** lewat plugin font Vite yang sudah ada:
- `bunny('Plus Jakarta Sans', { weights: [400, 500, 600, 700], preload: [{ weight: 400 }, { weight: 700 }] })`;
- diunduh saat `npm run build`, ikut `public/build`, tanpa CDN runtime, sesuai CSP `font-src 'self'`.

Alasan:
- Plus Jakarta Sans adalah padanan langsung Plus Jakarta Display milik acuan, dan judul tebal 700 adalah inti gayanya.
- Desainernya orang Indonesia, berlisensi OFL, dan tersedia di Bunny.
- Jalur build sama dengan sekarang, sehingga tidak ada infrastruktur baru.

Instrument Sans hanya punya 400–600 di konfigurasi saat ini, sehingga judul tebal 700 akan disintesis. Ongkosnya: 4 berkas woff2 Latin (±20–25 KB per berkas), dengan hanya 2 yang di-preload.

`--font-sans` di `@theme` menjadi `'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif, …`.

**`@fonts` sudah dipanggil** di `layouts/public.blade.php` baris 62 (perbaikan `df3f71e`) dan dijaga `tests/Feature/Http/Portal/PortalFontsTest.php`. Jadi tidak ada yang perlu diperbaiki. Test itu tetap lulus.

**Font Arab belum termuat.** `--font-arabic` menyebut `'Noto Naskh Arabic', 'Amiri', …`, tetapi tidak ada `@font-face` maupun berkas di `public/fonts` (isinya hanya `filament/`). Akibatnya teks Arab bergantung pada font sistem pengunjung, yang di Android kelas bawah sering bukan naskh. Perbaikannya:
- tambahkan `bunny('Noto Naskh Arabic', { weights: [400, 700], subsets: ['arabic'], preload: false })` di `vite.config.js`;
- self-host lewat jalur yang sama, dengan `preload: false` karena teks Arab hanya ada di beranda dan footer bila diisi;
- browser hanya mengunduh font itu bila glyph Arab dipakai.

Design guide §3 yang menyebut "di-self-host di `public/fonts`" diperbarui menjadi "self-host lewat plugin font Vite (`public/build`)".

### K3. Bentuk, bayangan, spasi

Token baru di `@theme`. Nilai mengikuti acuan, dengan warna bayangan navy:

```css
--shadow-card: 0 6px 24px rgb(0 10 60 / 0.05);
--shadow-card-lg: 0 20px 48px rgb(0 10 60 / 0.07);
--shadow-button: 0 12px 30px rgb(0 10 60 / 0.18);
```

Token ini menghasilkan utilitas `shadow-card`, `shadow-card-lg`, dan `shadow-button`. Nilai bayangan arbitrer (`shadow-[…]`) tidak boleh ada di Blade.

| Elemen | Bentuk |
|---|---|
| Tombol (`x-ui.button`), tautan "Daftar" di header, chip filter, paginasi, item nav, `summary` menu, skip link | `rounded-full` (pill) |
| Input teks, select, input file (termasuk `file:` button) | `rounded-full` (pill), `px-5` |
| Badge | `rounded-full` (sudah) |
| Kartu (`x-ui.card`, post-card, album-card, kartu keunggulan, figure foto album), alert, empty-state, foto/iframe, menu lipat | `rounded-xl` (12px). Kartu: `border border-gray-100 bg-white shadow-card`, tanpa border abu tebal. Kartu besar form PSB: `shadow-card-lg` |
| Pilihan radio berbentuk ubin (gelombang, jenis kelamin) | `rounded-xl`. Label gelombang bisa dua baris di 360px; pill dua baris terlihat janggal |

Pilihan radius kartu `rounded-xl`, bukan 0 seperti acuan. User meminta "tidak kaku dan kotak-kotak", dan radius kecil plus bayangan tipis lebih dekat dengan permintaan itu daripada sudut tajam.

Tombol utama: `bg-primary-900 text-white hover:bg-primary-800 shadow-button`. Fokus semua varian tombol (dan tautan "Daftar" di header, chip, paginasi aktif) memakai `focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2`. Offset putih memisahkan cincin dari latar navy tombol, karena `primary-500` yang menempel langsung pada `primary-900` hanya ±2.8:1. Tombol sekunder: `border border-gray-300 bg-white text-primary-950 hover:border-primary-900`. Tombol danger: `bg-danger-600` (tetap), pill. Tidak ada varian `accent` baru.

Spasi:
- Section beranda `py-16 lg:py-24`. Section kini punya padding atas dan bawah, menggantikan `pt-12`, dan `mt-12` pada footer dihapus.
- Kontainer halaman dalam (berita, galeri, video, halaman, 404) `py-12 lg:py-20`.
- Lebar kontainer tetap (`max-w-6xl`, `max-w-3xl`, `max-w-2xl`).

### K4. Latar section dan hiasan

**Body** menjadi `bg-white`, sebelumnya `bg-gray-50`.

**Beranda.** Hero dan slider sama-sama putih (slider adalah kelanjutan hero, `pt-0`). Section berikutnya yang benar-benar dirender berselang-seling `bg-gray-50` → `bg-white` → … Urutan section tidak berubah.

Pergantian dihitung oleh closure penghitung kecil di `@php` atas `home.blade.php`, misalnya `$band()`, yang mengembalikan kelas latar berikutnya dan hanya dipanggil di dalam `@if` section. Dengan begitu, section yang tersembunyi tidak membuat dua latar sama berdampingan.

**Halaman non-bare (form PSB).** Di layout saat ini hanya halaman Livewire PSB (`/psb/daftar`, `/psb/status`) yang non-bare. Semua view portal (beranda, berita, galeri, video, halaman statis/kontak, 404) memanggil layout dengan `:bare="true"`. Jadi `bg-gray-50` dipasang hanya pada cabang non-bare: `<main id="konten" class="flex-1">` tetap `flex-1` (body `flex min-h-screen flex-col`), dan kelas `bg-gray-50` ditambahkan pada `<main>` lewat `@class(['flex-1', 'bg-gray-50' => ! $bare])`. Dengan `flex-1`, latar abu memenuhi tinggi sisa sampai footer navy, sehingga tidak ada celah putih. Halaman portal bare (termasuk prosa artikel) tetap di atas body putih.

**Footer** adalah satu-satunya section gelap. Layout saat ini tidak punya pita statistik, dan tidak boleh dibuat (tanpa konten bawaan). Rinciannya:
- footer `bg-primary-950` dengan garis aksen `border-t-4 border-accent-500`;
- kutipan ayat di dalam footer: teks Arab `text-white`, terjemahan `text-primary-100`, sumber `text-primary-200`;
- nama lembaga `text-white`, deskripsi dan kontak `text-primary-100`;
- tautan `text-primary-100 hover:text-white`, fokus `ring-accent-400`;
- baris hak cipta `border-white/10 text-primary-200`.

`portal/partials/contact-list.blade.php` dipakai di footer (navy) dan di halaman Kontak (kartu putih). Karena itu partial ini menerima parameter `$onDark` (bool, bawaan `false`) yang memilih set kelas teks, ikon, dan tautan. Isi dan urutannya tidak berubah.

**Hiasan** (inti "tidak kotak-kotak"):
- Dua kelas komponen di `app.css` (`@layer components`):
  - `.portal-decor-ring`: cincin tebal setengah lingkaran. `aspect-ratio: 1`, `border-radius: 9999px`, border tebal `var(--color-accent-400)`, `clip-path` setengah.
  - `.portal-decor-dots`: pola titik, `radial-gradient` dengan `var(--color-primary-200)` dan `background-size` tetap.
- Murni CSS: tanpa gambar, tanpa SVG eksternal, tanpa aset di `public/img`.
- Ditaruh sebagai `<div aria-hidden="true">` kosong, `pointer-events-none absolute`, di dua tempat:
  1. **Hero:** pola titik di sisi kanan, hanya `lg:block` (mengisi ruang kosong di kanan blok teks `max-w-3xl`).
  2. **Slider album beranda** (foto hero): cincin di sudut kiri bawah dan pola titik di sudut kanan atas, di belakang foto (`-z-10` di dalam wadah `relative isolate`), hanya `sm:block`.
- Di bawah `sm` (termasuk 360px) hiasan tidak dirender (`hidden`), sehingga tidak bisa menutupi konten atau memicu scroll horizontal.
- Section berhias memakai `overflow-x-clip`. `overflow-hidden` tidak dipakai, agar cincin fokus slider tidak terpotong.
- Tanpa animasi apa pun, termasuk muncul-saat-scroll.

**Penanda emas lain:**
- badge "Pendaftaran dibuka" (`x-ui.badge color="accent"`): `bg-accent-400 text-primary-950`;
- indikator langkah PSB (`x-ui.steps`): **markup dan bentuk tetap** (batang segmen `h-1.5 rounded-full`, label tersembunyi di mobile, `aria-current="step"`, teks `step_counter`). Hanya warna yang berganti: langkah selesai dan aktif `bg-primary-900`, langkah berikutnya `bg-gray-200` (keputusan GREEN atas Review #2 temuan 2: emas 1.77:1 di putih terlalu samar melawan `gray-200`);
- garis tepi atas footer;
- `blockquote` di `.portal-prose`: `border-accent-500`.

### K5. Filament tidak berubah

Hasil verifikasi:
- `AdminPanelProvider` memakai `'primary' => Color::Emerald` tanpa `viteTheme()`;
- tidak ada `@vite`/`app.css` di `resources/views/filament` atau `app/Filament`;
- `app.css` hanya dimuat oleh `layouts/public.blade.php`.

Jadi perubahan token di `app.css` tidak menjangkau panel. `AdminPanelProvider` tidak disentuh. Dokumen cetak (`dokumen/*`, `keuangan/receipt`) dan halaman verifikasi surat (`dokumen/verify.blade.php`, CSS inline sendiri) juga tidak memuat `app.css` dan berada di luar ruang lingkup.

### K6. Perbaikan catatan F036

**(a) Logo header desktop.**
- Cabang yang diubah: (1) cabang `<picture>` (logo desktop dan mobile terpasang; satu `<img>` untuk kedua sumber) dan (2) cabang logo desktop saja. `<img>` keduanya menjadi `h-10 md:h-14`. Cabang logo mobile saja (`<span class="shrink-0 md:hidden">`) tidak berubah, karena di `md` ke atas cabang itu menampilkan teks nama. Kelas `max-w-full object-contain` dipertahankan, sehingga test F036 AC-38 (`h-10`, `max-w-full`, `object-contain`) tetap lulus tanpa diubah.
- Wadah `<a>` tetap `min-w-0`, dan logo diberi `md:max-w-64` agar logo melebar tidak mendesak menu 8 item di 1024px.
- Logo mobile tetap `h-10`.
- Nama teks (tanpa logo) menjadi `text-lg font-bold text-primary-950`.

**(b) Nama Arab "mengambang".** Penyebabnya: `<p lang="ar" dir="rtl">` adalah blok selebar penuh. Dengan `dir="rtl"`, teksnya rata kanan, sementara greeting, `h1`, dan subjudul rata kiri. Elemen itu juga tidak memakai `font-arabic`, bertentangan dengan design guide §3.

Struktur baru:

```blade
<hgroup class="space-y-…">
    <p class="font-arabic …"><span lang="ar" dir="rtl">{{ arabic_name }}</span></p>
    <p>{{ greeting }}</p>      {{-- bila ada --}}
    <h1>…</h1>
    <p>{{ subtitle }}</p>      {{-- bila ada --}}
</hgroup>
```

- `<p>` luar ber-arah LTR, sehingga potongan RTL inline duduk di tepi awal yang sama dengan `h1`. Isolasi bidi bawaan elemen ber-`dir` membuat urutan glyph Arab tetap benar.
- Ukuran Arab minimal 1.25× teks sekitar, sesuai §3: `text-2xl sm:text-3xl leading-loose`.
- `<hgroup>` (HTML living standard: satu heading dan `p` di sekitarnya) menyatukan nama Arab dan `h1` secara semantik.
- Badge PSB tetap di luar `hgroup`, di atasnya. Urutan tampil tidak berubah: badge, nama Arab, greeting, judul, subjudul, tombol.
- Test F036 yang mencari `<tag lang="ar" dir="rtl">\s*معهد الخير` tetap cocok, karena `<span>` memenuhi pola `[a-z0-9]+`.

**(c) Warna portal masih emerald.** Diselesaikan oleh K1.

**(d) Slider `w-[85%] sm:w-[60%] lg:w-[45%]`.** Diganti `w-5/6 sm:w-3/5 lg:w-5/12`, yaitu pecahan skala Tailwind (83.3% / 60% / 41.7%), dengan `shrink-0 snap-start` tetap. Hasilnya: di 360px foto berikutnya mengintip (5/6 lebar). Di `lg`, foto sedikit lebih sempit dari sebelumnya (41.7% vs 45%), sehingga dua foto penuh tampil dan foto ketiga mengintip sedikit lebih lebar. Test sumber memastikan tidak ada nilai arbitrer di view portal (AC-13).

**Batasan struktur untuk green-coder:** region slider (`<div role="region" …>`) harus tetap berada di dalam `<section>` sendiri, dan `</section>` pertama setelah region adalah penutup section slider. Helper test lama `profilSlider()` (`tests/Feature/Http/Portal/InstitutionHomeTest.php:17`) mengambil HTML dari `<div role="region">` sampai `</section>` pertama. Hiasan slider boleh berada di dalam section yang sama, tetapi tidak boleh membungkus region dengan `<section>` lain.

### K7. Tipografi portal

Skala ini hanya untuk portal/PSB. Filament tidak berubah.

| Elemen | Kelas |
|---|---|
| `h1` hero beranda | `text-4xl font-bold tracking-tight text-primary-950 sm:text-5xl lg:text-6xl text-balance` (36 → 60px, sesuai acuan) |
| `h2` section beranda | `text-3xl font-bold tracking-tight text-gray-900 lg:text-4xl` |
| `h1` halaman dalam (berita, galeri, video, halaman, detail berita/album, 404, judul PSB) | `text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl` |
| Subjudul hero | `text-lg text-gray-600 lg:text-xl` |
| Isi paragraf section beranda dan `.portal-prose` | `text-base leading-relaxed lg:text-lg` |
| Judul kartu | `text-lg font-semibold` (tetap) |
| Tombol | `text-base font-semibold` (tetap) |

Design guide §3 diperbarui: `font-bold` diizinkan untuk `h1`/`h2` portal. `text-5xl`–`text-6xl` hanya untuk `h1` hero beranda.

### K8. Kelas input bersama

Form PSB memiliki ±12 input/select dengan string kelas panjang yang identik. Kelas itu dipindahkan ke satu kelas komponen `.ui-input` di `app.css`:

```css
.ui-input {
    @apply min-h-11 w-full rounded-full border border-gray-300 bg-white px-5 text-base text-gray-900
        focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500;
}
```

- Dipakai di `registration-form` dan `application-status` untuk `input` teks/tel/email/date dan `select`.
- Kelas tambahan per field (`tabular-nums`, `tracking-wide`) tetap ditulis di elemen.
- Input file memakai `.ui-input` ditambah kelas `file:` (pill).
- `x-ui.field` sendiri tidak membungkus input (slot), jadi hanya label, hint, dan error yang diselaraskan: `px-1` agar sejajar dengan tepi pill.

Ini refaktor kelas, bukan perubahan perilaku. ID, `wire:model`, atribut `autocomplete`, `inputmode`, dan `maxlength` tetap.

### K9. Acuan susunan dan keadaan "Lembaga baru"

- **Susunan section** mengikuti portal yang ada di kode sekarang (hasil F036/F037), yang dibangun dari mockup "Portal Publik v3 versi 5": isian profil generik, logo desktop/mobile, keadaan "Lembaga baru", dan tanpa konten bawaan. Rencana ini tidak memakai mockup lain sebagai patokan susunan, termasuk arah visual lama yang sudah dibatalkan user. Gaya visual hanya dari `docs/brand/referensi-gaya-coursex.md`, warna hanya dari logo.
- **Keadaan "Lembaga baru"** (instalasi tanpa DemoSeeder, profil kosong selain nama) wajib tetap rapi dengan gaya baru. Yang tampil di keadaan ini:
  - header: nama lembaga sebagai teks (`text-lg font-bold text-primary-950`) dan menu bawaan;
  - hero: hanya `<h1>` nama lembaga (dan slogan bila diisi), dengan skala judul baru dan hiasan pola titik di `lg`. Tanpa badge, nama Arab, greeting, atau tombol bila kondisinya tidak terpenuhi;
  - tidak ada section lain (semua kosong, tidak dirender), sehingga tidak ada pita abu kosong: `$band()` hanya dipanggil oleh section yang dirender;
  - footer navy: nama lembaga dan baris hak cipta, tanpa kutipan, deskripsi, kontak, atau sosial; tautan cepat dan tautan "Status pendaftaran"/"Verifikasi surat" hanya bila menu footer diisi atau modulnya aktif.
- Hasilnya harus terlihat sebagai halaman yang sengaja sederhana: hero putih lega, lalu footer navy, tanpa celah janggal atau kotak kosong. Tidak ada teks atau gambar pengisi yang ditambahkan untuk "mengisi" halaman. Ini dicek visual di AC-20 dan perilakunya dijaga AC-12.

## Ruang lingkup

Termasuk:
- `resources/css/app.css`: token `primary`, `accent`, `gray`, `--font-sans`, `--shadow-*`, kelas `.portal-decor-ring`/`.portal-decor-dots`, kelas `.ui-input` (K8), dan penyesuaian `.portal-prose` (radius gambar, `blockquote` emas, ukuran isi).
- `vite.config.js`: `bunny('Plus Jakarta Sans', …)` menggantikan Instrument Sans, ditambah `bunny('Noto Naskh Arabic', …)`.
- Layout publik, seluruh view portal, `errors/404`, komponen `x-ui.*` dan `x-portal.*`, serta dua view Livewire PSB. Hanya kelas dan struktur pembungkus. Logika, kondisi `@if`, binding `wire:*`, teks, dan urutan tidak berubah.
- `docs/design-guide.md` §0, §2, §3, §6, §12.
- `docs/brand/referensi-gaya-coursex.md` masuk repo (saat ini untracked). Tabel tokennya dilengkapi nilai skala final.
- Test: satu berkas test baru dan perluasan AC-32 di `PortalAccessibilityTest.php`.

Tidak termasuk (eksplisit):
- Panel admin Filament: warna, font, dan tema tetap.
- Perubahan layout, urutan, atau jumlah section. Tanpa section baru (statistik, testimoni, FAQ, langkah PSB di beranda): pola itu ada di acuan, tetapi butuh data baru, sehingga menjadi fitur lain bila diminta.
- Tombol kedua "Info PSB" di hero (bergantung F038).
- Animasi muncul-saat-scroll, transisi dekoratif, mode gelap.
- Teks baru di `lang/id`. Tidak ada teks yang ditambah; hiasan tanpa teks.
- Dokumen cetak, kop surat, kuitansi, kartu santri, halaman verifikasi surat.
- Favicon dan logo itu sendiri (sudah dari profil lembaga F036).
- Tema yang bisa diatur admin (warna/font dari settings), yang ditunda sejak F036 tanpa entri.

## Model data

Tidak ada perubahan skema, migrasi, settings, atau model.

## Action class

Tidak ada Action baru atau yang diubah. `GetSchoolProfile`, `GetPortalNavigation`, dan `GetPortalHomeData` dibaca apa adanya.

## UI (Filament / Blade)

### Kontrak desain (§13)

- **Pengguna & tujuan:** calon wali/pendaftar (mayoritas HP Android) dan masyarakat umum. Mereka mengenali lembaga, membaca kabar, dan mendaftar PSB. Kesan yang dicari: lembaga yang tenang, rapi, dan bisa dipercaya, dengan warna yang sama dengan logo dan brosur.
- **Aksi utama:** "Daftar" PSB (tombol navy di header dan hero, bila PSB dibuka). Bila PSB tutup: "Baca berita". Aksi sekunder: tautan "Lihat semua" per section, menu, dan kontak di footer.
- **Urutan informasi beranda:** sama seperti sekarang, yaitu badge PSB → nama Arab → greeting → nama/judul → slogan → tombol → slider → keunggulan → video profil/PSB → profil → kabar → prestasi → video → galeri → footer (kutipan, identitas, kontak, tautan).
- **State:**
  - section kosong tidak dirender (F036);
  - empty-state daftar (berita, galeri, video, halaman) memakai `x-ui.empty-state` gaya baru;
  - PSB: loading tombol (`wire:loading`), alert gagal (`danger`), layar berhasil, layar PSB tutup;
  - 404 bergaya baru;
  - tanpa izin dan modul mati: perilaku F017/F036 tetap (item hilang, route 404).
- **Perilaku 360px:**
  - satu kolom;
  - tombol utama lebar penuh (`w-full sm:w-auto`, tetap);
  - hiasan tidak dirender;
  - slider menampilkan 5/6 lebar foto, sehingga foto berikutnya mengintip;
  - logo `h-10`, menu lipat;
  - `h1` hero 36px dengan `text-balance` dan `break-words`;
  - tanpa scroll horizontal.

### Berkas yang disentuh

| Berkas | Perubahan |
|---|---|
| `resources/css/app.css` | K1, K2 (`--font-sans`), K3 (`--shadow-*`), K4 (kelas hiasan), K8 (`.ui-input`), `.portal-prose` |
| `vite.config.js` | K2: Plus Jakarta Sans, Noto Naskh Arabic |
| `resources/views/layouts/public.blade.php` | body putih, `<main>` tetap `flex-1` dan `bg-gray-50` hanya bila non-bare (form PSB), header (logo K6a, `border-gray-100`), skip link pill, `summary` dan menu lipat |
| `resources/views/portal/home.blade.php` | `hgroup` nama Arab (K6b), skala judul, `$band()`, hiasan, slider (K6d), kartu keunggulan `rounded-xl shadow-card` (tanpa nomor), spasi |
| `resources/views/portal/partials/footer.blade.php` | footer navy (K4) |
| `resources/views/portal/partials/contact-list.blade.php` | parameter `$onDark` |
| `resources/views/portal/partials/nav-links.blade.php` | item pill, aktif `bg-primary-50 text-primary-900`, tombol Daftar navy pill `shadow-button` |
| `resources/views/portal/partials/pagination.blade.php` | pill, halaman aktif `bg-primary-900` |
| `resources/views/portal/posts/index.blade.php`, `posts/show.blade.php`, `pages/show.blade.php`, `albums/index.blade.php`, `albums/show.blade.php`, `videos/index.blade.php` | skala `h1`, spasi, radius gambar/figure |
| `resources/views/errors/404.blade.php` | skala `h1`, spasi |
| `resources/views/components/ui/button.blade.php`, `field.blade.php`, `badge.blade.php`, `card.blade.php`, `alert.blade.php`, `empty-state.blade.php`, `steps.blade.php` | K3, K4 (badge accent; `steps` hanya warna batang) |
| `resources/views/components/portal/post-card.blade.php`, `album-card.blade.php`, `filter-chips.blade.php`, `video-embed.blade.php` | kartu `rounded-xl shadow-card`, chip aktif `bg-primary-900`, iframe `rounded-xl` |
| `resources/views/livewire/psb/registration-form.blade.php`, `application-status.blade.php` | `.ui-input`, ubin radio `rounded-xl`, tautan `text-primary-700`, kotak nomor/token `rounded-xl` |
| `docs/design-guide.md` | §0, §2, §3, §6, §12 |
| `docs/brand/referensi-gaya-coursex.md` | masuk repo, tabel token dilengkapi skala final |
| `tests/Feature/Http/Portal/PortalAccessibilityTest.php` | AC-32 diperluas (lihat Rencana test) |
| `tests/Feature/Http/Portal/PortalVisualIdentityTest.php` | baru |

Tidak disentuh: `app/Providers/Filament/AdminPanelProvider.php`, `app/**` lainnya, `lang/id/pondok.php`, migrasi, seeder.

### Design guide (rincian perubahan)

- **§0:**
  - butir warna ditambah: "Portal/PSB: navy dari logo untuk aksi, emas hanya latar/garis/hiasan (tidak pernah `text-accent-*`), teks isi `gray-600`/`gray-700`";
  - butir baru: "Bayangan hanya token `shadow-card`/`shadow-card-lg`/`shadow-button`; tanpa nilai arbitrer".
- **§2:**
  - tabel token: kolom Filament tetap `Color::Emerald`. Kolom Tailwind: `primary-*` navy (skala hex K1, 950 = `#000A3C` logo), `accent-*` emas (500 = `#F8B800` logo), `gray-*` slate;
  - catatan bahwa portal dan panel sengaja berbeda primer: panel memakai nama semantik Filament dan tidak memuat `app.css`;
  - aturan emas (K1), aturan kontras teks di atas primary disesuaikan (teks putih di atas `primary-700` ke atas; teks navy di atas emas);
  - latar: halaman putih, section berselang `gray-50`, footer `primary-950`;
  - "Maksimal satu elemen accent per layar" diganti: "Maksimal satu sorotan emas berteks (badge) per layar; batang langkah, garis, dan hiasan tidak dihitung";
  - rujukan `docs/brand/referensi-gaya-coursex.md`.
- **§3:**
  - huruf Latin Plus Jakarta Sans (400/500/600/700, lewat `@fonts`);
  - teks Arab Noto Naskh Arabic self-host lewat plugin font Vite;
  - skala judul portal (K7);
  - `font-bold` diizinkan untuk `h1`/`h2` portal.
- **§6 (portal):**
  - radius: pill untuk tombol, input, select, badge, chip, paginasi, item nav; `rounded-xl` untuk kartu, alert, foto, iframe, ubin pilihan;
  - "Bayangan maksimal `shadow-sm`" diganti token bayangan (K3);
  - kartu `border-gray-100` + `shadow-card`;
  - spasi section `py-16 lg:py-24`, halaman dalam `py-12 lg:py-20`;
  - selang-seling latar beranda (K4);
  - hiasan cincin/pola titik: hanya di hero dan slider, `aria-hidden`, tidak dirender di bawah `sm`;
  - kelas `.ui-input`.
- **§12:**
  - hiasan hanya geometris (cincin, titik, garis) dari CSS, tanpa gambar dekoratif eksternal;
  - tanpa animasi muncul-saat-scroll, tanpa animasi dekoratif;
  - tanpa bayangan arbitrer.

## Permission & cakupan

Tidak ada permission baru, perubahan peran, scope `unit_user`, atau saklar modul. Saklar `modules.konten.enabled` dan `modules.psb.enabled` tetap menentukan section dan tautan seperti sekarang.

## Kriteria penerimaan (bisa dites)

Konvensi data uji: `portalBoot()` (konten aktif, nama dan slogan lembaga), ditambah data sesuai butir. "Halaman portal" dalam AC berarti `/`, `/berita`, `/berita/{slug}`, `/galeri`, `/galeri/{slug}`, `/video`, `/kontak` (dengan data kontak), `/psb/daftar` (PSB aktif, ada gelombang), `/psb/status`, dan URL tak dikenal (404).

### Token & warna

- **AC-1 Token logo.** Kondisi: berkas `resources/css/app.css`. Hasil:
  - `--color-primary-950` bernilai `#000a3c` (tanpa beda huruf besar/kecil);
  - `--color-accent-500` bernilai `#f8b800`;
  - berkas tidak memuat kata `emerald`.

  Shade lain dan pilihan `gray` (slate) tidak dikunci test. Nilainya tercatat di K1 dan design guide.
- **AC-2 Tanpa emerald di portal/PSB.** Kondisi: semua berkas Blade di `resources/views/portal/**`, `components/ui/*`, `components/portal/*`, `livewire/psb/*`, `layouts/public.blade.php`, dan `errors/404.blade.php`. Hasil: regex palet mentah AC-32 F017 (`\b(?:bg|text|border|ring|from|to|fill|stroke)-(?:red|green|emerald|amber|sky|zinc|slate|blue|yellow|indigo|neutral|stone)-\d{2,3}\b`) diperluas hanya dengan prefiks `outline|decoration|divide|placeholder`, dan tidak menemukan apa pun. Prefiks `accent-` sengaja tidak masuk regex, sehingga utilitas `accent-primary-*` (warna radio/checkbox di form PSB) tetap sah. Tidak ada hex di atribut `class`/`style`, dan tidak ada `dark:` maupun `!important`.
- **AC-3** (dihapus pada revisi 1: duplikat AC-2 dan rawan gagal palsu bila konten memuat kata itu).
- **AC-4 Tautan dan teks lolos kontras.** Kondisi: tabel K1 di rencana ini, dicek ulang oleh orkestrator saat verifikasi visual. Hasil:
  - tautan teks memakai `text-primary-700` atau lebih gelap (≥ 12.7:1 di putih);
  - teks isi memakai `gray-600`/`gray-700`/`gray-900`;
  - di footer navy, teks memakai `text-white`/`text-primary-100`/`text-primary-200` (≥ 11.3:1);
  - tidak ada `text-gray-400`/`text-gray-300` pada teks yang harus dibaca di latar terang.

  AC-4 adalah aturan design guide yang dicek visual di AC-20, tanpa test Pest. Rasio di tabel K1 dihitung dari hex final, dan design guide mencantumkannya.
- **AC-5** (dihapus pada revisi 1: penjaga sumber per baris rapuh dan gagal pada `x-dynamic-component` ikon di `empty-state`. Aturan "`gray-300`/`gray-400` hanya untuk ikon" menjadi aturan design guide dan dicek visual di AC-20).
- **AC-6 Emas bukan warna teks.** Kondisi: berkas Blade AC-2. Hasil: tidak ada kelas `text-accent-` (termasuk varian seperti `hover:text-accent-`). Elemen berlatar `bg-accent-*` memakai `text-primary-950`.

### Bentuk & hiasan

- **AC-7 Tombol dan input pill.** Hasil pada render:
  - `x-ui.button` (semua varian, `<a>` maupun `<button>`) memuat `rounded-full`;
  - tombol "Daftar" di header dan chip filter memuat `rounded-full`;
  - form PSB: setiap `<select>` dan setiap `<input>` terlihat bertipe `text`, `tel`, `email`, `date`, atau `file` memuat kelas `ui-input`. Diperiksa pada render `/psb/daftar` langkah 1 (select), langkah 2 (text, date), langkah 3 (text, tel, email), serta `/psb/status` sebelum masuk (dua input text) dan sesudah masuk dengan berkas yang bisa diunggah (input file);
  - pengecualian: input ber-`tabindex="-1"` (honeypot `website`), `radio`, dan `checkbox` tidak wajib memuat `ui-input`;
  - `.ui-input` di `app.css` memuat `rounded-full`.

  Ini satu-satunya AC yang mengunci kelas bentuk, karena "pill" adalah butir acceptance F035. Kelas warna tidak diuji.
- **AC-8 Bayangan token.** Kondisi: `app.css` dan berkas Blade AC-2. Hasil:
  - `@theme` mendefinisikan `--shadow-card`, `--shadow-card-lg`, dan `--shadow-button`;
  - tidak ada `shadow-[` (bayangan arbitrer) di berkas Blade AC-2. Utilitas bawaan `shadow-sm`…`shadow-2xl` tidak dilarang test; pemakaian token dianjurkan lewat design guide.
- **AC-9 Hiasan dekoratif dan aman.** Kondisi: `GET /` dengan album slider terpasang (`portal.hero_album_id`) dan tanpa album slider. Hasil:
  - setiap elemen yang kelasnya memuat `portal-decor-` punya `aria-hidden="true"`, tidak berisi teks atau elemen anak, dan memuat kelas `hidden` (tidak dirender di bawah breakpoint);
  - tanpa album slider, hiasan hanya ada di hero;
  - dengan album slider, ada hiasan tambahan di wadah slider;
  - hiasan tidak menambah gambar atau skrip: jumlah `<img` di region slider sama dengan jumlah foto album (maks 10), dan jumlah `<script` di beranda dengan album slider sama dengan jumlah `<script` pada baseline tanpa album slider (pola test F036 AC-46);
  - region slider tetap di dalam `<section>` sendiri, sehingga helper `profilSlider()` tetap bekerja (K6d).
- **AC-10 Tanpa aset eksternal.** Kondisi:
  - semua halaman portal di-GET dengan profil lengkap ala demo: logo desktop/mobile/favicon di disk `public` palsu, album slider, kutipan footer, dan kontak;
  - `app.css` dan `vite.config.js` dibaca sebagai teks.

  Hasil:
  - HTML tidak memuat `fonts.googleapis`, `fonts.gstatic`, `bunny.net`, `webflow`, `unpkg`, `jsdelivr`, `cdnjs`, `cloudflare`;
  - tidak ada `<link rel="stylesheet"` atau `<link rel="preload"` ber-`href` `http(s)://` ke host selain host aplikasi;
  - `app.css` tidak memuat `@import url(` maupun `url(http`;
  - `vite.config.js` hanya memakai fungsi font `bunny(` (unduh saat build), tanpa string `https://`.

  Satu-satunya host luar yang tetap boleh adalah embed `youtube-nocookie.com` milik F017 di halaman yang memang punya video. Halaman yang diuji AC-10 tidak memasang video, kecuali bila profil demo memasangnya; dalam hal itu host itu dikecualikan eksplisit.

### Layout tetap & catatan F036

- **AC-11 Layout dan urutan section tetap.** Kondisi: `GET /` dengan semua section terisi (badge PSB, nama Arab, greeting, judul, subjudul, slider, keunggulan, video profil dan PSB, profil, kabar, prestasi, video unggulan, galeri, kutipan footer). Hasil:
  - urutan kemunculan penanda di HTML sama dengan urutan saat ini: `aria-labelledby`/`id` seksi `seksi-keunggulan` < `seksi-profil` < `seksi-kabar` < `seksi-prestasi` < `seksi-video` < `seksi-galeri`;
  - region slider (`role="region"`) berada setelah `<h1>` dan sebelum `seksi-keunggulan`;
  - `<footer>` terakhir.
- **AC-12 Section kosong tetap tersembunyi.** Kondisi: instalasi baru (`portalBoot(freshPages: true)`, tanpa DemoSeeder). Hasil:
  - test F036 `NoBuiltInContentTest` dan `InstitutionHomeTest` AC-45 tetap lulus tanpa diubah;
  - HTML beranda tidak memuat `role="region"`, `snap-x`, `<iframe`, `lang="ar"`, `<blockquote`, `seksi-keunggulan`, dan `seksi-profil`;
  - hiasan hero boleh ada (tanpa teks, AC-9);
  - beranda keadaan "Lembaga baru" (K9) hanya memuat `<h1>` nama lembaga di `<main>`, ditambah header dan footer: tidak ada `<h2>` di dalam `<main>`.
- **AC-13 Tanpa nilai arbitrer di view portal.** Kondisi: `resources/views/portal/**`, `components/ui/*`, `components/portal/*`, `layouts/public.blade.php`, `errors/404.blade.php`. Hasil:
  - tidak ada kelas bernilai arbitrer (`-[` di dalam atribut `class`), sehingga `w-[85%]`, `sm:w-[60%]`, dan `lg:w-[45%]` hilang;
  - gambar slider beranda tetap memuat `snap-start` dan `shrink-0` (test F036 AC-46 `snap-x` tetap lulus).

  `livewire/psb/*` dikecualikan karena honeypot `-left-[9999px]`, `grid-cols-[auto_1fr]`, dan `has-[:checked]` adalah pola fungsional yang ada sebelum F035.
- **AC-14 Nama Arab sejajar.** Kondisi: `school.arabic_name = "معهد الخير"`. Hasil pada `GET /`:
  - ada satu `<hgroup>` yang memuat `<h1>` dan elemen `lang="ar" dir="rtl"` berisi `معهد الخير`;
  - elemen ber-`lang="ar"` itu adalah `<span>` yang induk langsungnya `<p>` tanpa atribut `dir`;
  - `<p>` itu memuat kelas `font-arabic`;
  - dalam `hgroup`, nama Arab muncul sebelum `<h1>`;
  - tanpa `arabic_name`, `hgroup` tetap ada (berisi `h1`) dan tidak memuat `lang="ar"`.
- **AC-15 Logo header desktop lebih besar.** Kondisi: dua kasus, (a) logo desktop dan mobile terpasang (cabang `<picture>`), (b) hanya logo desktop. Hasil pada kedua kasus: `<img>` logo di header tetap memuat `h-10`, `max-w-full`, dan `object-contain` (test F036 AC-38 tidak diubah), dan memuat kelas tinggi `md:h-` dengan nilai lebih dari 10 (diuji dengan regex `md:h-(1[2-9]|[2-9]\d)`). Kasus (c) hanya logo mobile tidak diuji, karena cabang itu tidak berubah. Ukuran nyata dicek visual di 1024px: menu 8 item tidak meluap.
- **AC-16 Font self-host termuat.** Kondisi: test `PortalFontsTest` lama. Hasil: tetap lulus (`@fonts` di layout). Tambahan sumber:
  - `vite.config.js` memuat `bunny('Plus Jakarta Sans'` dengan bobot 700, dan `bunny('Noto Naskh Arabic'` dengan `subsets` berisi `arabic` dan `preload: false`;
  - tidak memuat `Instrument Sans`;
  - `--font-sans` di `app.css` diawali `'Plus Jakarta Sans'`;
  - `--font-arabic` diawali `'Noto Naskh Arabic'`.

### Filament, PSB, dokumen

- **AC-17 Panel admin tidak berubah.** Hasil:
  - `Filament::getPanel('admin')->getColors()['primary']` sama dengan `Color::Emerald`;
  - `getViteTheme()` bernilai `null`;
  - `GET /admin/login` (tamu) 200 dan HTML-nya tidak memuat `resources/css/app.css` maupun `Plus Jakarta`.
- **AC-18 Form PSB tanpa perubahan logika.** Kondisi: test Livewire `tests/Feature/Livewire/Psb/RegistrationFormTest.php` dan `ApplicationStatusTest.php`. Hasil: semua tetap lulus tanpa diubah. Render `/psb/daftar` di setiap langkah tetap memuat `wire:model` yang sama (dicek oleh test lama). Indikator langkah tetap batang segmen dengan markup yang sama: `aria-current="step"` pada langkah aktif dan teks `step_counter`, tanpa angka baru.
- **AC-19 Dokumen di repo.** Hasil:
  - `docs/brand/referensi-gaya-coursex.md` ter-track git (masuk commit fitur);
  - `docs/design-guide.md` §2 memuat `#000A3C` dan `#F8B800` serta kalimat bahwa Filament tetap `Color::Emerald`;
  - §3 menyebut Plus Jakarta Sans;
  - §6 menyebut `rounded-full` untuk tombol/input dan `shadow-card`;
  - §12 melarang animasi muncul-saat-scroll.

  Diperiksa manual oleh plan-reviewer/red-tester VERIFY, tanpa test Pest.

### Verifikasi visual (orkestrator, tanpa Pest)

- **AC-20 Checklist §0 dan tiga lebar.** Setelah satu `npm run build`, beranda (data DemoSeeder), `/berita`, `/berita/{slug}`, `/galeri/{slug}`, `/kontak`, `/psb/daftar` (langkah 1 dan 2), `/psb/status`, dan 404 dibuka di Herd pada 360px, 1024px, dan ≥1280px. Kriteria lolos:
  - tanpa scroll horizontal;
  - hiasan tidak menutupi teks atau foto;
  - logo desktop terlihat jelas dan menu 8 item muat di 1024px;
  - nama Arab sejajar kiri dengan judul dan memakai font naskh (cek di DevTools: font yang dirender "Noto Naskh Arabic");
  - judul Latin Plus Jakarta Sans;
  - fokus keyboard terlihat di latar putih dan navy; tombol navy menampilkan cincin dengan `ring-offset-2` (celah putih antara tombol dan cincin);
  - teks abu redup (`gray-300`/`gray-400`) hanya pada ikon atau paginasi nonaktif (pengganti AC-5);
  - tombol pill navy dengan bayangan tipis;
  - section berselang-seling tanpa dua latar sama berdampingan;
  - beranda keadaan "Lembaga baru" (K9: `migrate:fresh` dengan seeder instalasi, tanpa DemoSeeder) tampil rapi di 360px, 1024px, dan desktop: hero putih lega dengan nama lembaga, langsung disusul footer navy, tanpa celah janggal atau kotak kosong;
  - footer navy terbaca.

  Tambahan:
  - `grep -r "bunny.net\|googleapis" public/build` hasilnya kosong (font CSS di build merujuk berkas lokal);
  - ukuran `public/build/assets/app-*.css` dicatat (perkiraan naik < 5 KB).

## Rencana test

**Test lama yang terdampak.** Diperbarui di fase RED oleh red-tester; green-coder tidak mengubah test.

| Berkas | Alasan |
|---|---|
| `tests/Feature/Http/Portal/PortalAccessibilityTest.php` (AC-32 F017, ±baris 61–92) | Daftar berkas diperluas ke semua `components/ui/*` dan `livewire/psb/*`. Regex palet mentah ditambah prefiks `outline`/`decoration`/`divide`/`placeholder`. Prefiks `accent-` tidak dimasukkan (utilitas `accent-primary-*` sah). Ditambah larangan `text-accent-` (AC-6) dan `shadow-[` (AC-8). Asersi lama tetap |

Test yang **tidak** perlu diubah (diperiksa):
- `InstitutionHeaderTest` AC-38 (`h-10 max-w-full object-contain` dipertahankan);
- `InstitutionHomeTest` (regex `lang="ar" dir="rtl"` cocok dengan `<span>`; `snap-x` tetap);
- `NoBuiltInContentTest`;
- `PortalFontsTest`;
- `InstitutionFooterContactTest` (regex kutipan Arab cocok);
- test Livewire PSB (tidak ada asersi kelas).

**Test baru: `tests/Feature/Http/Portal/PortalVisualIdentityTest.php`**

| AC | Nama test (usulan) |
|---|---|
| 1 | `defines navy and gold tokens from the logo without emerald` |
| 2, 6, 8 (Blade) | bagian di `PortalAccessibilityTest` AC-32 yang diperluas |
| 8 (token) | `defines shadow tokens in the theme` |
| 13 | `has no arbitrary values in portal views` |
| 7 | `renders pill buttons`, `renders every visible psb input and select as a pill` |
| 9 | `renders decorations hidden from assistive technology and small screens` |
| 10 | `loads no fonts or images from external hosts` (dataset URL), `bundles fonts at build time only` |
| 11 | `keeps the home section order` |
| 12 | `still hides empty sections on a fresh install`, `renders only the institution name heading for a new institution` |
| 14 | `aligns the arabic name with the heading in one hgroup`, `renders the hgroup without arabic markup when the name is empty` |
| 15 | `enlarges the header logo for the picture branch`, `enlarges the header logo for the desktop only branch` |
| 16 | `self hosts plus jakarta sans and noto naskh arabic` |
| 17 | `leaves the filament panel on emerald without the portal stylesheet` |

AC-3 dan AC-5 dihapus. AC-4 dan AC-20 diverifikasi visual, AC-18 dicakup test Livewire PSB lama, AC-19 diperiksa manual.

Semua test HTML memakai `portalBoot()` (sudah memalsukan Vite dan direktori build). Test PSB menyalakan `modules.psb.enabled` dan membuat gelombang aktif dengan helper PSB yang ada di `tests/Pest.php` (red-tester memilih helper yang sesuai, mis. dari `RegistrationFormTest`).

## Urutan implementasi

Setiap langkah bisa menjadi micro-commit dan menjaga test hijau.

1. `vite.config.js` dan `app.css`: font (K2), token warna (K1), bayangan (K3), kelas hiasan (K4), `.ui-input` (K8), `.portal-prose` (AC-1, 8, 16). Commit: `feat(konten): token warna dan font identitas portal`.
2. Komponen `x-ui.*`: button, badge, card, alert, empty-state, field, steps (AC-6, 7, 18).
3. Komponen `x-portal.*` dan partial nav-links, pagination (AC-7).
4. Layout publik: body, header/logo K6a, menu lipat, `<main>` `flex-1` + `bg-gray-50` non-bare (AC-15).
5. Footer navy dan `contact-list` `$onDark` (AC-6).
6. Beranda: `hgroup` (K6b), slider (K6d), `$band()`, hiasan, kartu keunggulan, skala judul (AC-9, 11–14).
7. Halaman dalam dan 404: skala `h1`, spasi, radius (AC-2).
8. Form PSB Livewire: `.ui-input`, ubin pilihan, tautan (AC-7, 18).
9. Test sumber AC-32 diperluas dan test baru hijau. Pint untuk berkas PHP test (tidak ada PHP aplikasi yang berubah).
10. `docs/design-guide.md` dan `docs/brand/referensi-gaya-coursex.md` (AC-19). Commit: `docs(konten): panduan warna dan bentuk portal`.
11. Orkestrator: satu `npm run build` lalu verifikasi visual AC-20. Bila ada perbaikan visual, kembali ke langkah terkait.

## Risiko & pertanyaan terbuka

Risiko:
- **Kontras navy–emas dan emas–warning.**
  - Emas di atas putih tidak terbaca (1.77:1). Aturan "tanpa `text-accent-*`" dijaga test sumber.
  - Emas di atas navy (10.69:1) dan navy di atas emas aman.
  - Aksen emas dan status warning (amber) bernada dekat. Pembedanya bentuk (badge emas padat ber-teks navy vs badge warning pucat), dan keduanya jarang bertemu di satu layar. Halaman `/psb/status` dicek visual khusus untuk ini.
- **Ukuran CSS dan font.**
  - Tailwind hanya menghasilkan utilitas yang dipakai, sehingga kenaikan CSS kecil (token, tiga bayangan, dua kelas hiasan, `.ui-input`).
  - Font naik dari 3 bobot Instrument Sans ke 4 bobot Plus Jakarta Sans (±80–100 KB total, 2 di-preload).
  - Noto Naskh Arabic (subset arabic, ±2 berkas) tidak di-preload dan hanya diunduh di halaman yang memuat teks Arab.
  - Untuk HP kelas bawah ini masih wajar. Alternatifnya mengurangi bobot 500 bila tidak terpakai (cek saat GREEN: pakai `font-medium` atau tidak).
- **Build font butuh jaringan.** `bunny()` mengunduh font saat `npm run build`. Build di mesin tanpa internet gagal atau jatuh ke font sistem. Paket rilis cPanel tetap aman karena `public/build` ikut paket. Ketersediaan "Plus Jakarta Sans" dan "Noto Naskh Arabic" di Bunny sudah dicek orkestrator. Rencana ini tidak menambah dependensi npm.
- **Logo melebar di 1024px.** Logo desktop lembaga pengguna (simbol dan teks) pada `md:h-14` bisa selebar ±250px. Dengan 8 item menu plus Masuk/Daftar, header di 1024px bisa sesak. Mitigasinya `md:max-w-64` dan `min-w-0` (logo menyusut), lalu dicek visual AC-20. Bila tetap sesak, turunkan ke `md:h-12`.
- **Footer navy dan `contact-list`.** Partial dipakai di dua latar. Parameter `$onDark` menambah percabangan kelas. Risiko regresi kecil, dan test kontak F036 tetap berlaku.
- **`<hgroup>`.** Semantik `hgroup` versi living standard didukung pembaca layar modern sebagai pembungkus biasa. Tidak ada risiko fungsional.
- **Konflik merge.** F036 dan F037 sudah `done` dan ada di cabang ini (commit `a1c7aa2`), sehingga tidak ada konflik dengan fitur yang sedang berjalan. F038 (`psb-info-publik`), bila dikerjakan paralel, akan menyentuh `nav-links`/layout. Merge F035 dulu, lalu F038 mengikuti token baru.

Pertanyaan terbuka (default sudah dipilih; tidak menahan RED):
- **Q1. Abu `slate` vs tetap `zinc`.** Default: slate (alasan di K1). Tidak dikunci test, sehingga bisa diganti tanpa mengubah AC.
- **Q2. Radius kartu `rounded-xl` vs 0 (persis acuan).** Default: `rounded-xl`, mengikuti "tidak kaku dan kotak-kotak".
- **Q3. Footer navy penuh.** Default: ya, sebagai satu-satunya section gelap (acuan: selang-seling putih → abu → gelap). Alternatifnya footer putih dengan pita navy hanya untuk kutipan ayat.
- **Q4. Nomor urut (01, 02, …) pada kartu keunggulan.** Default: **tidak**. Layout tetap, tanpa elemen baru.

Orkestrator (17 Okt 2026): Q1–Q4 memakai default.

## Riwayat revisi

- 2026-10-17 DRAFT awal (product-manager). Fitur sudah diklaim orkestrator (`owner`: sesi infallible-hamilton-d9c6ef); `feature_list.json` tidak diubah oleh product-manager.
- 2026-10-17 REVISED atas Review #1 (status tetap DRAFT):
  - #1 major: AC-7 mencakup semua input terlihat (text, tel, email, date, file) dan select; honeypot `tabindex="-1"`, radio, dan checkbox dikecualikan.
  - #2 major: AC-3 dan AC-5 dihapus (nomor dipertahankan sebagai catatan, aturannya pindah ke AC-20). AC-1 dibatasi ke `primary-950`, `accent-500`, dan tanpa emerald. AC-8 hanya keberadaan token `--shadow-*` dan larangan `shadow-[`. Tabel rencana test diperbarui.
  - #3 major: `x-ui.steps` tetap batang segmen, hanya warna. Lingkaran nomor dan paragraf risiko helper teks dihapus.
  - #4: nomor 01/02 pada kartu keunggulan dihapus (Q4, default tidak).
  - #5: `bg-gray-50` hanya pada `<main>` non-bare (form PSB), `<main>` tetap `flex-1`.
  - #6: rujukan K6d → AC-13, kalimat slider diperbaiki.
  - #7: AC-9 kalimat `<script` ditulis ulang, batasan `<section>` slider untuk helper `profilSlider()` dicatat di K6d.
  - #8: K6a dan AC-15 menyebut cabang `<picture>` dan logo desktop saja, keduanya diuji.
  - #9: AC-2 memakai regex AC-32 F017 ditambah `outline|decoration|divide|placeholder`, `accent-primary-*` sah.
  - #10: fokus tombol `ring-offset-2`, masuk checklist AC-20.
  - Ketersediaan font di Bunny dikonfirmasi orkestrator; risiko terkait dipangkas.
  - Arahan sesi induk: K9 baru (susunan mengikuti portal kode sekarang / mockup "Portal Publik v3 versi 5", gaya hanya dari acuan Course X, keadaan "Lembaga baru" tetap rapi). AC-12 dan AC-20 ditambah butir "Lembaga baru". Dipastikan tidak ada rujukan ke arah visual lama yang dibatalkan.

- 2026-10-17 GREEN (green-coder): Review #2 temuan 1 dan 2 diselesaikan di teks K9 dan K4 (batang langkah `bg-primary-900`).

## Review #1 — 2026-10-17

Verdict: CHANGES_REQUESTED

Yang sudah benar (diperiksa terhadap kode): dependensi F017/F036/F037 `done`; semua rasio kontras K1 cocok dengan perhitungan ulang (primary-700 12.75, primary-200 di 950 11.35, accent-400 di 950 11.96, accent-500 di putih 1.77, gray-600 di gray-50 7.24); Filament tidak memuat `app.css` (hanya `layouts/public.blade.php`); API `bunny()` memang menerima `subsets` dan `preload: boolean | [{weight}]` (`node_modules/laravel-vite-plugin/dist/fonts/types.d.ts`), dan Noto Naskh Arabic sudah dicek tersedia di Bunny; diagnosis nama Arab mengambang (blok `<p dir="rtl">` rata kanan) tepat; test lama (`InstitutionHeaderTest` memakai `profilClassPattern` berbasis lookahead, regex `lang="ar" dir="rtl"`, `snap-x`) tidak patah oleh rencana ini; cakupan kebutuhan user (warna, layout tetap, tanpa konten bawaan, tanpa animasi scroll, admin tetap) dan empat catatan F036 tercakup.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | AC-7 (dan K8) | AC-7 mengharuskan "setiap `<input type="text|date">` memuat `ui-input`", tetapi `registration-form.blade.php` baris 82 punya honeypot `<input type="text" id="website" tabindex="-1">` yang sengaja tidak bergaya. Test sesuai AC ini pasti gagal atau memaksa honeypot diberi `ui-input`. Input `tel` dan `email` (baris 168, 171) dan `file` (application-status baris 136) disebut di K8 tetapi tidak di AC-7. | Ubah AC-7: semua `input` (text, tel, email, date, file) dan `select` yang terlihat memuat `ui-input`, kecuali input ber-`tabindex="-1"` (honeypot). Radio dan checkbox dikecualikan. |
| 2 | major | Rencana test: AC-3, AC-5, AC-8 (separuh Blade), AC-1 | Permintaan user: test hanya mengunci perilaku bermakna dan tidak rapuh. (a) AC-5 memeriksa "baris memuat `heroicon` atau `aria-disabled`", tetapi `components/ui/empty-state.blade.php` baris 4 memakai `<x-dynamic-component :component="$icon" class="size-10 text-gray-400">` tanpa kata `heroicon`, jadi test gagal pada kode yang sudah benar menurut teks AC-5 sendiri; juga bergantung pada format satu baris Blade. (b) AC-3 (substring `emerald` di HTML) menduplikasi AC-2 dan bisa gagal palsu bila konten uji memuat kata itu. (c) AC-8 melarang `shadow-sm|md|lg|xl|2xl` di Blade: kunci gaya murni tanpa nilai perilaku, menahan perubahan desain berikutnya. (d) AC-1 mengunci 22 shade hex. | Pertahankan hanya penjaga yang beralasan fungsional: AC-2 (perluasan AC-32 F017), AC-6 (emas bukan warna teks, alasan kontras), AC-13 (tanpa `-[`), dan AC-1 terbatas pada `--color-primary-950 = #000a3c`, `--color-accent-500 = #f8b800`, serta tanpa `emerald`. Hapus AC-3 dan AC-5 (jadikan aturan design guide, dicek visual di AC-20). Di AC-8 pertahankan hanya keberadaan token `--shadow-*` di `@theme` dan larangan `shadow-[`; buang larangan `shadow-sm..2xl`. Perbarui tabel rencana test. |
| 3 | major | K4 "Penanda emas lain", `x-ui.steps` | Rencana mengubah indikator langkah PSB dari batang segmen (`h-1.5 rounded-full`, label tersembunyi di mobile) menjadi lingkaran bernomor. Itu perubahan bentuk dan markup yang melanggar "warnanya aja, layoutnya tetap", dan menambah digit ke teks halaman sehingga rencana sendiri mengakui bisa mematahkan helper teks (`tagihanPlainText`/`profilVisibleText`) lalu menyerahkannya ke red-tester. Risiko ini bisa dihindari sejak rencana. | Pertahankan batang segmen; ganti warna saja (selesai/aktif `bg-primary-900` atau `bg-accent-500`, berikutnya `bg-gray-200`). Hapus kalimat lingkaran bernomor dan paragraf risiko `aria-hidden` terkait. |
| 4 | minor | K4 kartu keunggulan bernomor `01, 02` | Elemen baru yang tidak ada di layout sekarang; user minta layout tetap dan tanpa konten bawaan. | Hapus, atau jadikan pertanyaan terbuka dengan default "tidak". |
| 5 | minor | K4 "Halaman non-bare (PSB)" | Hanya beranda yang `bare`; berita, galeri, video, halaman statis, kontak, dan 404 juga non-bare. Rencana hanya menyebut PSB padahal `<main bg-gray-50>` berlaku ke semua itu; prosa artikel di atas gray-50 tidak dibahas. | Tegaskan cakupan `<main>` abu muda (semua non-bare atau hanya PSB), dan pastikan `<main>` `flex-1` agar tidak ada celah putih sebelum footer navy. |
| 6 | minor | K6d / AC-13 | K6d merujuk "AC-12" untuk larangan nilai arbitrer; seharusnya AC-13. Kalimat "di desktop dua foto lebih sedikit tampil" tidak jelas: `lg:w-[45%]` ke `lg:w-5/12` (41.7%) justru memperlihatkan sedikit lebih banyak foto ketiga. | Perbaiki rujukan dan kalimat. |
| 7 | minor | AC-9 | Kalimat "jumlah `<script` ... sama dengan dan tanpa album slider" kurang jelas. Helper lama `profilSlider()` (`InstitutionHomeTest.php:17`) mengambil dari `<div role="region">` sampai `</section>` pertama, jadi slider harus tetap di dalam `<section>`. | Tulis ulang: jumlah `<script` sama dengan baseline tanpa slider. Catat batasan struktur `<section>` slider untuk green-coder. |
| 8 | minor | K6a, AC-15 | Cabang `<picture>` memakai satu `<img>` untuk kedua sumber; rencana bilang "semua cabang yang memakai gambar" tanpa membedakan cabang mobile-only (`md:hidden`, tidak perlu berubah). AC-15 hanya menguji satu kombinasi. | Sebutkan cabang yang diubah (picture dan desktop-only) dan uji keduanya. |
| 9 | minor | AC-2 | Prefiks "`accent` diikuti `-` dan nama palet" ambigu; utilitas `accent-primary-600` (accent-color radio/checkbox di form PSB) sah dan tetap ada. | Pakai regex AC-32 F017 ditambah prefiks `outline|decoration|divide|placeholder`; kecualikan `accent-primary-*`. |
| 10 | minor | K3/K8 fokus | Fokus tombol utama `primary-900` tidak dijelaskan; cincin `primary-500` menempel pada tombol navy hanya ±2.8:1. | Tambahkan `ring-offset-2` pada tombol dan masukkan ke checklist AC-20. |

Ringkasan: 0 blocker, 3 major, 7 minor. Revisi harus menyelesaikan temuan 1 sampai 3; minor boleh dicatat saja.

## Review #2 — 2026-10-17

Verdict: APPROVED

Pemeriksaan penanganan Review #1:
- #1 (major, AC-7): selesai. Semua `select` dan `input` terlihat (text, tel, email, date, file) wajib `ui-input`; honeypot `tabindex="-1"`, radio, checkbox dikecualikan. Cocok dengan `registration-form.blade.php` baris 82.
- #2 (major, test rapuh): selesai. AC-3 dan AC-5 dihapus (aturannya pindah ke AC-20), AC-1 hanya mengunci `primary-950`, `accent-500`, dan tanpa emerald, AC-8 hanya token `--shadow-*` dan `shadow-[`. Tersisa penjaga sumber yang beralasan fungsional (AC-2, AC-6, AC-13) dan test perilaku (AC-7, 9, 10, 11, 12, 14, 15, 17).
- #3 (major, steps): selesai. Markup batang segmen tetap, hanya warna; AC-18 menegaskan tanpa angka baru.
- #4 sampai #10 (minor): semuanya ditangani (nomor 01/02 jadi Q4 default tidak; `<main>` `flex-1` dan `bg-gray-50` hanya non-bare, dan klaim itu benar karena semua view portal memanggil layout dengan `:bare="true"` sehingga hanya halaman PSB yang non-bare; rujukan AC-13; batasan `<section>` slider untuk `profilSlider()`; dua cabang logo diuji; regex AC-2 dan `accent-primary-*`; `ring-offset-2`).

Arahan sesi induk: K9 selaras dengan kode sekarang; tidak ada rujukan tegel/girih di rencana maupun `docs/brand/referensi-gaya-coursex.md`; tidak ada section baru; AC-12 dan AC-20 mencakup keadaan "Lembaga baru".

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | K9 footer | Klaim footer "nama lembaga dan baris hak cipta saja" hanya benar bila modul psb dan dokumen mati. `footer.blade.php` juga merender tautan "Status pendaftaran" dan "Verifikasi surat" bila modulnya aktif, dan tautan cepat dari `footer.links`. | Ubah kalimat menjadi "tanpa kutipan, deskripsi, kontak, atau sosial; tautan hanya bila modulnya aktif". Jangan menambah asersi footer di AC-12. |
| 2 | minor | K4 steps | Batang langkah selesai/aktif `bg-accent-500` (1.77:1 di putih) melawan `bg-gray-200` hampir tak terbedakan untuk pengguna low-vision; status memang juga disampaikan lewat `aria-current` dan teks `step_counter`, jadi bukan pelanggaran fungsional. | Pertimbangkan `bg-primary-900` untuk selesai/aktif (emas tetap untuk badge dan garis). Keputusan boleh diambil saat GREEN. |
| 3 | minor | AC-12 | Butir baru "hanya `<h1>`, tanpa `<h2>` di `<main>`" bergantung pada `portalBoot(freshPages: true)` tanpa berita/prestasi/album. Wajar, tetapi test harus menyatakan kondisinya. | Red-tester mencatat kondisi data kosong eksplisit di test. |

Ringkasan: 0 blocker, 0 major, 3 minor. Rencana siap lanjut ke RED.
