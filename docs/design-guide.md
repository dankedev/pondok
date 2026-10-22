# Design Guide — Pondok

Panduan ini **wajib diikuti** oleh setiap agent dan kontributor saat membuat atau mengubah tampilan: panel admin Filament, portal publik, portal wali, dan form PSB. Tujuannya satu: semua layar terasa dibuat oleh satu tangan.

Bila ada aturan yang tidak bisa diikuti, jangan improvisasi gaya baru. Ikuti pola terdekat yang sudah ada di kode, lalu sebutkan penyimpangannya ke user.

---

## 0. Checklist sebelum menyerahkan pekerjaan UI

- [ ] Alur anti-ui-slop (§13) sudah dijalankan; layar besar punya kontrak desain.
- [ ] Tidak ada data, metrik, atau tombol pengisi yang tidak punya sumber/Action nyata (§13).
- [ ] Halaman sudah dilihat di desktop dan 360px, atau user diberi tahu bahwa belum dicek visual (§13).
- [ ] Semua teks UI lewat `__('pondok.…')` di `lang/id/pondok.php`. Tidak ada string Indonesia/Inggris yang di-hardcode di PHP atau Blade.
- [ ] Warna hanya dari token (§2). Tidak ada hex, `rgb()`, atau kelas Tailwind warna mentah seperti `bg-green-600` di Blade.
- [ ] Portal/PSB: token merek (`bg-brand text-on-brand`, `text-brand-text`) untuk aksi dan judul utama, emas hanya latar/garis/hiasan (tidak pernah `text-accent-*`), teks isi `gray-600`/`gray-700` (§2).
- [ ] Bayangan hanya token `shadow-card`/`shadow-card-lg`/`shadow-button`; tanpa nilai arbitrer `shadow-[…]` (§6).
- [ ] Uang tampil `Rp 1.250.000` (§5). Tanggal tampil `15 Okt 2026` (§5).
- [ ] Status tampil sebagai badge dengan warna sesuai tabel status (§4).
- [ ] Ada empty state, dan state "Modul belum tersedia" untuk widget/halaman modul non-inti (§7).
- [ ] Layar portal/PSB dicek di lebar 360px tanpa scroll horizontal (§6).
- [ ] Portal wali (panel Filament `wali`): komponen dan warna Filament seperti panel admin, bukan token/komponen portal publik; mode terang; data hanya lewat `User::portal*()` (§2, §6).
- [ ] Ikon dari Heroicon Outlined (§3).
- [ ] Aksi destruktif dan aksi keuangan meminta konfirmasi (§8).

---

## 1. Prinsip

1. **Tenang dan bisa dipercaya.** Ini aplikasi lembaga pendidikan Islam yang mengurus data anak dan uang wali. Tidak ada gradien mencolok, animasi berlebihan, emoji di UI, atau gaya "startup".
2. **Jelas untuk pengguna awam.** Bendahara, musyrif, dan wali bukan orang teknis. Label lugas, satu aksi utama per layar, angka besar dan mudah dibaca.
3. **Ringan.** Target shared hosting dan HP Android kelas bawah. Tidak ada library JS tambahan, tidak ada aset dari CDN saat runtime, gambar selalu dikompres.
4. **Default Filament dulu.** Di panel admin, pakai komponen dan perilaku bawaan Filament. Kustomisasi CSS/view Filament hanya bila tidak ada cara lewat API-nya.

## 2. Warna

### Token

Dua permukaan, nama semantik yang sama:

| Peran | Filament (`AdminPanelProvider`, `WaliPanelProvider`) | Tailwind (`resources/css/app.css`) | Pakai untuk |
|---|---|---|---|
| Primary | `Color::Emerald` | `primary-*` navy logo Al-Khair (skala di bawah, 950 = `#000A3C`) | Tombol utama, link, elemen aktif |
| Accent | — | `accent-*` emas logo Al-Khair (500 = `#F8B800`) | Portal/PSB: latar badge "Pendaftaran dibuka", garis aksen footer, `blockquote`, hiasan. Bukan warna teks. |
| Netral | `gray` (bawaan) | `gray-*` (alias slate) | Teks, border, latar |
| Success | `success` | `success-*` (green) | Lunas, aktif, disetujui, diterima |
| Warning | `warning` | `warning-*` (amber) | Belum lunas sebagian, menunggu, akan jatuh tempo |
| Danger | `danger` | `danger-*` (red) | Terlambat, ditolak, batal, aksi hapus |
| Info | `info` | `info-*` (sky) | Proses berjalan, informasi netral |

**Portal dan panel sengaja berbeda primer.** Panel admin Filament tetap `Color::Emerald` dan tidak memuat `app.css`. Portal wali (F022) adalah panel Filament kedua (`wali`) dengan `Color::Emerald` dan theme admin yang sama, jadi ikut aturan panel, bukan token navy/emas portal. Panel memakai theme Filament sendiri (`resources/css/filament/admin/theme.css`) yang hanya mengimpor `theme.css` bawaan Filament dan memindai `app/Filament` serta `resources/views/filament`, sehingga token navy/emas tetap hanya berlaku di portal publik, PSB, dan layout publik. Acuan gaya portal: [`docs/brand/referensi-gaya-coursex.md`](brand/referensi-gaya-coursex.md).

