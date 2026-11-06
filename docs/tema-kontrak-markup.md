# Kontrak markup tema ber-CSS

Dokumen untuk penerbit tema yang menyertakan `theme.css` (F105). Tema ber-CSS menata portal lewat **kait stabil** di markup, bukan lewat kelas utilitas Tailwind inti (kelas itu bisa berubah di rilis inti mana pun). Kait hanya dicetak bila tema aktif ber-CSS; tema tanpa CSS menghasilkan HTML yang sama persis seperti sebelumnya.

## Aturan

1. **Targetkan hanya kait `data-pt*` dan atribut ARIA.** Jangan menarget kelas utilitas (`.flex`, `.text-lg`, dst.) dan jangan bergantung pada urutan atau kedalaman elemen di luar tabel di bawah.
2. **Selektor wajib berawalan `body[data-pt-theme="<slug>"]`** (slug tema Anda). `<body>` membawa `data-pt-theme` hanya saat tema ber-CSS aktif. Selektor tanpa awalan ini ditolak saat pemasangan.
3. **Tanpa JavaScript.** Perilaku interaktif (menu lipat, slider hero) berasal dari HTML inti; tema hanya menata.
4. **Tanpa jadwal shalat.** Kontrak ini tidak mencakup komponen jadwal shalat; tema tidak boleh mengandalkannya.
5. Status dibaca dari atribut ARIA: `aria-current="page"` pada tautan menu aktif, `aria-current="true"` pada grup menu yang memuat halaman aktif, dan `[open]` pada `<details>` menu.

## Kait

| Atribut | Arti | Contoh selektor |
|---|---|---|
| `data-pt="<komponen>"` | akar elemen komponen (satu komponen bisa muncul berkali-kali) | `body[data-pt-theme="x"] [data-pt="hero"]` |
| `data-pt-variant="<varian>"` | varian komponen yang dirender (selalu tercetak, juga untuk varian bawaan) | `[data-pt="hero"][data-pt-variant="split"]` |
| `data-pt-part="<bagian>"` | bagian bernama di dalam komponen | `[data-pt="hero"] [data-pt-part="title"]` |

Akar `<header>` dan `<footer>` ditata lewat nama tag. Kait di partial bersama (`brand`, `nav_menu`, `footer_widgets`) ikut tercetak juga bila tema tidak mendefinisikan `header`/`footer` sehingga chrome inti dipakai.

Daftar komponen dan bagian yang **selalu ada** pada konten lengkap bersumber dari `ThemeComponents::PARTS` dan dijaga test. Bagian bertanda *opsional* hanya ada bila datanya ada.

## Tata letak (header, footer, beranda)

| Komponen | Varian | Bagian | Elemen |
|---|---|---|---|
| `container` | - | `inner` | `div` akar (latar, border); `inner` = `div` dalam yang menyusun anak (padding, gap, lebar) |
| `columns` | - | `grid`, `cell` | akar = `div` pembungkus (level atas beranda) atau grid itu sendiri; `grid` = `div` grid; `cell` = `div` per anak |
| `divider` | - | `line`, `diamond` | akar `div` (`aria-hidden`); `line` = `span` garis (dua), `diamond` = `span` belah ketupat |

## Header dan footer

| Komponen | Varian | Bagian | Elemen |
|---|---|---|---|
| `brand` | - | `name` | akar `a` ke beranda; `name` = `span` nama lembaga (bisa `sr-only` bila logo ada) |
| `nav_menu` | - | `desktop`, `mobile`, `toggle`, `link`, `panel`, `group`, `group-label`, *opsional:* `button` | dua akar: `nav` (desktop, mulai `xl`) dengan `data-pt-part="desktop"` dan `details` (menu lipat) dengan `mobile`; `toggle` = `summary` menu lipat; `link` = `a` item menu (juga tautan Masuk); `button` = `a` tombol header; `group` = `details` submenu atau pilihan Masuk (desktop) dan `div` pembungkus grup (seluler); `panel` = `div` daftar tautan dalam `group` (desktop) dan `nav` di dalam `details` seluler; `group-label` = `p` judul grup (hanya seluler; desktop pakai `[data-pt-part="group"] > summary`) |
| `footer_widgets` | - | `widget`, `heading`, *opsional:* `name`, `logo` | akar `div` grid widget (mode ringkas: `div` nama dan tautan); `widget` = `div` per widget; `heading` = `h2` judul widget (*opsional* per widget); `name` = `p` nama lembaga (mode ringkas, dan widget Teks bila diminta); `logo` = `span` pembungkus logo (hanya widget Teks mode penuh, bila logo ada) |
| `copyright` | - | - | `p` hak cipta |

