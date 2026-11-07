# F109 Empat tema resmi memakai kait F108

Issue: #150. Jalur: cepat (CSS dan dokumen di repo `pondok-themes-work`; di repo ini hanya rencana dan `feature_list.json`). Bergantung pada F108 (#148, PR #149, sudah di `dev`). Pola acuan: F107 (`docs/plans/F107-plan.md`).

## Tujuan

Mengganti selektor posisional sisa di `theme.css` Mihrab, Cahaya, Tegas, dan Kobalt (butir 12 `CELAH-SISTEM.md`) dengan kait F108, tanpa mengubah karakter visual, lalu mengukur ulang keempat tema di 360, 768, 1024, dan 1440px. Tema tidak ditandatangani dengan kunci asli dan tidak diterbitkan.

## Yang diubah

- `/Users/hadie/Dev/pondok-themes-work/{mihrab,cahaya,tegas,kobalt}/theme.css` dan `theme.json` (versi naik: mihrab 1.0.9, cahaya 1.0.4, tegas 1.0.4, kobalt 1.0.3).
- `PANDUAN-MEMBUAT-TEMA.md`, `CELAH-SISTEM.md` (butir 12 selesai), `LAPORAN-F109.md` baru.
- Repo ini: `docs/plans/F109-plan.md` dan status `feature_list.json` (oleh orkestrator).

## Pemetaan selektor lama ke kait F108

| Lama (posisional) | Baru |
|---|---|
| `[data-pt="card"] > div:last-child` | `[data-pt="card"] [data-pt-part="body"]` |
| `[data-pt="text_image"] > div` | `[data-pt="text_image"] [data-pt-part="inner"]` |
| `[data-pt="text_image"] > div > div` | `[data-pt="text_image"] [data-pt-part="content"]` |
| `[data-pt="cta"] > div` | `[data-pt="cta"] [data-pt-part="inner"]` |
| `[data-pt="quote"] figure`, `figure > div` | `[data-pt="quote"] [data-pt-part="figure"]`, `[data-pt-part="mark"]` |
| `footer [data-pt-part="widget"] > p:first-child` / `> span` | `[data-pt="footer_widgets"] [data-pt-part="name"]` / `[data-pt-part="logo"]` |
| `[data-pt="page"] > div > a`, `> a` | `[data-pt="page"] [data-pt-part="meta"] a`, `[data-pt-part="back"]` |
| `[data-pt="page"] > p` | `[data-pt-part="meta"]` (album) dan `[data-pt-part="lead"]` |
| `[data-pt="page"] > img` | `[data-pt="page"] [data-pt-part="cover"]` |
| `[data-pt="page"] > section` | `[data-pt="page"] [data-pt-part="contact"]` |

`name` dibatasi di bawah `footer_widgets` karena `brand` di footer juga membawa `name`; `meta` kartu dibatasi di bawah kartu. Selektor yang tetap posisional (anak `figure`/`:has(> ul)` di halaman PSB, `dl > div`, `li > div > p`, reset `> *`, `filters/pagination > a/span`) diberi komentar alasan.

## Kriteria penerimaan

1. Tidak ada selektor posisional sisa tanpa alasan tertulis di komentar CSS; semua elemen butir 12 memakai kait F108.
2. Gaya terhitung (computed style) elemen yang disentuh tidak berubah dibanding versi sebelumnya (selisih dicatat bila ada, beserta alasannya).
3. `audit-kontras.js` `__audit(true)`: 0 kontras gagal dan 0 overflow di 360, 768, 1024, 1440px untuk 7 halaman daftar issue plus detail berita, keempat tema.
4. Keempat tema lolos `ValidateThemePackage` dan `ValidateThemeCss` lewat `install-theme.sh` (pasangan kunci sekali pakai).
5. Beranda, detail berita, detail album, dan `/kontak` dilihat di 360 dan 1440px per tema; karakter visual sama dengan `bukti/tangkapan/`.
6. `theme.template` dan viewport dipulihkan ke semula (`kobalt`, desktop).