Skala portal (hex literal di `@theme`, kontras WCAG 2.x):

| Shade | `primary-*` (navy) | Kontras di putih | `accent-*` (emas) | Kontras di putih / navy 950 |
|---|---|---|---|---|
| 50 | `#EEF2FB` | 1.12 | `#FFF9E5` | 1.05 / 17.98 |
| 100 | `#DDE4F6` | 1.27 | `#FFF0BF` | 1.14 / 16.66 |
| 200 | `#BBC8EC` | 1.67 | `#FFE28A` | 1.27 / 14.88 |
| 300 | `#8EA2DB` | 2.52 | `#FFD452` | 1.42 / 13.34 |
| 400 | `#5F78C4` | 4.23 | `#FFC524` | 1.58 / 11.96 |
| 500 | `#3D56A8` | 6.79 | `#F8B800` | 1.77 / 10.69 |
| 600 | `#273E8C` | 9.75 | `#D49A00` | 2.49 / 7.60 |
| 700 | `#1A2C72` | 12.75 | `#A67700` | 3.99 / 4.74 |
| 800 | `#0F1D59` | 15.67 | `#7A5700` | 6.58 / 2.88 |
| 900 | `#061247` | 17.72 | `#523A00` | 10.69 / 1.77 |
| 950 | `#000A3C` | 18.94 | `#2E2100` | 15.75 / 1.20 |

Tabel di atas adalah **skala bawaan**. Admin dapat mengganti warna utama dan aksen portal lewat Situs > Tampilan (F070): `BuildColorScale` menyusun skala 50–950 di PHP (OKLCH, tangga lightness dan chroma tetap per peran yang diambil dari tabel ini, warna masukan ditaruh di langkah dengan lightness terdekat, chroma diturunkan sampai masuk gamut sRGB), lalu `GetPortalTheme` menulisnya sebagai `--color-primary-*`/`--color-accent-*` di `<style data-portal-theme>` layout publik. Pemakaian langkah dan aturan emas di bawah tetap berlaku untuk skala apa pun; skala ditolak bila putih vs `primary-700`–`950`, `primary-100`/`200` vs `primary-950`, atau `accent-400`/`500` vs `primary-950` di bawah 4,5:1. Warna masukan sampai ke tombol dan judul lewat token merek (F071, di bawah). Panel admin, halaman galat global (`errors/layout.blade.php`), dan bayangan `--shadow-*` tidak ikut tema. Template beranda (F072) dipilih di halaman yang sama tetapi tidak mengubah token warna, font, atau sudut (lihat §6). Tema yang sama juga mengatur sudut kartu: `--radius-lg`/`--radius-xl` kecil `.25rem`/`.375rem`, sedang (bawaan) `.5rem`/`.75rem`, besar `.75rem`/`1.25rem`; `rounded-full` tetap. Jangan mengubah `@theme` di `app.css` menjadi `@theme inline` atau memakai `theme()` di utilitas portal, karena tema bergantung pada utilitas yang membaca `var()`.

Token merek (F071) di `@theme`, dipakai untuk aksi dan judul utama portal/PSB:

| Token | Bawaan | Utilitas | Dipakai di |
|---|---|---|---|
| `--color-brand` | `var(--color-primary-900)` | `bg-brand`, `accent-brand` | Tombol utama (`<x-ui.button>` bawaan, tombol Daftar di header), chip filter aktif, halaman paginasi aktif, batang langkah PSB, radio/checkbox PSB |
| `--color-brand-hover` | `var(--color-primary-800)` | `hover:bg-brand-hover` | Hover tombol utama |
| `--color-on-brand` | `var(--color-white)` | `text-on-brand` | Teks di atas `bg-brand` (selalu dipasangkan) |
| `--color-brand-text` | `var(--color-primary-950)` | `text-brand-text` | `h1` hero (semua preset), `h1` beranda tanpa hero, nama sekolah di header (latar putih) |

Bawaan identik dengan tampilan navy sebelumnya. Bila warna utama kustom diterima, `GetPortalTheme` menulis keempat token sebagai hex dari `BuildColorScale::brandColors()` (satu titik `GetPortalTheme::brandTokens()`, juga dipakai pratinjau Situs > Tampilan); warna ditolak/rusak/bawaan → token tidak ditulis. Aturan pemetaan (ambang 4,5:1, teks gelap = langkah 950 skala):
- Isi (`brand`): warna masukan bila terbaca dengan teks putih atau gelap; selain itu langkah skala terdekat yang terbaca (seri → lebih gelap).
- Teks di atas isi (`on-brand`): putih bila terbaca, selain itu langkah 950.
- Hover: satu langkah dari langkah isi, lebih gelap bila teks putih, lebih terang bila teks gelap; arah lain di ujung skala.
- Judul (`brand-text`): warna masukan bila terbaca di putih; selain itu langkah lebih gelap pertama yang terbaca.
Akibatnya warna terang (mis. kuning) menjadi tombol terang bertuliskan gelap, sedangkan judulnya digeser lebih gelap. Jangan memakai `bg-brand` tanpa `text-on-brand`, dan jangan memakai `text-brand-text` di latar gelap.