## Beranda

| Komponen | Varian | Bagian | Elemen |
|---|---|---|---|
| `hero` | `center`, `split` | `eyebrow`, `title`, `text`, `actions`, `media`, `badge`, `content`, `hgroup`, `slider`, `slide` | akar `section`; `eyebrow` = `p`; `title` = `h1`/`h2`; `text` = `p`; `actions` = `div` tombol; `media` = `div` pembungkus gambar atau slider album; `badge` = `span` pil pendaftaran (hanya bila gelombang PSB buka); `content` = `div` pembungkus teks (anak langsung akar `center`, atau anak grid `split`); `hgroup` = `hgroup` eyebrow, judul, teks; `slider` = `div[role=region]` slider album; `slide` = `img` tiap foto (gambar tunggal juga `slide`, tanpa `slider`) |
| `text_image` | - | `media`, `heading`, `body`, `link`, `inner`, `content` | akar `section`; `inner` = `div` pembungkus grid (anak langsung akar); `media` = `img`; `content` = `div` pembungkus teks (anak langsung `inner`, bersaudara dengan `media`; tanpa gambar tetap ada); `heading` = `h2`; `body` = `div` isi; `link` = `a` tombol |
| `feature_grid` | `cards`, `numbered` | `heading`, `item`, `item-title`, `item-text`, `number` | akar `section`; `item` = `div` (cards) atau `li` (numbered); `number` = `span` nomor (hanya `numbered`) |
| `quote` | `plain`, `framed` | `text`, `source`, `figure`, *opsional:* `translation`, `mark` | akar `section`; `figure` = `figure` pembungkus kutipan; `text` = `p` kutipan; `source` = `figcaption`; `translation` = `p` terjemahan; `mark` = `div` garis hias (hanya `framed`) |
| `cta` | `band`, `card` | `heading`, `actions`, `inner`, *opsional:* `eyebrow`, `text` | akar `section`; `inner` = `div` pembungkus isi (anak langsung akar, satu tingkat); `heading` = `h2`; `actions` = `div` tombol |
| `video` | - | `heading`, `player` | akar `section`; `heading` = `h2`; `player` = `div` pemutar (`iframe` ditambah tautan YouTube) |
| `feed_list` | `cards`, `list`, `magazine` | `head`, `heading`, `see-all`, `list`, `entry`, `lead` | akar `section`; `head` = `div` judul + tautan; `heading` = `h2`; `see-all` = `a` "Lihat semua"; `list` = `ul` (varian `list` dan `magazine`); `entry` = `li` (varian `list` dan `magazine`); `lead` = `div` item pertama (hanya `magazine`). Kartu di varian `cards` membawa kait komponen `card` (lihat bagian Komponen bersama) |
| `blocks` | `default`, `centered` | `hero`, `text-image`, `features`, `feed`, `quote`, `video`, `cta` | tanpa pembungkus: tiap seksi `section` membawa `data-pt="blocks"`, `data-pt-variant`, dan `data-pt-part` = jenis seksi. Isi seksi tidak punya kait lanjutan |

## Komponen bersama (kartu dan tombol)

Kartu dan tombol dipakai di banyak tempat, tetapi kaitnya hanya tercetak pada halaman portal publik saat tema ber-CSS aktif.

| Komponen | Varian | Bagian | Elemen |
|---|---|---|---|
| `card` | `post`, `album`, `video` | `media`, `meta`, `badge`, `date`, `title`, `summary`, `body` | akar `article` (`post`, `album`; `video` di daftar video) atau `div` (`video` di beranda); `media` = `img` atau `div` placeholder (`div` pemutar untuk `video`); `meta` = `div` badge dan tanggal (`post`) atau `p` kategori dan jumlah foto (`album`); `badge` = kategori, hanya bila ada; `date` = `time` (hanya `post`, bila terbit); `title` = `h2`/`h3`; `summary` = `p` ringkasan (`post`) atau deskripsi (`video`, hanya bila ada); `body` = `div` pembungkus judul, meta, dan ringkasan di bawah media (hanya `post` dan `album`; kartu `video` tanpa pembungkus) |
| `button` | `primary`, `secondary`, `inverse`, `inverse-secondary` | - | akar `a`/`button` tombol di hero, ajakan (CTA), preset `blocks`, dan "Daftar sekarang" di info PSB; varian = varian tombol yang dicetak |

