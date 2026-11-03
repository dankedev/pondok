# Panduan rilis Pondok (untuk pengelola)

Panduan membuat paket rilis zip yang diunggah sekolah ke hosting cPanel. Panduan pemasangan untuk sekolah ada di [docs/instalasi.md](instalasi.md).

## Prasyarat mesin build

- PHP 8.3 atau lebih baru dengan ekstensi `zip` (CLI).
- Composer 2.
- Node.js dan pnpm (hanya di mesin build; server sekolah tidak butuh Node).
- git, dengan repo dalam keadaan bersih (semua perubahan sudah di-commit).

`composer.json` mengunci platform PHP 8.3, jadi dependency yang terpasang tetap cocok untuk server PHP 8.3 walau mesin build memakai versi PHP lebih baru.

## Membuat paket

```
php artisan pondok:release --release-version=1.2.0
```

Langkah yang dijalankan:

1. Menolak berjalan bila `APP_ENV=production`, atau bila ada perubahan yang belum di-commit (kecuali `--allow-dirty`; hanya commit `HEAD` yang dipaketkan).
2. `git archive HEAD` ke folder staging sementara.
3. `composer install --no-dev --optimize-autoloader` di staging (skrip Composer menerbitkan aset Filament).
4. `pnpm install --frozen-lockfile --ignore-workspace` lalu `pnpm run build` (aset Vite ke `public/build`).
5. Menulis berkas `VERSION`.
6. Memeriksa kelengkapan paket. Paket ditolak bila tidak ada `public/build/manifest.json`, `vendor/autoload.php`, aset Filament, `.htaccess` root, `.env.production.example`, atau `docs/instalasi.md`, atau bila ada paket pengembangan (`vendor/pestphp/`, dsb.) maupun berkas `.env` rahasia.
7. Menulis `pondok-<versi>.zip` (berakar folder `pondok/`) dan `pondok-<versi>.zip.sha256` ke `storage/app/releases`.

Opsi:

| Opsi | Fungsi |
|---|---|
| `--release-version=` | Versi paket. Bawaan: hasil `git describe --tags --always`. |
| `--output=` | Folder hasil. Bawaan `storage/app/releases`. |
| `--allow-dirty` | Lanjut walau ada perubahan belum di-commit (tidak ikut paket). |
| `--keep-staging` | Simpan folder staging untuk diperiksa. |

## Isi paket

Ikut: kode aplikasi, `vendor/` tanpa paket dev, `public/build`, aset Filament (`public/css|js|fonts/filament`), `.env.example`, `.env.production.example`, `.htaccess` root penolak akses, kerangka folder `storage` dan `bootstrap/cache`, `docs/` (kecuali `docs/plans` dan `docs/handoffs`), dan `VERSION`.

Tidak ikut: `.env` dan varian rahasianya, `.git`, `node_modules`, `tests`, berkas kerja agent (`.claude`, `CLAUDE.md`, `AGENTS.md`, `boost.json`, `feature_list.json`), log, cache, sesi, view terkompilasi, unggahan, `database/database.sqlite`, dan `public/storage`.

Periksa isi zip bila perlu:

```
unzip -l storage/app/releases/pondok-1.2.0.zip | less
```

## Tema portal (skema v1)

Tema portal adalah satu berkas `resources/themes/<slug>/theme.json` (data saja, tanpa kode, tanpa aset; `slug` harus sama dengan nama folder). Empat tema resmi: `default`, `centered`, `majalah`, `mushaf`. Tema dipilih di Situs > Tampilan; tema yang gagal validasi tampil nonaktif dan portal memakai chrome inti.

Isi manifes v1: `schema_version` (1), `version`, `slug`, `name`, `description`, `min_core`, serta `header`, `footer`, dan `home.layout` (daftar node `{component, variant?, style?, children?}` berurutan bebas: komponen boleh di posisi mana pun, berulang, dan bersarang di `container`/`columns`) yang disusun dari 15 komponen tetap dengan nilai gaya dari enum (padding, kolom, tone, dsb.). Node `blocks` menampilkan semua blok beranda dari admin sesuai urutannya (dipakai `default` dan `centered`); node isi (hero, feed_list, dst.) menampilkan blok aktif pertama bertipe itu. Editor visual dan susunan per situs di database adalah pekerjaan lanjutan dengan skema yang sama. Batas: kedalaman pohon 6, 150 node, 64 KB. Nilai di luar enum dan kunci tak dikenal ditolak; kunci `assets` hanya dikenal inti 1.2.0 ke atas (lihat "Menyusun, menguji, dan menandatangani paket tema ber-CSS").