Tetap memakai skala F070 (bukan token merek): aksen emas, tautan `text-primary-700`, footer dan CTA `navy` (`bg-primary-950` teks putih), CTA `gold`, eyebrow, badge, nav aktif (`bg-primary-50 text-primary-900`), border/teks hover chip dan paginasi (`hover:border-primary-900 hover:text-primary-950`), cincin fokus `primary-500` (dengan `ring-offset-2` pada tombol), `shadow-button`, kutipan Arab, dan `errors/layout.blade.php`.

Aturan emas (portal/PSB):
- `text-accent-*` **dilarang** di Blade (emas di putih hanya 1.77:1). Dijaga test sumber.
- Emas hanya lewat `bg-`, `border-`, `ring-`, `fill-`, `stroke-`, `outline-`, atau `decoration-`. Teks di atas emas memakai `text-primary-950`.
- Cincin fokus di latar navy memakai `ring-accent-400` (11.96:1 terhadap navy); `primary-500` di atas navy hanya ±2.8:1.
- Maksimal satu sorotan emas berteks (badge) per layar; garis, hiasan, dan `blockquote` tidak dihitung.
- Status `warning` (amber) bernada dekat dengan emas. Pembedanya bentuk: badge aksen emas padat berteks navy, badge warning latar `warning-50` dengan teks `warning-800`.

Aturan:
- Di Filament, warna hanya lewat nama semantik (`'success'`, `'danger'`, `'gray'`, `'primary'`), **bukan** `Color::Green` atau hex.
- Di Blade, pakai kelas token (`bg-primary-600`, `text-danger-700`). Jangan `bg-emerald-600` langsung. Bila butuh token baru, tambahkan di `@theme` pada `app.css` dan catat di tabel ini.
- Arti warna konsisten. Hijau tidak pernah dipakai untuk "ditolak", merah tidak pernah untuk dekorasi.
- Warna tidak boleh jadi satu-satunya pembeda. Badge selalu berisi teks.

### Kontras & latar

- Teks utama `gray-900` di atas putih, teks isi `gray-600`/`gray-700`. `gray-500` hanya untuk ikon; `gray-300`/`gray-400` tidak untuk teks yang harus dibaca (kecuali paginasi nonaktif).
- Portal: teks di atas `primary-700` ke atas putih; teks di atas emas `primary-950`. Panel admin mengikuti Filament.
- Latar portal: halaman putih, section beranda berselang-seling `gray-50`/putih, form PSB di atas `gray-50`, footer `primary-950` (satu-satunya section gelap; teks `white`/`primary-100`/`primary-200`). Kartu putih dengan `border-gray-100` + `shadow-card`.
- Dark mode: panel admin mengikuti bawaan Filament. Portal wali (panel `wali`, `->darkMode(false)`), portal publik, dan PSB **hanya mode terang** (Fase 1–2). Jangan menambahkan kelas `dark:` di Blade portal maupun view panel wali.

## 3. Tipografi & ikon

- **Huruf Latin:** Plus Jakarta Sans 400/600/700 (bobot 500 tidak dipakai portal, jadi tidak dibundel), di-self-host lewat plugin font Vite (`bunny()` di `vite.config.js`, diunduh saat `npm run build` ke `public/build`) dan dimuat lewat `@fonts`. Panel Filament tetap memakai font bawaannya.
- **Font tema portal** (F070, Situs > Tampilan; semua OFL, self-host lewat `bunny()` dengan `preload: false`): `jakarta` (bawaan, Plus Jakarta Sans untuk judul dan isi), `lora` (Lora 600/700 untuk `h1`–`h3` lewat `--font-heading`, isi tetap Plus Jakarta Sans), `nunito` (Nunito 400/600/700 untuk judul dan isi lewat `--font-sans`). Judul membaca `var(--font-heading, var(--font-sans))` di `@layer base`. Font tema hanya dipakai bila aliasnya ada di `fonts-manifest.json` build aktif; selain itu portal jatuh ke bawaan. Jangan menambah font Latin lain di luar daftar ini; font judul kondensasi berhuruf kapital tidak dipakai (bertentangan dengan §9).
- **Teks Arab** (nama surah, ayat, doa, kaligrafi nama lembaga): Noto Naskh Arabic (400/700, subset `arabic`, tanpa preload) yang di-self-host lewat plugin font Vite yang sama, dibungkus elemen dengan `lang="ar" dir="rtl"` dan kelas `font-arabic`. Ukuran minimal 1.25× teks sekitarnya. Jangan pernah render teks Arab dengan font Latin.
- Skala yang dipakai (Tailwind): `text-sm` (keterangan, tabel), `text-base` (isi), `text-lg` (judul kartu), `text-2xl` (judul halaman panel). Jangan memakai ukuran lain tanpa alasan.
- Skala judul portal/PSB:

  | Elemen | Kelas |
  |---|---|
  | `h1` hero beranda | `text-4xl font-bold tracking-tight text-brand-text sm:text-5xl lg:text-6xl text-balance` |
  | `h2` section beranda | `text-3xl font-bold tracking-tight text-gray-900 lg:text-4xl` |
  | `h1` halaman dalam dan PSB | `text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl` |
  | Subjudul hero | `text-lg text-gray-600 lg:text-xl` |
  | Isi section beranda dan `.portal-prose` | `text-base leading-relaxed lg:text-lg` |

  `text-5xl`–`text-6xl` hanya untuk `h1` hero beranda.
