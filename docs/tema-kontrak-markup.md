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
| `footer_widgets` | - | `widget`, `heading` | akar `div` grid widget (mode ringkas: `div` nama dan tautan); `widget` = `div` per widget; `heading` = `h2` judul widget (*opsional* per widget) |
| `copyright` | - | - | `p` hak cipta |

## Beranda

| Komponen | Varian | Bagian | Elemen |
|---|---|---|---|
| `hero` | `center`, `split` | `eyebrow`, `title`, `text`, `actions`, `media`, `badge`, `content`, `hgroup`, `slider`, `slide` | akar `section`; `eyebrow` = `p`; `title` = `h1`/`h2`; `text` = `p`; `actions` = `div` tombol; `media` = `div` pembungkus gambar atau slider album; `badge` = `span` pil pendaftaran (hanya bila gelombang PSB buka); `content` = `div` pembungkus teks (anak langsung akar `center`, atau anak grid `split`); `hgroup` = `hgroup` eyebrow, judul, teks; `slider` = `div[role=region]` slider album; `slide` = `img` tiap foto (gambar tunggal juga `slide`, tanpa `slider`) |
| `text_image` | - | `media`, `heading`, `body`, `link` | akar `section`; `media` = `img`; `heading` = `h2`; `body` = `div` isi; `link` = `a` tombol |
| `feature_grid` | `cards`, `numbered` | `heading`, `item`, `item-title`, `item-text`, `number` | akar `section`; `item` = `div` (cards) atau `li` (numbered); `number` = `span` nomor (hanya `numbered`) |
| `quote` | `plain`, `framed` | `text`, `source`, *opsional:* `translation` | akar `section`; `text` = `p` kutipan; `source` = `figcaption`; `translation` = `p` terjemahan |
| `cta` | `band`, `card` | `heading`, `actions`, *opsional:* `eyebrow`, `text` | akar `section`; `heading` = `h2`; `actions` = `div` tombol |
| `video` | - | `heading`, `player` | akar `section`; `heading` = `h2`; `player` = `div` pemutar (`iframe` ditambah tautan YouTube) |
| `feed_list` | `cards`, `list`, `magazine` | `head`, `heading`, `see-all`, `list`, `entry`, `lead` | akar `section`; `head` = `div` judul + tautan; `heading` = `h2`; `see-all` = `a` "Lihat semua"; `list` = `ul` (varian `list` dan `magazine`); `entry` = `li` (varian `list` dan `magazine`); `lead` = `div` item pertama (hanya `magazine`). Kartu di varian `cards` adalah komponen inti bersama dan tidak punya kait: tata lewat `[data-pt="feed_list"]` dan tag |
| `blocks` | `default`, `centered` | `hero`, `text-image`, `features`, `feed`, `quote`, `video`, `cta` | tanpa pembungkus: tiap seksi `section` membawa `data-pt="blocks"`, `data-pt-variant`, dan `data-pt-part` = jenis seksi. Isi seksi tidak punya kait lanjutan |

`blocks` adalah preset lama dan wajib menjadi satu-satunya node beranda; komponen lain boleh berada di posisi mana pun dan berulang.

## Contoh

```css
body[data-pt-theme="contoh"] [data-pt="hero"] [data-pt-part="title"] {
    letter-spacing: -0.02em;
}

body[data-pt-theme="contoh"] [data-pt="nav_menu"] [data-pt-part="link"][aria-current="page"] {
    text-decoration: underline;
}
```