Tombol header `nav_menu` (`data-pt-part="button"`) berbeda dari komponen `button` ini.

## Halaman dalam

Tujuh halaman dalam (`/berita`, detail berita, `/galeri`, detail album, `/video`, halaman statis, `/info-psb`) memakai satu komponen `page` tanpa varian; halaman dibedakan lewat bagian yang ada.

| Komponen | Varian | Bagian | Elemen |
|---|---|---|---|
| `page` | - | `page-title`, `meta`, `back`, *opsional:* `breadcrumb`, `filters`, `pagination`, `prose`, `figure`, `section`, `lead`, `cover`, `contact` | akar = pembungkus isi (`div`, atau `article` di detail berita); `page-title` = `h1`; `breadcrumb` = `nav` remah (tidak ada di `/video`); `filters` = `nav` chip filter (jumlah bisa lebih dari satu); `pagination` = `nav` paginasi (hanya bila lebih dari satu halaman); `prose` = `div` isi artikel atau halaman; `figure` = `figure` foto di detail album; `section` = `section` gelombang, syarat, biaya, dan kontak di `/info-psb`; `meta` = `div` baris tanggal dan kategori (detail berita) atau `p` kategori album (detail album, bila ada kategori), jadi elemennya bervariasi; `back` = `a` tautan kembali (detail berita dan album); `lead` = `p` kutipan berita atau deskripsi album; `cover` = `img` sampul berita; `contact` = `section` kartu kontak (halaman kontak) |

`blocks` adalah preset lama dan wajib menjadi satu-satunya node beranda; komponen lain boleh berada di posisi mana pun dan berulang.

## Catatan penataan

- **Urutan hero.** `center` = `section` flex kolom dengan anak `content` lalu `media`. `split` = `section` berisi satu `div` grid (hanya bila ada media) dengan anak `content` dan `media`; `media` memakai `order-first` di layar kecil (tampil di atas teks) dan kembali ke urutan DOM mulai `lg` (`lg:order-none`), sehingga di desktop `content` di kiri dan `media` di kanan. Tema boleh mengubah urutan lewat properti `order` pada anak itu, mis. `[data-pt="hero"] [data-pt-part="media"] { order:-1 }`.
- **Spesifisitas per komponen.** Kartu dan halaman bersarang (kartu muncul di `/berita`, `/galeri`, `/video`), jadi selektor wajib menyebut komponennya: `[data-pt="card"] [data-pt-part="title"]` untuk judul kartu dan `[data-pt="page"] [data-pt-part="page-title"]` untuk judul halaman. Nama bagian `page-title` sengaja berbeda dari `title` kartu.
- **Dua "button".** `nav_menu` bagian `button` = tautan tombol di header. Komponen `button` = tombol `x-ui.button` di hero, ajakan (CTA), preset `blocks`, dan "Daftar sekarang" di info PSB, dengan `data-pt-variant` sesuai varian tombolnya. Tombol di halaman lain tidak membawa kait.
- **Judul grup menu.** Di seluler judul grup memakai bagian `group-label`. Di desktop tidak ada kait terpisah: pakai `[data-pt-part="group"] > summary`.
- **`feed_list` dan preset `blocks`.** Kartu di varian `cards` dan `magazine` (item pertama) membawa kait komponen `card`; baris di varian `list` dan item sisa `magazine` adalah `entry`. Kartu berita dan album di preset `blocks` juga membawa kait `card`; kartu video di preset `blocks` dan isi seksi lainnya tidak.

## Contoh

```css
body[data-pt-theme="contoh"] [data-pt="hero"] [data-pt-part="title"] {
    letter-spacing: -0.02em;
}

body[data-pt-theme="contoh"] [data-pt="nav_menu"] [data-pt-part="link"][aria-current="page"] {
    text-decoration: underline;
}
```