- Bobot: `font-normal` untuk isi, `font-semibold` untuk judul kartu dan angka penting. `font-bold` hanya untuk `h1`/`h2` portal dan nama lembaga di header/footer; `font-black` tidak dipakai.
- Angka nominal, NIS, dan nomor kuitansi memakai `tabular-nums`. Di tabel Filament, kolom uang rata kanan (`->alignEnd()`).
- Kapitalisasi: **Huruf kapital di awal kalimat saja** untuk label, tombol, dan judul (`Tambah santri`, bukan `Tambah Santri`). Pengecualian: nama diri, singkatan (PSB, SPP, NIS), dan label model/navigasi Filament yang sudah ada.
- **Ikon:** hanya `Filament\Support\Icons\Heroicon::Outlined…` di Filament dan `<x-heroicon-o-… />` di Blade. Satu konsep satu ikon; tambahkan pasangannya di tabel bila memakai ikon baru.

| Konsep | Ikon |
|---|---|
| Santri | `AcademicCap` |
| Angkatan | `Users` |
| Wali | `UserGroup` |
| Staff | `Identification` |
| Asrama / kamar | `HomeModern` |
| PSB | `ClipboardDocumentList` |
| Keuangan / tagihan | `Banknotes` |
| Pembayaran / kuitansi | `ReceiptPercent` |
| Dokumen | `DocumentText` |
| Prestasi | `Trophy` |
| Rekap / laporan | `ChartBar` |
| Tahfidz | `BookOpen` |
| Perizinan | `ArrowRightStartOnRectangle` |
| Masuk ke panel (login) | `ArrowRightEndOnRectangle` |
| Konten / berita | `Newspaper` |
| Pengaturan | `Cog6Tooth` |
| Pindah (jenjang, kamar, kelas) | `ArrowsRightLeft` |
| Pendaftar | `UserPlus` |
| Unggah berkas | `ArrowUpTray` |
| Unduh | `ArrowDownTray` |
| Tandai terverifikasi | `CheckBadge` |
| Terima | `CheckCircle` |
| Tolak / batalkan penerimaan | `XCircle` |
| Kembalikan ke tahap sebelumnya | `ArrowUturnLeft` |
| Pulihkan | `ArrowPath` |
| Cadangan | `Clock` |
| Daftar ulang | `AcademicCap` |
| Galeri | `Photo` |
| Video | `Film` |
| Alamat | `MapPin` |
| Telepon | `Phone` |
| Email | `Envelope` |
| WhatsApp | `ChatBubbleLeftRight` |
| Menu (portal seluler) | `Bars3` |
| Jenjang | `BuildingLibrary` |
| Rombel | `RectangleGroup` |
| Naik kelas | `ArrowUpCircle` |
| Periode (tahun ajaran, gelombang PSB, tagihan tahunan) | `CalendarDays` |
| Jalur PSB | `Flag` |
| Jenis dokumen | `RectangleStack` |
| Pengguna panel | `UserCircle` |
| Identitas lembaga | `BuildingOffice2` |
| Penomoran (NIS) | `Hashtag` |
| Surat | `EnvelopeOpen` |
| Penandatangan | `PencilSquare` |
| Akhiri (keringanan, penempatan kamar) | `StopCircle` |
| Ganti berkas | `ArrowUpTray` (sama dengan unggah) |
| Subsidi / beasiswa (kosong di laporan) | `Gift` |
| Buat tagihan / berkas baru | `DocumentPlus` |
| Kategori | `Tag` |
| Halaman statis | `Document` |
| Beranda situs | `Home` |
| Kaki situs | `Window` |
| Tampilan situs (tema) | `Swatch` |
| Blok situs: hero, teks, kutipan, ajakan, tautan, media sosial | `Star`, `Bars3BottomLeft`, `ChatBubbleBottomCenterText`, `Megaphone`, `Link`, `Share` |
| Tautkan (wali ↔ santri) | `Link` |
| Token akses PSB | `Key` |
| Salin | `DocumentDuplicate` (Filament), `ClipboardDocument` (salin ke papan klip) |
| Tambah | `Plus` |
| Lihat | `Eye` |
| Cetak | `Printer` |
| Hapus | `Trash` |
| Buka / kembali | `ArrowRight` / `ArrowLeft` |
| Kosong (umum) | `Inbox` |
| Peta lokasi | `Map` |

## 4. Status & badge

