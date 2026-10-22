# Referensi gaya: Course X (Webflow)

Sumber: https://coursetemplate.webflow.io/home, dipilih user (16 Okt 2026) sebagai acuan gaya portal publik. Yang diambil hanya **gaya**; warna mengikuti logo lembaga, isi tetap dari profil lembaga (tanpa konten bawaan).

## Yang membuat template ini tidak terasa kaku

Kartunya justru bersudut tajam (radius 0). Kesan lembutnya datang dari:

- ruang kosong besar: padding section 140–160px atas-bawah di desktop;
- bayangan sangat tipis dan lebar: `0 6px 24px rgba(27,27,27,.04)`, kartu besar `0 20px 48px rgba(27,27,27,.06)`;
- tombol dan input **pill** (radius penuh), tombol utama dengan bayangan berwarna: `0 12px 35px rgba(<primary>,.21)`;
- hiasan geometris di belakang foto: cincin tebal setengah lingkaran dan pola titik (dot grid);
- selang-seling latar section: putih → abu sangat muda `#FBFBFB` → gelap → putih;
- judul besar dan tebal, teks isi besar (18px, line-height 1.67).

## Token asli → token Pondok

| Peran | Course X | Pondok |
|---|---|---|
| Primer (tombol, ikon, aksen) | hijau `#079561` | navy logo `#000A3C` (sampel dominan `#000840`) |
| Aksen | hijau yang sama | emas logo `#F8B800` |
| Section gelap | `#1A1E1C` | navy logo `#000A3C` |
| Teks judul | `#1A1E1C` | navy gelap |
| Teks isi | `#859389` | abu netral yang lolos kontras 4.5:1 (warna asli hanya ±3.3:1) |
| Latar section muda | `#FBFBFB` / kartu `#F8F8F8` | sama |

Nilai final yang dipakai di `resources/css/app.css` (F035; rincian kontras di `docs/design-guide.md` §2):

| Token | Nilai |
|---|---|
| `primary-50` … `primary-950` | `#EEF2FB`, `#DDE4F6`, `#BBC8EC`, `#8EA2DB`, `#5F78C4`, `#3D56A8`, `#273E8C`, `#1A2C72`, `#0F1D59`, `#061247`, `#000A3C` (logo) |
| `accent-50` … `accent-950` | `#FFF9E5`, `#FFF0BF`, `#FFE28A`, `#FFD452`, `#FFC524`, `#F8B800` (logo), `#D49A00`, `#A67700`, `#7A5700`, `#523A00`, `#2E2100` |
| `gray-*` | alias slate (teks isi `gray-600` 7.58:1, latar section `gray-50` `#F8FAFC`) |
| `shadow-card` | `0 6px 24px rgb(0 10 60 / 0.05)` |
| `shadow-card-lg` | `0 20px 48px rgb(0 10 60 / 0.07)` |
| `shadow-button` | `0 12px 30px rgb(0 10 60 / 0.18)` |
| Font | Plus Jakarta Sans 400/600/700, Noto Naskh Arabic 400/700 (self-host lewat plugin font Vite) |

Aturan emas: **emas tidak dipakai sebagai warna teks di atas putih** (kontras ±1.9:1). Emas dipakai untuk latar badge/nomor langkah, cincin dan titik dekoratif, angka statistik di section navy, dan garis aksen. Teks di atas emas memakai navy.

## Tipografi

- Font asli: Plus Jakarta Display (700 judul, 400 isi). Padanan gratis yang dipakai portal: **Plus Jakarta Sans** (OFL, desainer Indonesia), di-self-host saat build (`docs/design-guide.md` §3).
- Skala desktop: H1 60/74, H2 38/49, H3 25/34, isi 18/30, tombol 16 tebal. Di mobile H1 sekitar 36–40px.

## Pola section → bagian portal Pondok

Setiap section tetap disembunyikan bila isiannya kosong.

| Section Course X | Dipakai untuk |
|---|---|
| Hero dua kolom: judul + teks + 2 tombol pill, foto dengan cincin dan pola titik | Beranda: nama/slogan, tombol Daftar & Info PSB, foto lembaga |
| Kartu profil menumpuk di atas foto + teks di kanan | Sambutan pimpinan |
| Pita gelap berisi angka besar dipisah garis tipis | Statistik lembaga (bila diisi admin) |
| Daftar langkah bernomor (lingkaran) di samping foto | Cara mendaftar / timeline PSB |
| Grid kartu putih dengan ikon dalam lingkaran berwarna | Program, kurikulum, fasilitas, ekstrakurikuler |
| Kartu testimoni besar: foto bulat + kutipan | Testimoni wali/alumni (bila ada) |
| FAQ dua kolom, kartu dengan ikon "+" | FAQ PSB |
| Footer: logo + ikon sosial, kolom tautan, input pill | Footer portal (kolom dari menu F037) |

## Yang tidak ikut diambil

Animasi muncul saat scroll (konten tersembunyi sampai digulir; buruk untuk aksesibilitas dan `prefers-reduced-motion`), foto stok, keranjang belanja, dan badge promosi Webflow.
