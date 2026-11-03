# F107 Empat tema resmi memakai kait F106 dan bingkai foto hero diperbaiki

Issue: #146. Jalur: cepat (CSS dan dokumen di repo `pondok-themes-work`; di repo ini hanya rencana dan `feature_list.json`). Bergantung pada F106 (#144, PR #145, sudah di `dev`).

## Tujuan

Mengganti setiap selektor posisional di `theme.css` Mihrab, Cahaya, Tegas, dan Kobalt dengan kait F106 tanpa mengubah karakter visual yang sudah disetujui, memperbaiki bingkai foto hero yang kosong (Tegas 63 %, Mihrab 44 % di 1440px), lalu mengukur ulang keempat tema di 360, 768, 1024, dan 1440px.

## Yang diubah

- `/Users/hadie/Dev/pondok-themes-work/{mihrab,cahaya,tegas,kobalt}/theme.css` dan `theme.json` (versi naik: mihrab 1.0.7, cahaya 1.0.3, tegas 1.0.2, kobalt 1.0.2).
- `PANDUAN-MEMBUAT-TEMA.md`, `CELAH-SISTEM.md` (butir 1–6 dan 10 selesai di F106), `LAPORAN-F107.md` baru.
- Repo ini: `docs/plans/F107-plan.md` dan status `feature_list.json`.

## Pemetaan selektor lama ke kait F106

| Lama (posisional) | Baru |
|---|---|
| `[data-pt="hero"] > div:first-child` | `[data-pt="hero"] [data-pt-part="content"]` |
| `[data-pt="hero"] > div:first-child > span:first-child` | `[data-pt="hero"] [data-pt-part="badge"]` |
| `[data-pt="hero"] hgroup` | `[data-pt="hero"] [data-pt-part="hgroup"]` |
| `[data-pt="hero"] [data-pt-part="media"] > div` | `[data-pt="hero"] [data-pt-part="slider"]` |
| `[data-pt="hero"] [data-pt-part="media"] img` | `[data-pt="hero"] [data-pt-part="slide"]` |
| `[data-pt="nav_menu"] details > div`, `[data-pt-part="mobile"] > nav` | `[data-pt="nav_menu"] [data-pt-part="panel"]` |
| `[data-pt="nav_menu"][data-pt-part="mobile"] p` | `[data-pt="nav_menu"] [data-pt-part="group-label"]` |
| `[data-pt="nav_menu"] summary` | `[data-pt="nav_menu"] [data-pt-part="group"] > summary` (desktop tidak punya kait terpisah, sesuai kontrak) |
| `[data-pt-part="actions"] a`, `a:nth-child(2)` | `[data-pt="button"][data-pt-variant="primary"/"inverse"]` dan `"secondary"/"inverse-secondary"` |
| `main > div > section > div > a` (Daftar sekarang PSB) | `[data-pt="page"] [data-pt="button"]` |
| `main div > article` dan anak posisinya | `[data-pt="card"]` + `media`, `meta`, `badge`, `date`, `title`, `summary` |
| `main > div > h1`, `main > article > h1` | `[data-pt="page"] [data-pt-part="page-title"]` |
| `main nav ol …` (remah) | `[data-pt="page"] [data-pt-part="breadcrumb"] …` |
| `main nav[aria-label] > a/span` (chip dan paginasi) | `[data-pt-part="filters"] > a`, `[data-pt-part="pagination"] > a/span` |
| `main > div > div > figure` | `[data-pt="page"] [data-pt-part="figure"]` |
| `main section …` (PSB) | `[data-pt="page"] [data-pt-part="section"] …` |
| `main > div > div a/h2` (prosa) | `[data-pt="page"] [data-pt-part="prose"] …` |

Selektor yang tetap posisional (diberi komentar alasan di CSS): pembungkus isi kartu (`[data-pt="card"] > div` tanpa kait), pembungkus grid `text_image`/`cta`, batang hias `quote figure > div`, isi widget footer ringkas, serta `p`/`img`/`a` langsung di bawah `[data-pt="page"]` (kategori album, sampul berita, tautan kembali) yang tidak punya kait. Semua sudah berakar pada kait komponen, bukan pada `main`.

## Kriteria penerimaan (dari issue)

1. Tidak ada selektor posisional yang tersisa tanpa alasan tertulis di komentar CSS.
2. Bingkai foto hero Mihrab dan Tegas kosong tidak lebih dari 15 % di 1440px; foto tetap terlihat tanpa gulir di 360px (visual utama >= 45 %).
3. `audit-kontras.js` `__audit(true)`: 0 kegagalan kontras dan 0 overflow di 360, 768, 1024, 1440px untuk keempat tema (beranda dan halaman dalam).
4. Keempat tema lolos `ValidateThemePackage` dan `ValidateThemeCss` lewat `install-theme.sh`.
5. Karakter visual tiap tema tidak berubah dibanding `bukti/tangkapan/`; `theme.template` dipulihkan ke nilai semula.