Setiap kolom `status` tampil sebagai badge (`->badge()`), label dari `lang/id`, warna dari tabel ini. Status baru wajib ditambahkan ke tabel ini dulu.

| Domain | Status | Warna |
|---|---|---|
| Santri | aktif | `success` |
| Santri | lulus, keluar, pindah | `gray` |
| Tagihan | lunas | `success` |
| Tagihan | sebagian | `warning` |
| Tagihan | belum bayar | `gray` |
| Tagihan | jatuh tempo lewat | `danger` |
| Pembayaran | diterima | `success` |
| Tagihan / pembayaran | batal | `danger` (teks dicoret tidak dipakai) |
| PSB | draft, diajukan (terdaftar) | `gray` |
| PSB | diverifikasi, tes | `info` |
| PSB | diterima | `success` |
| Daftar ulang | sudah daftar ulang, memenuhi syarat | `success` |
| Daftar ulang | belum memenuhi syarat | `warning` |
| PSB | cadangan | `warning` |
| PSB | ditolak, mengundurkan diri | `danger` |
| Gelombang PSB (portal Info PSB) | dibuka | `success` |
| Gelombang PSB (portal Info PSB) | akan dibuka | `info` |
| Berkas PSB | menunggu | `warning` |
| Berkas PSB | valid | `success` |
| Berkas PSB | ditolak | `danger` |
| Perizinan | menunggu | `warning` |
| Perizinan | disetujui, sudah kembali | `success` |
| Perizinan | ditolak | `danger` |
| Perizinan | terlambat (dihitung, bukan status) | `danger` |
| Dokumen | menunggu | `warning` |
| Dokumen | valid | `success` |
| Dokumen | ditolak | `danger` |
| Dokumen | diganti (dihitung dari `replaced_by_id`) | `gray` |
| Surat keluar | terbit | `success` |
| Surat keluar | batal | `danger` |
| Prestasi | terverifikasi | `success` |
| Prestasi | belum diverifikasi | `warning` |
| Konten | draft | `gray` |
| Konten | terbit | `success` |
| Konten | terjadwal (dihitung, bukan status) | `info` |
| Saklar modul (Pengaturan → Modul) | aktif | `success` |
| Saklar modul (Pengaturan → Modul) | nonaktif | `gray` |

Pemetaan warna ditaruh di satu tempat (method `color()` pada enum atau konstanta model), tidak ditulis ulang di setiap tabel.

## 5. Format data

| Data | Tampilan | Cara |
|---|---|---|
| Uang | `Rp 1.250.000` | Filament: `->money('IDR', locale: 'id', decimalPlaces: 0)`. Input: `TextInput` numeric dengan `->prefix('Rp')`, simpan integer. Blade: helper format rupiah bersama, bukan `number_format` di view. |
| Uang nol | `Rp 0` atau label `Gratis` untuk beasiswa penuh | — |
| Persen | `25%` | tanpa desimal kecuali perlu |
| Tanggal | `15 Okt 2026` | `->date('j M Y')` dengan locale `id`; singkatan Agustus "Agu" (`17 Agu 2026`, diatur di `AppServiceProvider`) |
| Tanggal + jam | `15 Okt 2026, 14.30` | `->dateTime('j M Y, H.i')` |
| Periode tagihan | `Oktober 2026` | `translatedFormat('F Y')` |
| Tahun ajaran | `2026/2027` | dari `academic_years.name` |
| Tanggal Hijriah | `12 Rabiulawal 1448 H` | opsional, selalu sebagai keterangan sekunder di bawah tanggal Masehi |
| Telepon | `0812-3456-7890` | simpan tanpa format |
| NIK / No. KK | `3201••••••••0001` | selalu dimasker di tabel; tampil penuh hanya di halaman detail bagi yang berhak |
| Kosong | `-` | `->placeholder('-')` |
| Gender | `Laki-laki` / `Perempuan` | `pondok.common.genders` |

Zona waktu selalu `Asia/Jakarta`. Jangan menampilkan ISO timestamp mentah.

## 6. Tata letak

### Panel admin (Filament)

- Struktur resource mengikuti pola yang ada: `Resource` + `Schemas/<Model>Form` + `Tables/<Model>sTable` + `RelationManagers`. Data induk kecil memakai halaman `Manage…` (modal).
- **Form:** kelompokkan dengan `Section` berjudul (`section_identity`, `section_address`, …), `->columns(2)`. Field panjang (alamat, catatan) `->columnSpanFull()`. Urutan: identitas → relasi (jenjang, angkatan) → kontak → lain-lain.
- **Tabel:** kolom pertama adalah pengenal (NIS, nomor tagihan), kolom kedua nama. Maksimal ±7 kolom tampil; sisanya `->toggleable(isToggledHiddenByDefault: true)`. Kolom status di kanan, kolom uang rata kanan. Selalu `->defaultSort()` dan eager-load relasi lewat `modifyQueryUsing`.
- **Filter:** jenjang dan status sebagai `SelectFilter`. Filter jenjang memakai `->accessible()`.
- **Aksi baris:** `EditAction` tampil langsung; aksi lain masuk `ActionGroup`. Aksi bisnis didefinisikan sebagai static method di Resource (contoh `StudentResource::changeUnitAction()`) dan memanggil Action class.
- **Navigasi:** grup dari `pondok.navigation.*`, urutan sesuai alur kerja (yang paling sering dipakai di atas). Jangan membuat grup baru tanpa menambahkannya ke `lang/id`.
- **Dashboard:** widget stat di baris atas (maks. 4), lalu tabel/daftar tindak lanjut ("Tagihan jatuh tempo", "Izin menunggu"), lalu grafik. Grafik hanya bila menjawab pertanyaan; jangan hiasan.