### Warna dan huruf tema (tambahan v1, inti 1.1.0)

Tiga kunci puncak opsional berupa enum; tema hanya menyebut nama, tanpa hex, nama font, CSS, atau berkas:

| Kunci | Nilai | Efek |
|---|---|---|
| `palette` | `navy` (bawaan, tanpa deklarasi), `hijau` | warna utama (`primary-50..950` dan token merek) dari satu hex primer di `ThemePalette::PALETTES`; aksen tetap emas bawaan |
| `surface` | `putih` (bawaan), `krem` | menimpa `--color-gray-50` dan `--color-gray-100` (band `tone: muted`/`auto`, kartu); `--color-white` tidak disentuh |
| `font` | `jakarta`, `lora`, `nunito` | pasangan font bawaan inti (`GetPortalTheme::FONTS`); font Arab tetap Noto Naskh |

Prioritas: nilai admin di Situs > Tampilan yang terisi (`theme.primary`, `theme.accent`, `theme.font`) menang atas tema; nilai kosong mengikuti tema; aksen hanya dari admin; radius tidak dipengaruhi tema. Guard kontras 4,5:1 dan cek manifest font tetap berlaku, jadi warna/font yang tak lolos jatuh ke bawaan. Nilai di luar enum membuat tema ditolak.

Kebijakan versi: kunci ini tambahan kompatibel, `schema_version` tetap 1 dan `CORE_VERSION` naik ke `1.1.0`. Tema yang memakainya sebaiknya menulis `min_core` `1.1.0`; inti 1.0.0 menolaknya (kunci tak dikenal) dan memakai chrome inti. Tema v1 lama tetap valid tanpa diubah.

### Panduan `home.layout` (skema v1)

Node: `{component, variant?, style?, children?}`. Komponen tata letak: `container` (susun vertikal; di header berderet), `columns` (anak = sel, 2 sampai 4 anak), `divider` (hiasan emas, satu bentuk). Komponen isi mengikat blok beranda aktif pertama bertipe sama: `hero`, `text_image`, `feature_grid` (blok keunggulan), `quote`, `cta`, `video`, `feed_list` (blok konten terbaru). `blocks` adalah preset lama: wajib satu-satunya node, tanpa `style`, varian `default` atau `centered`.

Varian (yang pertama = bawaan bila `variant` dihilangkan):

| Komponen | Varian |
|---|---|
| `hero` | `center`, `split` |
| `feed_list` | `cards`, `list`, `magazine` |
| `quote` | `plain`, `framed` |
| `cta` | `band`, `card` |
| `feature_grid` | `cards`, `numbered` |
| `blocks` | `default`, `centered` |

Aturan perilaku:

- **Node kosong.** Node tampil bila ada anak isi yang tampil. Tanpa blok bertipe itu (atau blok disembunyikan admin), node beserta pembungkusnya tidak dirender. Sel `columns` kosong dibuang dan jumlah kolom = `min(style.columns, sel tampil)`. `divider` hanya tampil bila bersebelahan dengan konten yang tampil.
- **Judul.** Hero pertama yang tampil memakai `h1`, berikutnya `h2`. Bila node bertajuk (text_image, feature_grid, cta, video, feed_list) tampil sebelum hero, hero tetap `h2` dan `h1` sr-only nama lembaga ditaruh di awal `<main>`. Tanpa hero, tampil `h1` sr-only (atau pita nama lembaga pada preset `blocks`).
- **`tone: auto`.** Selang-seling `bg-gray-50` dan `bg-white` hanya untuk node level atas `home.layout`, dihitung menurut urutan tampil (node kosong dan `divider` tidak menggeser). Tidak sah di node bersarang, header, atau footer.
- **Gaya.** Hanya enum `ThemeStyleMap` (tone, padding, gap, align, width, radius, shadow, border, columns); responsif `{base, md, lg}` hanya untuk `columns` dan `padding`. `style.columns` hanya pada `columns` (nilai 1 sampai 4, tidak melebihi jumlah anak). Node bersarang tidak mendapat padding atau gutter bawaan.
- **Teks.** Tema hanya membawa struktur. Seluruh teks tampil berasal dari data sekolah (blok beranda, navigasi, footer); `name` dan `description` tema bukan teks portal.

