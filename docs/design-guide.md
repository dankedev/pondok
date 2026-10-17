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
- [ ] Portal/PSB: navy dari logo untuk aksi, emas hanya latar/garis/hiasan (tidak pernah `text-accent-*`), teks isi `gray-600`/`gray-700` (§2).
- [ ] Bayangan hanya token `shadow-card`/`shadow-card-lg`/`shadow-button`; tanpa nilai arbitrer `shadow-[…]` (§6).
- [ ] Uang tampil `Rp 1.250.000` (§5). Tanggal tampil `15 Okt 2026` (§5).
- [ ] Status tampil sebagai badge dengan warna sesuai tabel status (§4).
- [ ] Ada empty state, dan state "Modul belum tersedia" untuk widget/halaman modul non-inti (§7).
- [ ] Layar portal/PSB dicek di lebar 360px tanpa scroll horizontal (§6).
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

| Peran | Filament (`AdminPanelProvider`) | Tailwind (`resources/css/app.css`) | Pakai untuk |
|---|---|---|---|
| Primary | `Color::Emerald` | `primary-*` navy logo Al-Khair (skala di bawah, 950 = `#000A3C`) | Tombol utama, link, elemen aktif |
| Accent | — | `accent-*` emas logo Al-Khair (500 = `#F8B800`) | Portal/PSB: latar badge "Pendaftaran dibuka", garis aksen footer, `blockquote`, hiasan. Bukan warna teks. |
| Netral | `gray` (bawaan) | `gray-*` (alias slate) | Teks, border, latar |
| Success | `success` | `success-*` (green) | Lunas, aktif, disetujui, diterima |
| Warning | `warning` | `warning-*` (amber) | Belum lunas sebagian, menunggu, akan jatuh tempo |
| Danger | `danger` | `danger-*` (red) | Terlambat, ditolak, batal, aksi hapus |
| Info | `info` | `info-*` (sky) | Proses berjalan, informasi netral |

**Portal dan panel sengaja berbeda primer.** Panel admin Filament tetap `Color::Emerald` dan tidak memuat `app.css` (tanpa `viteTheme()`), sehingga token navy/emas hanya berlaku di portal publik, PSB, dan layout publik. Acuan gaya portal: [`docs/brand/referensi-gaya-coursex.md`](brand/referensi-gaya-coursex.md).

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

Pemakaian navy: tautan `primary-700`, tombol utama/chip aktif/halaman aktif/batang langkah PSB `primary-900` (hover `primary-800`), judul hero dan footer `primary-950`, cincin fokus `primary-500` (dengan `ring-offset-2` pada tombol navy).

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
- Dark mode: panel Filament mengikuti bawaan Filament. Portal publik, portal wali, dan PSB **hanya mode terang** (Fase 1–2). Jangan menambahkan kelas `dark:` di Blade portal.

## 3. Tipografi & ikon

- **Huruf Latin:** Plus Jakarta Sans 400/600/700 (bobot 500 tidak dipakai portal, jadi tidak dibundel), di-self-host lewat plugin font Vite (`bunny()` di `vite.config.js`, diunduh saat `npm run build` ke `public/build`) dan dimuat lewat `@fonts`. Jangan menambah font lain untuk teks Latin. Panel Filament tetap memakai font bawaannya.
- **Teks Arab** (nama surah, ayat, doa, kaligrafi nama lembaga): Noto Naskh Arabic (400/700, subset `arabic`, tanpa preload) yang di-self-host lewat plugin font Vite yang sama, dibungkus elemen dengan `lang="ar" dir="rtl"` dan kelas `font-arabic`. Ukuran minimal 1.25× teks sekitarnya. Jangan pernah render teks Arab dengan font Latin.
- Skala yang dipakai (Tailwind): `text-sm` (keterangan, tabel), `text-base` (isi), `text-lg` (judul kartu), `text-2xl` (judul halaman panel). Jangan memakai ukuran lain tanpa alasan.
- Skala judul portal/PSB:

  | Elemen | Kelas |
  |---|---|
  | `h1` hero beranda | `text-4xl font-bold tracking-tight text-primary-950 sm:text-5xl lg:text-6xl text-balance` |
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
| Wali | `UserGroup` |
| Staff | `IdentificationCard` |
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

### Portal publik, portal wali, PSB (Blade + Livewire)

- **Mobile-first.** Rancang untuk 360px dulu, lalu `sm`/`md`/`lg`. Wali dan pendaftar hampir semua memakai HP.
- Kontainer: `mx-auto max-w-6xl px-4 sm:px-6` (portal publik), `max-w-2xl` (portal wali, form PSB).
- Spasi memakai skala Tailwind 4 kelipatan: `gap-4`/`gap-6`, `p-4`/`p-6`. Section beranda `py-16 lg:py-24`, kontainer halaman dalam `py-12 lg:py-20`. Hindari nilai arbitrer (`p-[13px]`, `w-[85%]`); pakai pecahan skala (`w-5/6`).
- Sudut: `rounded-full` (pill) untuk tombol, input, select, input file, badge, chip filter, paginasi, item nav, dan tautan "Daftar". `rounded-xl` untuk kartu, alert, empty state, foto, iframe, menu lipat, dan ubin pilihan radio.
- Bayangan hanya token di `@theme`: `shadow-card` (kartu, `border-gray-100`), `shadow-card-lg` (kartu besar, menu lipat), `shadow-button` (tombol utama navy). Tanpa `shadow-[…]`.
- Input dan select memakai kelas komponen `.ui-input` (pill, `px-5`, fokus `ring-primary-500`); kelas tambahan per field (`tabular-nums`) tetap ditulis di elemen.
- Beranda: hero dan slider putih, lalu section yang dirender berselang-seling `bg-gray-50` → `bg-white` (penghitung `$band()` di `home.blade.php`, hanya dipanggil section yang tampil). Section kosong tidak dirender.
- Hiasan cincin emas setengah lingkaran (`.portal-decor-ring`) dan pola titik navy muda (`.portal-decor-dots`): murni CSS, hanya di hero (titik, `lg`) dan slider (cincin + titik, `sm`), sebagai `<div aria-hidden="true">` kosong di belakang konten (`-z-10` dalam wadah `isolate`), selalu berkelas `hidden` di bawah breakpoint. Section berhias memakai `overflow-x-clip`.
- Target sentuh minimal 44×44px. Tombol utama di portal wali/PSB lebar penuh di mobile (`w-full sm:w-auto`).
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