### Portal wali (panel Filament `wali`, F022)

- Panel Filament kedua di `/wali` (`WaliPanelProvider`): `->topNavigation()`, `->maxContentWidth(Width::TwoExtraLarge)` (setara `max-w-2xl`), `->darkMode(false)`, `Color::Emerald`, theme `resources/css/filament/admin/theme.css`. Tanpa dashboard bawaan, `AccountWidget`, maupun notifikasi database.
- Halaman di `app/Filament/Wali/Pages` memakai trait `BelongsToPortalWali` (`getModule()` = `portal-wali`, `canAccess()` cek modul). Halaman detail tidak masuk navigasi, menyimpan id saja, dan me-resolve ulang record lewat `User::portal*()` setiap render; tidak berhak → 404.
- Komponen: `x-filament::section`, `x-filament::badge`, `x-filament::empty-state`, `x-filament::button`, dan tabel Filament untuk daftar berhalaman. Ringkasan santri dipakai ulang lewat `filament/wali/partials/student-summary`.
- Mobile-first seperti portal: satu kolom di 360px, `sm:grid-cols-*` di atasnya. Sapaan "Anda" (§9).

### Portal publik, PSB (Blade + Livewire)

- **Mobile-first.** Rancang untuk 360px dulu, lalu `sm`/`md`/`lg`. Wali dan pendaftar hampir semua memakai HP.
- Kontainer: `mx-auto max-w-6xl px-4 sm:px-6` (portal publik), `max-w-2xl` (form PSB).
- Spasi memakai skala Tailwind 4 kelipatan: `gap-4`/`gap-6`, `p-4`/`p-6`. Section beranda `py-16 lg:py-24`, kontainer halaman dalam `py-12 lg:py-20`. Hindari nilai arbitrer (`p-[13px]`, `w-[85%]`); pakai pecahan skala (`w-5/6`).
- Sudut: `rounded-full` (pill) untuk tombol, input, select, input file, badge, chip filter, paginasi, item nav, dan tautan "Daftar". `rounded-xl` untuk kartu, alert, empty state, foto, iframe, menu lipat, dan ubin pilihan radio.
- Bayangan hanya token di `@theme`: `shadow-card` (kartu, `border-gray-100`), `shadow-card-lg` (kartu besar, menu lipat), `shadow-button` (tombol utama navy). Tanpa `shadow-[…]`.
- Input dan select memakai kelas komponen `.ui-input` (pill, `px-5`, fokus `ring-primary-500`); kelas tambahan per field (`tabular-nums`) tetap ditulis di elemen.
- Beranda (F045) = daftar blok (`resources/views/themes/<preset>/blocks/{type}.blade.php`): hero putih, lalu blok yang dirender berselang-seling `bg-gray-50` → `bg-white` (penghitung `$band()` di `home.blade.php`, hanya dipanggil blok yang tampil). Blok kosong tidak dirender. Blok Ajakan punya latar sendiri: `light` = band, `navy` = `bg-primary-950` teks putih dengan tombol varian `inverse`/`inverse-secondary` dan fokus `ring-accent-400`, `gold` = `bg-accent-500` teks `text-primary-950` (emas tetap bukan warna teks). Grid Keunggulan dan Konten terbaru memakai `<x-portal.adaptive-grid>`: kolom `lg` = min(jumlah item, maks.), satu item tanpa grid lebar, baris sisa rata tengah dengan lebar kartu sama (`.portal-adaptive-grid` + `adaptive-cols-<n>` di `app.css`, F073).
- Preset template beranda (F072, Situs > Tampilan, key `theme.template`): `default` (Standar: hero dua kolom, keunggulan dalam kartu) dan `centered` (Terpusat: hero rata tengah dengan media `max-w-4xl` di bawah teks, keunggulan sebagai daftar bergaris `divide-y` tanpa kartu (butir terakhir jumlah ganjil di tengah pada `lg`), gambar Teks + gambar di kiri pada `lg`, kutipan rata tengah dengan garis emas pendek di atas). Preset hanya mengoverride blok yang tata letaknya beda; blok lain jatuh ke `themes/default` (`GetPortalTheme::blockView`). Semua preset merender isian `portal.home_blocks` yang sama dengan kontrak yang sama: satu `h1` (hero atau pita nama lembaga), `section` dengan `aria-labelledby` hanya bila ada judul, `$band()`/`$sectionHeading`/`$sectionLink` dari `home.blade.php`, tombol dan alt gambar sama. Header, footer, navigasi, dan halaman selain beranda tidak ikut preset. Preset baru = folder `themes/<kunci>/blocks/` + kunci di `GetPortalTheme::TEMPLATES` + label/deskripsi di `pondok.school_profile.theme.templates`.
- Hiasan cincin emas setengah lingkaran (`.portal-decor-ring`) dan pola titik navy muda (`.portal-decor-dots`): murni CSS, hanya di hero (titik, `lg`) dan slider (cincin + titik, `sm`), sebagai `<div aria-hidden="true">` kosong di belakang konten (`-z-10` dalam wadah `isolate`), selalu berkelas `hidden` di bawah breakpoint. Section berhias memakai `overflow-x-clip`.
- Target sentuh minimal 44×44px. Tombol utama di PSB lebar penuh di mobile (`w-full sm:w-auto`).
- Form PSB dipecah menjadi langkah (wizard) dengan indikator langkah, satu kolom di mobile, label di atas field, dan tombol "Simpan & lanjut" di bawah.
- Komponen Blade yang dipakai ulang (tombol, badge, kartu, field, empty state, alert) dibuat sebagai anonymous component di `resources/views/components/ui/` dan dipakai sebagai `<x-ui.button>`. Cek komponen yang sudah ada sebelum membuat yang baru.
- Foto: rasio tetap (`aspect-video` untuk berita/galeri, `aspect-square` untuk foto santri), `object-cover`, `loading="lazy"`, ada `alt`.