Pekerjaan lanjutan (belum ada, diusulkan sebagai issue terpisah menunggu persetujuan): editor visual susunan di Filament dengan susunan per situs di database (memakai skema yang sama), dan pemilihan blok ke-n (`nth`) agar sekolah dengan beberapa blok sejenis bisa menempatkan blok kedua dst. Saat ini node isi selalu memakai blok aktif pertama bertipe itu.

Kebijakan versi:

- `schema_version` naik hanya untuk perubahan yang merusak tema lama. Inti mendukung versi N dan N-1 (N-1 dimigrasikan dalam memori oleh `MigrateThemeSchema`, berkas tidak diubah).
- Tambahan yang kompatibel (komponen atau varian baru) cukup menaikkan `min_core`: tema dengan `min_core` di atas versi inti dianggap tidak valid dan portal memakai chrome inti.
- Versi di luar N dan N-1 diperlakukan sebagai tidak didukung (fallback inti, peringatan log sekali per slug).

Saat rilis, jalankan `npm run build` (atau `pnpm run build`, sudah ada di `pondok:release`) sebelum memaketkan: kelas Tailwind peta gaya (`ThemeStyleMap`, kelas ditulis utuh sebagai literal) dan utilitas `portal-gutter` hanya ada di CSS bila dibuild. Test tidak memeriksa CSS hasil build, jadi periksa sekilas satu tema di portal setelah build.

## Menerbitkan tema resmi

Tema resmi tambahan (di luar empat tema bawaan) dipasang super-admin dari Situs > Katalog tema. Penerbit menandatangani `theme.json` dengan kunci rahasia Ed25519; aplikasi hanya memasang berkas yang tanda tangannya cocok dengan kunci publik yang tertanam di kode.

### Kunci tanda tangan (sekali saja)

Buat **dua** pasang kunci: `k1` (utama) dan `k2` (cadangan, dipakai bila `k1` bocor). Jalankan di mesin pengelola, bukan di server sekolah, satu kali per kunci:

```
php -r '$p=sodium_crypto_sign_keypair(); echo "publik:  ".base64_encode(sodium_crypto_sign_publickey($p))."\nrahasia: ".base64_encode(sodium_crypto_sign_secretkey($p))."\n";'
```

- Simpan kunci **rahasia** offline (mis. berkas di flash disk terenkripsi atau password manager), satu berkas per kunci berisi hanya string base64-nya. Jangan pernah menaruhnya di repo, CI, `.env`, atau chat. `k2` disimpan terpisah dari `k1`.
- Tanam kunci **publik** di `config/pondok.php`, `theme_public_keys` (`'k1' => '...', 'k2' => '...'`), lalu rilis inti. Selama nilainya kosong, pemasangan tema dari katalog ditolak dengan pesan jelas.
- Bila `k1` bocor: rilis inti berikutnya menghapus `k1` dari daftar, dan tema diterbitkan ulang dengan `k2`. Rotasi penuh di luar cakupan v1.

### Menandatangani satu tema

Sumber tema ada di repo publik `dankedev/pondok-themes`, satu folder per slug berisi `theme.json` (slug = nama folder, `version` naik setiap terbit). Tandatangani:

```
php artisan pondok:theme-sign zz-contoh/theme.json \
  --secret-file=/media/kunci/k1.key --key-id=k1 \
  --url=https://pondok.org/themes/zz-contoh/1.0.0/theme.json
```

Perintah memvalidasi tema, memastikan kunci rahasia berpasangan dengan kunci publik `key_id` di config, lalu mencetak satu objek JSON (`slug`, `name`, `description`, `version`, `schema_version`, `min_core`, `size`, `sha256`, `url`, `signature`, `key_id`). Keluarannya sama pada setiap jalan, jadi aman diulang. Perintah gagal (exit 1) bila kunci salah, tak cocok dengan `key_id`, atau tema tidak valid.

### Menyusun dan menerbitkan katalog