## 7. State

Setiap daftar, tabel, dan widget wajib punya:

| State | Isi |
|---|---|
| Kosong | Ikon + satu kalimat keadaan + satu aksi bila relevan. Contoh: "Belum ada tagihan untuk periode ini." [Buat tagihan] |
| Modul mati / tabel belum ada | Widget dashboard: widget tetap tampil dengan pesan `pondok.dashboard.module_unavailable` ("Modul belum tersedia."), tanpa error, tanpa tombol aksi, tanpa link (trait `InteractsWithDashboardModule`). Resource/halaman: `pondok.common.module_disabled` (403) |
| Tidak ada akses | Jangan tampilkan widget sama sekali (bukan widget kosong) |
| Memuat (Livewire) | `wire:loading` pada tombol: tombol disabled + teks "Menyimpan…" |
| Gagal | Notifikasi `danger` dengan pesan yang bisa ditindaklanjuti, bukan pesan exception |
| Berhasil | Notifikasi `success` yang menyebut objeknya: "Tahun ajaran 2026/2027 sekarang aktif." |

## 8. Aksi & konfirmasi

- Satu aksi utama (`primary`) per layar. Aksi sekunder `gray`. Aksi destruktif `danger`.
- Wajib `->requiresConfirmation()` untuk: hapus, batalkan tagihan/pembayaran, terima/tolak pendaftar, generate tagihan massal, ganti tahun ajaran aktif. Deskripsi modal menjelaskan **akibatnya**, misalnya "Tagihan yang dibatalkan tidak bisa dibayar lagi. Data tetap tersimpan."
- Data keuangan tidak punya tombol "Hapus"; yang ada "Batalkan".
- Label tombol berupa kata kerja + objek: `Catat pembayaran`, `Terima pendaftar`, `Cetak kuitansi`. Hindari `OK`, `Submit`, `Proses`.

## 9. Bahasa & nada

- Bahasa Indonesia baku tapi ramah, kalimat aktif, sapaan "Anda" di portal wali/PSB. Di panel admin, tanpa sapaan.
- Istilah pesantren dipakai apa adanya sesuai glosarium di `CLAUDE.md` (santri, musyrif, mukim, halaqah, ziyadah). Jangan diterjemahkan jadi "siswa", "pembina", atau "peserta".
- Pesan validasi menyebut cara memperbaiki: "Tanggal selesai harus setelah tanggal mulai.", bukan "Tanggal tidak valid."
- Istilah Arab yang sudah umum ditulis dengan ejaan yang dipakai di `lang/id` (murajaah, tasmi', syahadah); jangan membuat variasi ejaan baru.
- Tanpa emoji, tanpa tanda seru beruntun, tanpa huruf kapital semua.

## 10. Aksesibilitas

- Kontras teks minimal 4.5:1 (WCAG AA).
- Setiap input punya `<label>`; placeholder bukan pengganti label.
- Fokus terlihat: jangan hapus `outline`; pakai `focus-visible:ring-2 focus-visible:ring-primary-500`.
- Gambar bermakna punya `alt` deskriptif; gambar dekoratif `alt=""`.
- Urutan heading tidak loncat (`h1` → `h2` → `h3`), satu `h1` per halaman.
- Teks Arab ditandai `lang="ar" dir="rtl"`.

## 11. Dokumen cetak (kuitansi, kartu, surat)

- Dibuat sebagai view Blade khusus cetak, ukuran A5 (kuitansi) atau A4 (surat), hitam-putih aman (tidak bergantung warna).
- Kop: logo dan identitas lembaga dari `settings`, bukan di-hardcode.
- Nominal ditulis angka **dan** terbilang ("satu juta dua ratus lima puluh ribu rupiah").
- Selalu ada nomor dokumen, tanggal, dan nama petugas.

## 12. Larangan

- Library UI/JS tambahan (Bootstrap, DaisyUI, Flowbite, Alpine plugin, chart.js di portal) tanpa persetujuan user.
- Font atau aset dari CDN saat runtime.
- Override CSS Filament untuk mengubah gaya global.
- Warna hardcode, ukuran arbitrer, `!important`.
- Teks UI hardcode di luar `lang/id`.
- Ilustrasi makhluk bernyawa (manusia/hewan) di UI dan konten bawaan; gunakan ikon, pola geometris, atau foto asli lembaga.
- Hiasan selain bentuk geometris CSS (cincin, titik, garis); gambar atau SVG dekoratif eksternal.
- Animasi muncul-saat-scroll (konten tersembunyi sampai digulir) dan animasi dekoratif lain.
- Bayangan arbitrer `shadow-[…]`; pakai token `shadow-card`/`shadow-card-lg`/`shadow-button`.
- `text-accent-*` di Blade portal/PSB.

## 13. Alur kerja UI (anti-ui-slop)

Setiap pekerjaan UI dikerjakan dengan alur **anti-ui-slop**. Agent yang punya skill `anti-ui-slop` wajib memanggilnya (`/anti-ui-slop`) di awal pekerjaan UI, lalu menerapkannya **di dalam batas panduan ini**: token, status, format, dan larangan di atas selalu menang atas saran skill. Kontributor tanpa skill itu cukup mengikuti langkah berikut.

1. **Pahami layar sebelum menggambar.** Baca halaman/resource yang dituju, modul terkait di `docs/prd.md` dan `docs/erd.md`, lalu tulis (untuk diri sendiri) tiga hal: siapa penggunanya (peran dari daftar peran bawaan), apa **satu aksi utamanya**, dan data apa yang benar-benar ada di tabel.
2. **Pakai yang sudah ada.** Komponen Filament bawaan, komponen `x-ui.*`, token warna, ikon, dan pola resource yang sudah ada. Jangan membuat varian baru dari sesuatu yang sudah ada.
3. **Kontrak desain untuk layar besar.** Untuk layar baru atau redesain (dashboard, portal wali, wizard PSB, halaman tagihan), tulis kontrak singkat di deskripsi PR atau ke user sebelum coding:
   - Pengguna & tujuan
   - Aksi utama dan aksi sekunder
   - Urutan informasi (apa yang dibaca pertama, kedua, ketiga)
   - State yang dicakup (§7)
   - Perilaku di 360px

   Perbaikan kecil (ubah label, tambah kolom) tidak perlu kontrak.
4. **Data nyata, bukan pengisi.** Jangan mengarang metrik, grafik, aktivitas terbaru, testimoni, atau tombol yang tidak punya Action di belakangnya hanya supaya layout terlihat penuh. Setiap angka di dashboard harus berasal dari query; setiap tombol harus memanggil Action atau route yang ada. Konten contoh di seeder memakai data yang masuk akal untuk pesantren (nama santri Indonesia, nominal SPP wajar), bukan "Lorem ipsum" atau "John Doe".
5. **Semua state, bukan hanya jalur bahagia.** Loading, kosong, gagal, berhasil, disabled, tanpa izin, dan modul mati (§7) dikerjakan bersamaan dengan layar utamanya, bukan "nanti".
6. **Lihat hasilnya.** Buka halaman di `https://pondok.test` (Herd, tanpa menjalankan dev server; ikuti aturan RAM di CLAUDE.md global). Cek lebar desktop dan 360px. Perbaiki teks terpotong, elemen bertumpuk, scroll horizontal, tombol yang tidak berbuat apa-apa, dan kontras buruk. Bila tidak bisa merender, katakan ke user bahwa tampilan belum dicek secara visual.
7. **Laporkan.** Ringkas apa yang berubah, state mana yang dicakup, dan penyimpangan dari panduan ini (bila ada).

**Tanda UI slop yang harus ditolak saat review:**

- Kartu statistik berderet tanpa tindak lanjut ("Total santri: 312" tanpa link atau konteks).
- Hero generik dengan gradien, ilustrasi stok, atau slogan kosong ("Solusi pendidikan modern terpadu").
- Semua elemen sama tebal/besar sehingga tidak ada yang terbaca pertama.
- Ikon dekoratif di setiap judul dan setiap baris.
- Grafik yang tidak menjawab pertanyaan apa pun.
- Teks bahasa Inggris atau placeholder yang tertinggal ("Dashboard", "No data", "Lorem").
- Layout yang hanya bagus di desktop.

Referensi eksternal (mis. UIZZE MCP) boleh dipakai hanya bila tersedia dan ada pertanyaan visual yang konkret. Hasilnya diadaptasi ke token dan komponen Pondok, tidak disalin gayanya.

---

Perubahan pada panduan ini (token baru, status baru, ikon baru) dilakukan di file ini **dalam commit yang sama** dengan kode yang membutuhkannya.