1. Susun `themes.json` berisi `{"themes": [ ...entri dari pondok:theme-sign... ]}` (satu entri per tema, versi terbaru saja). Katalog tidak ditandatangani; yang dipercaya hanya tanda tangan atas berkas tema.
2. Hosting berkas **apa adanya, tanpa mengubah satu byte pun**: tanda tangan dihitung atas byte persis. Di repo `dankedev/pondok-themes` set `* -text` di `.gitattributes` (tanpa konversi akhir baris), dan jangan biarkan hosting meminifikasi atau mengompres ulang isi JSON.
3. **Urutan terbit:** unggah dulu berkas tema ke `https://pondok.org/themes/<slug>/<versi>/theme.json`, pastikan terunduh sama persis (`shasum -a 256`), baru terakhir unggah `https://pondok.org/themes/themes.json`. Katalog tidak boleh menunjuk berkas yang belum ada.
4. Uji dari instalasi percobaan: Situs > Katalog tema > Muat ulang katalog, pasang tema, dan periksa portal. Untuk uji sebelum rilis, ganti URL katalog dengan env `PONDOK_THEME_CATALOG_URL`.

`pondok:doctor` menampilkan baris **Katalog tema** (peringatan, bukan kegagalan, bila katalog tidak terjangkau).

### Menyusun, menguji, dan menandatangani paket tema ber-CSS

Sejak inti 1.2.0 tema resmi boleh membawa `theme.css` dan font `woff2` sendiri (kunci puncak opsional `assets` di `theme.json`). Tanpa JavaScript dari tema. Tema tanpa `assets` tidak berubah sama sekali. Kait markup yang boleh dipakai CSS ada di [docs/tema-kontrak-markup.md](tema-kontrak-markup.md).

**Struktur folder** (nama folder = `slug`):

```
zz-contoh/
  theme.json
  theme.css
  fonts/zz-serif.woff2
```

Hanya berkas yang terdaftar di `theme.json` yang boleh ada; berkas lain, subfolder lain, dan symlink menolak paket.

**Manifest `assets`:**

```json
"assets": {
  "css": {"path": "theme.css", "sha256": "<64 hex>", "size": 4210},
  "fonts": [
    {"path": "fonts/zz-serif.woff2", "family": "Zz Serif", "weight": 400, "style": "normal", "license": "OFL-1.1", "sha256": "<64 hex>", "size": 48211}
  ],
  "contrast": [{"fg": "--pt-fg", "bg": "--pt-bg", "size": "normal"}]
}
```

- Path hanya `theme.css` dan `fonts/<nama>.woff2` (huruf kecil, angka, tanda hubung). Maksimum 6 font; lisensi `OFL-1.1`, `Apache-2.0`, atau `CC0-1.0`.
- Batas: manifest 64 KB, CSS 128 KB, tiap font 250 KB, total paket (manifest + CSS + font) 1,5 MB.
- Tema ber-`assets` menulis `min_core` `1.2.0` (inti lama menolaknya sebagai kunci tak dikenal, jadi tidak pernah memasang paket yang tidak dipahaminya). Skema tetap `schema_version` 1.

**Aturan CSS** (diperiksa saat `pondok:theme-sign` dan saat pasang, dengan validator yang sama): setiap selektor diawali `body[data-pt-theme="<slug>"]`, tanpa kelas atau id (pakai kait `data-pt*` dan `aria-*`); tanpa nesting, `!important`, backslash, `@import`, dan `url()` eksternal; `url()` hanya untuk `@font-face` ke font manifest dan `data:image/svg+xml` pada `background*`, `mask*`, atau `border-image-source`; at-rule hanya `@media`, `@supports`, `@keyframes pt-*`, dan `@font-face`. Tiap `@font-face` wajib `font-display: swap`, `font-family` sama dengan `family` manifest, dan `src: url("fonts/...woff2") format("woff2")`; setiap font manifest harus dirujuk. Custom property hanya didefinisikan di blok scope tema.

**Tailwind.** Utilitas Tailwind berada di `@layer`, jadi CSS tema (tanpa layer) selalu menang tanpa perang spesifisitas. Tema menimpa properti secara eksplisit, termasuk varian responsif utilitas (mis. `md:grid-cols-*` perlu `@media` sendiri).

**Kontras.** Daftar `assets.contrast` berisi pasangan custom property (`--pt-fg` di atas `--pt-bg`) yang wajib berkontras >= 4,5:1 (`normal`) atau >= 3:1 (`large`). Kedua properti harus bernilai literal `#rrggbb` dan didefinisikan tepat sekali di blok scope tema tingkat atas. Wajib minimal satu pasangan. **Jaminan kontras hanya untuk pasangan yang dideklarasikan**; pasangan lain menjadi tanggung jawab penerbit.

**Reduced-motion.** `animation*`, `transition*`, dan `scroll-behavior` hanya boleh aktif di dalam `@media (prefers-reduced-motion: no-preference)`; di luarnya hanya `none`, `0s`, atau `auto`.

**Menandatangani paket.** Beri path folder paket:

```
php artisan pondok:theme-sign zz-contoh \
  --secret-file=/media/kunci/k1.key --key-id=k1 \
  --url=https://pondok.org/themes/zz-contoh/1.0.0/theme.json
```

Perintah menjalankan pemeriksaan paket yang sama dengan pemasangan. Pelanggaran (hash atau ukuran tak cocok, berkas hilang atau berlebih, aturan CSS, font, kontras, gerak) = exit 1 tanpa entri. Bila hash tak cocok, nilai yang benar dicetak. Setelah menyunting CSS atau font, jalankan lagi dengan `--write-hashes`: sha256 dan size di `theme.json` ditulis ulang (ringkasan perubahan dicetak ke stderr) **hanya bila seluruh paket lolos**, baru kemudian ditandatangani. Keluaran entri sama dengan tema biasa dan deterministik; `--url` tetap menunjuk `theme.json`.

**Menguji sebelum terbit.** Tempatkan folder paket dan `themes.json` di hosting uji HTTPS (host sama untuk katalog dan berkas), arahkan instalasi percobaan ke sana dengan env `PONDOK_THEME_CATALOG_URL`, lalu pasang dari Situs > Katalog tema dan periksa portal. Job mengunduh `theme.json` lalu tiap berkas dari folder yang sama dengan `url` entri (`<folder url>/theme.css`, `<folder url>/fonts/<nama>.woff2`), dengan tenggat total 150 detik.

**Urutan terbit.** Unggah dulu **semua berkas paket** (`theme.json`, `theme.css`, font) ke `https://pondok.org/themes/<slug>/<versi>/`, pastikan terunduh sama persis (`shasum -a 256`), baru terakhir perbarui `themes.json`. Berkas tidak boleh diubah setelah ditandatangani; perubahan sekecil apa pun = versi baru. Versi baru harus lebih tinggi dari yang terpasang.

**Kebijakan `min_core`.** Tema ber-`assets` memakai `min_core` `1.2.0` atau lebih tinggi. Inti yang lebih rendah menampilkan tema itu sebagai tidak cocok versi dan tidak memasangnya.

**Daftar periksa penerbit sebelum menandatangani.**

- Kontras hanya dijamin untuk pasangan warna yang dideklarasikan di `assets.contrast`. Pemeriksa tidak menilai warna lain di `theme.css`.
- Periksa secara visual elemen yang warnanya berubah per status: menu aktif (`aria-current`), hover dan fokus, tombol, tautan di atas latar berwarna, dan teks pada tombol menu seluler. Teks dan latar yang sama-sama berubah per status tidak tertangkap pemeriksa.
- Lihat tema di 360px, 1024px, dan desktop, dengan konten demo yang lengkap.
- Variabel yang dipakai pasangan kontras hanya boleh didefinisikan sekali di blok scope tingkat atas; definisi ulang di komponen atau `@media` (termasuk mode gelap) ditolak.

### Memasang, mengembalikan, dan menghapus tema (super-admin)

Di Situs > Katalog tema (izin `manage:themes`, bawaan super-admin):

- **Pasang / Perbarui:** pemasangan berjalan di antrean, jadi baru mulai paling lambat sekitar 1 menit (cron `schedule:run` yang memproses antrean). Status berubah dari "sedang berjalan" ke terpasang tanpa memuat ulang manual. Tema hanya terpasang bila ukuran, sha256, dan tanda tangan cocok dan `theme.json` lolos validasi; versi harus lebih baru dari yang terpasang.
- **Kembalikan versi sebelumnya:** memulihkan `previous.json` (satu langkah mundur).
- **Hapus:** menghapus tema terpasang; bila sedang dipakai, portal kembali ke tema bawaan.
- Perubahan tema aktif di portal baru terlihat setelah cache portal kedaluwarsa, sekitar 5 menit.
- Tema terpasang disimpan di `storage/app/themes` (ikut dalam backup storage).

## Memeriksa checksum

Sekolah dapat mencocokkan berkas yang diunduh:

```
shasum -a 256 -c pondok-1.2.0.zip.sha256
```

## Menandai versi

Setelah paket diuji (instalasi bersih dan pembaruan dari versi sebelumnya), tandai commit-nya:

```
git tag -a v1.2.0 -m "Pondok 1.2.0"
git push origin v1.2.0
```

Tanpa `--release-version`, `pondok:release` memakai tag ini sebagai versi.
