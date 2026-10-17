# Audit UI/UX portal publik dan pengaturan konten

Tanggal: 17 Okt 2026. Kondisi: `main` setelah F035, F036, dan F037 (commit `bc19b07`), dengan data DemoSeeder Ma'had Al-Khair.
Metode: portal dibuka di browser pada lebar 360px, 1024px, dan 1440px. Panel admin dinilai dari kode form Filament (`app/Filament/Clusters/InstitutionProfile/Pages/*`, `StaticPageResource`, `PostForm`, `AlbumResource`, `VideoResource`, `CategoryResource`) beserta teks bantuannya di `lang/id/pondok.php`. Login admin di browser tidak dilakukan karena akses kredensial diblokir oleh pengaman sesi, jadi bagian admin **belum dicek visual**.
Pembanding: dokumentasi resmi Ghost ([navigasi](https://ghost.org/help/updating-navigation/), [custom theme settings](https://docs.ghost.org/themes/custom-settings/), [dynamic routing](https://docs.ghost.org/themes/routing/)), ditambah WordPress block theme dan Statamic bila relevan.

Laporan ini hanya berisi analisis. Tidak ada kode, test, data, atau `feature_list.json` yang diubah.

---

## 1. Kenapa terasa tidak cocok

1. **Struktur situs disimpan sebagai "profil lembaga".** Menu, susunan beranda, dan footer ada di *Pengaturan → Profil lembaga*, sedangkan halaman, berita, galeri, dan video ada di grup *Konten*. Untuk mengurus satu situs, admin harus bolak-balik antara dua grup yang namanya tidak menjelaskan isinya. Ghost memisahkan tiga hal: **konten** (post/page), **struktur** (navigasi, routing), dan **tampilan** (brand dan theme settings). Di Pondok ketiganya bercampur dalam satu cluster.
2. **Skema data mengunci satu gaya lembaga.** Field seperti *Nama dalam bahasa Arab* di hero, *Kalimat pembuka* (yang diisi basmalah), *Kutipan: teks Arab, terjemahan, sumber* di footer, serta pasangan *Video profil* dan *Video PSB* adalah bentuk halaman satu pesantren yang dijadikan kolom database. Sekolah umum, TK, atau pesantren lain dengan gaya berbeda tidak punya padanannya. Karena kolomnya tetap, Al-Khair sendiri juga tidak bisa keluar dari pola itu. Inilah yang disebut user "terlalu mendefinisikan".
3. **Satu data diketik di banyak tempat, lalu isinya berbeda.** Alamat, telepon, dan email ada di *Identitas*, daftar kontak ada di *Kontak & lokasi*, dan isi halaman `/kontak` diketik lagi sebagai teks bebas. Di data demo, halaman Kontak menulis telepon `0735 000 0170` sementara kartu otomatis menampilkan `0735-0000-170`. Di satu layar, informasi kontak tampil tiga kali: isi halaman, kartu "Informasi kontak", dan footer.
4. **Hero tidak punya pusat visual.** Sebelum pesan utama, pengunjung melewati empat lapis pembuka: badge PSB, nama Arab, "Bismillahirrahmanirrahim", lalu judul berupa sapaan ("Ahlan wa Sahlan di …"). Kolom kanan kosong dan hanya berisi pola titik. Foto dipisah ke section slider di bawahnya, dan di layar 1024px slider itu terpotong di batas layar. Hasilnya tidak seperti acuan Course X yang memakai hero dua kolom dengan foto. Di 360px, layar pertama habis untuk teks tanpa satu gambar pun.
5. **Footer lebih berat daripada isi halaman.** Blok ayat besar (teks Arab, terjemahan, sumber) tampil di *setiap* halaman, termasuk 404, form PSB, dan detail berita. Di halaman pendek seperti Profil, Video, atau 404, footer lebih tinggi daripada konten. Nama lembaga juga diulang di judul footer dan di kalimat deskripsi.
6. **Navigasi tidak mencerminkan isi situs.** Header berisi halaman profil (Tentang Yayasan, Pengurus, Selayang Pandang, Kontak), sedangkan Berita, Galeri, Video, dan Info PSB hanya bisa dicapai lewat footer atau tombol. Menu utama dan tautan footer memakai dua model berbeda: menu berupa daftar semua halaman dengan saklar *Tampilkan*, footer berupa repeater tautan. Menu dibatasi 8 item tanpa submenu, jadi halaman "Tentang" tidak bisa dikelompokkan.
7. **Taksonomi berita bertumpuk.** Sebuah berita punya *jenis* (Kegiatan/Prestasi/Berita), *kategori* (di demo ada "Kegiatan Al-Khair" dan "Prestasi Al-Khair", yang mengulang jenis), dan *jenjang* (SD/SLTP/SLTA, padahal lembaga ini tidak punya SD dan menamai jenjangnya MTW/SMP dan TSN/SMA). Akibatnya halaman `/berita` dibuka dengan tiga baris chip filter sebelum berita pertama.
8. **Grid tidak menyesuaikan jumlah isi.** Lima keunggulan dalam grid empat kolom menyisakan satu kartu sendirian. Satu prestasi tampil di grid dengan dua pertiga ruang kosong. Tiga album tampil dalam grid empat kolom. Halaman jadi terasa belum selesai, padahal datanya ada. Gambar placeholder demo berwarna hijau dan krem juga tidak senada dengan navy dan emas.

Inti masalahnya: **portal dibangun sebagai formulir berisi kolom-kolom tetap, bukan sebagai situs yang disusun dari blok dan tautan.** Gaya visual F035 sudah benar arahnya, tetapi model kontennya yang membuat situs terasa kaku dan "bukan milik kami".

---

## 2. Temuan per area: portal publik

Format setiap temuan: **Masalah**, lalu *Dampak ke lembaga lain*, lalu **Rekomendasi**.

### 2.1 Header dan navigasi
- **Masalah:** Item header bergantung pada saklar per halaman di editor Menu. Item bawaan modul (Berita, Galeri, Video, Info PSB) bisa kalah oleh batas 8 item, dan di demo semuanya hilang dari header. Di 360px, panel menu juga mengulang tombol *Masuk* dan *Daftar*. Logo mobile tampil sangat kecil (±32px).
  *Dampak:* sekolah yang situsnya berfokus pada berita atau PSB tidak bisa langsung mengarahkan pengunjung ke sana tanpa memahami batas tersembunyi itu.
  **Rekomendasi:** jadikan menu daftar tautan (lihat §5.2) dengan submenu satu tingkat. Tautan modul menjadi pilihan tujuan, bukan item bawaan yang selalu ada. Tinggi logo mobile minimal 40px.
- **Masalah:** label "Masuk" ke `/admin` tampil untuk semua pengunjung.
  *Dampak:* wali dan calon wali melihat pintu masuk admin yang bukan untuk mereka.
  **Rekomendasi:** jadikan tautan login sebagai item navigasi biasa yang boleh dihapus admin, atau pindahkan ke footer.

### 2.2 Beranda: hero
- **Masalah:** empat lapis pembuka, judul berupa sapaan, kolom kanan kosong, slider terpisah dan terpotong (lihat §1 poin 4). Field `hero_greeting` diberi contoh basmalah di dokumen rencana, jadi lembaga lain pun akan meniru pola yang sama.
  *Dampak:* lembaga yang tidak memakai nama Arab atau kalimat pembuka mendapat hero yang hanya berisi judul dan teks di kiri, tanpa gambar.
  **Rekomendasi:** hero menjadi **blok** dengan field generik: *label kecil (opsional), judul, teks, gambar atau album, tombol utama, tombol kedua*. Gambar ditaruh di kolom kanan (dua kolom di ≥1024px, gambar di atas judul di 360px). Nama Arab tidak masuk hero secara otomatis: lembaga yang ingin menampilkannya bisa memasukkannya ke gambar logo, atau memakai label kecil/teks dengan arah RTL.
  Arah judul: pesan tentang lembaga ("Pendidikan berasrama untuk putra jenjang SMP dan SMA"), bukan sapaan. Ini cukup sebagai contoh di teks bantuan, tidak perlu jadi konten bawaan.

### 2.3 Beranda: section lain
- **Keunggulan:** judul seksi dan daftar sudah generik, ini model yang baik. Masalahnya ada di grid (§1 poin 8) dan tidak adanya ikon atau gambar, sehingga semua kartu terlihat sama. **Rekomendasi:** grid adaptif (2/3/4 kolom sesuai jumlah, item sisa di tengah) dan ikon opsional dari daftar Heroicons.
- **Video profil dan video PSB:** tampil tanpa judul seksi, sebagai dua kolom di mana pun posisinya. Di demo keduanya kosong. **Rekomendasi:** cukup satu blok "Video" yang bisa dipakai berulang (pilih video, judul seksi opsional), tanpa nama khusus "profil" atau "PSB".
- **"Tentang Yayasan":** ringkasannya diambil otomatis dari halaman ber-slug `profil`, dan tautannya dikunci ke `/profil`. Lembaga yang halaman profilnya berbeda tidak bisa mengaturnya. **Rekomendasi:** blok "Teks + gambar" dengan tombol yang tujuannya dipilih admin. Ringkasan halaman diambil dari field *ringkasan* milik halaman itu sendiri (§5.4).
- **Kabar terbaru, Prestasi, Galeri:** data otomatis sudah tepat. Masalahnya hanya tautan "Lihat semua" yang tidak konsisten (ada di Galeri, tidak ada di Kabar terbaru) dan grid yang tidak menyesuaikan jumlah. **Rekomendasi:** semua blok otomatis punya opsi judul seksi, jumlah item, dan tautan "Lihat semua".
- **Urutan section** saat ini tetap di kode. Lembaga yang ingin PSB atau Galeri di atas tidak bisa mengubahnya. **Rekomendasi:** urutan dan saklar per blok (§5.3).

### 2.4 Berita dan kegiatan (`/berita`, detail)
- **Masalah:** tiga baris filter (jenis, kategori, jenjang). Chip jenjang menampilkan unit yang tidak punya berita. Di detail berita tanpa sampul, judul langsung disusul isi tanpa jeda visual. Tidak ada berita terkait atau tautan ke kategori.
  *Dampak:* lembaga dengan satu jenjang tetap melihat filter jenjang. Lembaga yang tidak membedakan "kegiatan" dan "berita" tetap mendapat dua taksonomi.
  **Rekomendasi:** satu taksonomi utama (kategori bebas), dan jenjang hanya tampil bila ada lebih dari satu jenjang yang dipakai. Chip disembunyikan bila jumlah beritanya nol. Detail berita menampilkan kategori sebagai tautan dan 3 berita terkait dari kategori yang sama.

### 2.5 Galeri dan album
- **Masalah:** sudah bersih. Masalahnya hanya grid 3 album di empat kolom dan placeholder hijau.
  **Rekomendasi:** grid adaptif dan placeholder netral memakai token (navy muda).

### 2.6 Video
- **Masalah:** halaman `/video` bisa dibuka walau kosong ("Belum ada video."), dan tidak ada di menu.
  **Rekomendasi:** halaman tetap boleh ada, tetapi tujuan navigasi "Video" disembunyikan otomatis bila kosong. Ini sama dengan aturan "section kosong disembunyikan".

### 2.7 Halaman statis
- **Masalah:** halaman Profil memiliki tautan tetap "Visi dan Misi →" di kode. Tiga halaman sistem (profil, visi-misi, kontak) tidak bisa dihapus, padahal sekolah lain mungkin tidak punya halaman visi-misi terpisah. Halaman tidak punya ringkasan, gambar unggulan, atau meta deskripsi.
  *Dampak:* lembaga dipaksa memiliki struktur tiga halaman yang sama.
  **Rekomendasi:** hapus konsep "halaman sistem". Semua halaman setara, dan yang berbeda hanya **template** halaman (Biasa, Kontak, Lebar penuh). Halaman bertemplate "Kontak" otomatis menampilkan kartu kontak dari Pengaturan, sehingga admin tidak perlu mengetik ulang alamat.

### 2.8 Kontak (`/kontak`)
- **Masalah:** informasi kontak tampil tiga kali, dan isinya berbeda (§1 poin 3).
  **Rekomendasi:** template "Kontak" berisi isi halaman (teks bebas, misalnya jam layanan), kartu kontak otomatis, dan tombol peta. Data kontak hanya diketik di satu tempat.

### 2.9 PSB (form dan cek status)
- **Masalah:** form langsung dimulai dengan "Pilihan pendaftaran" tanpa ringkasan syarat atau biaya, karena F038 belum ada. Footer ayat yang besar mengikuti di bawah form. Halaman cek status sudah ringkas dan baik.
  **Rekomendasi:** footer ringkas di alur PSB. Untuk F038, lihat §6: Info PSB sebaiknya memakai model blok yang sama, bukan menambah banyak field khusus.

### 2.10 404
- **Masalah:** pesan dan dua tombol sudah baik, tetapi footer ayat yang besar membuat halaman terasa berat.
  **Rekomendasi:** footer ringkas.

### 2.11 Footer
- **Masalah:** urutannya kutipan Arab dan terjemahan dulu, baru nama, deskripsi, alamat, kontak berlabel, sosial (teks saja tanpa ikon), dan tautan. Di 1024px kolom kanan kosong. Tautan bawaan sistem ("Cek status pendaftaran", "Verifikasi keaslian surat") selalu ditambahkan dan tidak bisa diurutkan. Tidak ada logo.
  *Dampak:* lembaga tanpa kutipan mendapat footer yang cukup baik. Lembaga dengan kutipan mendapat pita besar di semua halaman. Field "Teks Arab" tidak berguna untuk sekolah umum.
  **Rekomendasi:** footer generik (§5.5): logo dan deskripsi, kontak otomatis, sosial dengan ikon, *Navigasi footer* (daftar tautan yang sama modelnya dengan menu utama, tempat tautan sistem menjadi pilihan tujuan), dan maksimal dua **blok teks bebas** (judul, teks, opsi arah kanan-ke-kiri). Kutipan ayat bisa diisi lewat blok teks bebas, atau sebagai blok "Kutipan" di beranda saja.

### 2.12 Responsif (360px, 1024px, 1440px)
- Tidak ada scroll horizontal di 360px (lebar dokumen 360). Beranda di 360px setinggi ±8.150px, terutama karena keunggulan ditumpuk penuh dan footer panjang. Di 1024px, slider hero terpotong di kanan dan footer hanya memakai dua kolom. Di 1440px, kolom kanan hero kosong menjadi sangat terlihat.
- **Rekomendasi:** keunggulan di 360px memakai daftar ringkas (ikon dan judul, keterangan lebih pendek) atau dua kolom kecil. Footer di 360px memakai bagian yang bisa dilipat untuk tautan.

---

## 3. Temuan per area: panel admin konten

Sudut pandang: admin lembaga yang awam, tanpa pengalaman CMS.

### 3.1 Penempatan menu admin
- **Masalah:** *Profil lembaga* (Identitas, Kontak & lokasi, Beranda, Footer, Menu) ada di grup **Pengaturan**, sedangkan Halaman, Berita, Kategori, Album, dan Video ada di grup **Konten**. Admin awam mencari "ubah menu" atau "ubah beranda" di Konten, bukan di Profil lembaga.
- **Rekomendasi:** susunan baru (§5.1). Grup *Situs* berisi Beranda, Navigasi, Halaman, Berita, Kategori, Galeri, dan Video. Grup *Pengaturan → Situs* berisi Identitas dan logo, Kontak dan sosial, serta Tampilan.

### 3.2 Identitas
- **Masalah:** ada empat gambar (logo cetak, logo desktop, logo mobile, favicon). Aturan user "logo cukup dua pilihan: desktop dan mobile" sudah benar untuk portal, tetapi *logo cetak* untuk kop surat masih ada di halaman yang sama dan membingungkan. *Nama Arab* dan *Slogan* adalah field identitas yang sebenarnya dipakai sebagai isi hero. Bagian "Alamat & kontak resmi" ada di Identitas, sedangkan "Kontak & lokasi" adalah halaman terpisah.
- **Rekomendasi:** Identitas berisi nama, nama singkat, deskripsi singkat (satu field untuk meta, footer, dan fallback hero), logo desktop, logo mobile, favicon, dan gambar bagikan bawaan (OG). Logo cetak dipindah ke pengaturan Dokumen/kop surat (bila kosong, memakai logo desktop). Nama Arab dipindah ke kop surat bila memang dipakai di sana. Alamat, telepon, dan email pindah ke halaman Kontak.

### 3.3 Kontak & lokasi
- **Masalah:** daftar kontak berlabel dan media sosial sudah generik, ini model yang baik. Yang kurang hanya satu sumber: telepon kantor dan alamat ada di Identitas.
- **Rekomendasi:** satu halaman *Kontak & sosial* berisi alamat, tautan peta, email, daftar kontak (satu kontak bisa ditandai "dicetak di kop dan kuitansi"), dan media sosial.

### 3.4 Beranda
- **Masalah:** form tetap: Sambutan (kalimat pembuka, judul, subjudul), Keunggulan, dan Media beranda (album slider, video profil, video PSB). Admin tidak bisa menambah, menghapus, atau mengurutkan bagian. Bagian otomatis (berita, prestasi, galeri) tidak muncul di form sama sekali, jadi admin tidak tahu dari mana asalnya atau cara menyembunyikannya.
- **Rekomendasi:** pembuat beranda berbasis blok memakai komponen `Builder` Filament (§5.3). Semua bagian, termasuk yang otomatis, terlihat sebagai blok yang bisa diurutkan, diberi judul, dan disembunyikan.

### 3.5 Footer
- **Masalah:** bagian pertama form adalah "Kutipan: Teks Arab, Terjemahan, Sumber". Ini yang disebut user "terlalu mendefinisikan". Tautan cepat sudah memakai model tautan (jenis Halaman/Tautan), yang justru lebih baik daripada editor Menu.
- **Rekomendasi:** hapus kelompok Kutipan dan ganti dengan "Blok teks" (maks. 2: judul, teks, saklar arah kanan-ke-kiri). Tautan cepat pindah ke Navigasi sebagai daftar *Footer*.

### 3.6 Menu (F037), menjawab pertanyaan user
- **Masalah:** editor menampilkan *semua* halaman beserta item bawaan sebagai daftar tetap (tidak bisa ditambah atau dihapus), masing-masing dengan saklar *Tampilkan* dan label singkat. Halaman baru otomatis masuk daftar dalam keadaan tersembunyi. Ada batas 8 item yang tampil, lalu sisanya diam-diam tidak muncul. Tombol "Daftar PSB" diatur di sini juga.
- **Jawaban pertanyaan user:** "kenapa opsi tampil di menu tidak di pengaturan halaman saja, dan menu cukup daftar tautan seperti quick link?"
  - User benar bahwa model sekarang adalah gabungan yang canggung: menunya bukan daftar tautan, tetapi juga bukan pengaturan per halaman.
  - Ghost memilih **daftar tautan**. Navigasi primer dan sekunder hanya berisi *label + URL* dan bisa diurutkan dengan diseret. Halaman baru **tidak** otomatis masuk menu ([Ghost Help](https://ghost.org/help/updating-navigation/)). WordPress block theme juga memakai blok Navigasi yang berisi tautan.
  - Menaruh saklar di pengaturan halaman saja tidak cukup, karena menu juga berisi tujuan yang bukan halaman: daftar berita, galeri, Info PSB, URL luar, atau kategori. Urutan juga tetap butuh satu tempat.
  - **Rekomendasi:** menu menjadi **daftar tautan**, dengan model yang sama untuk *Menu utama* dan *Footer*. Setiap item berisi label dan tujuan (Halaman / Berita / Kategori / Galeri / Video / Info PSB / Cek status PSB / Verifikasi surat / URL), plus submenu satu tingkat. Sebagai kemudahan, form halaman baru diberi tombol "Tambahkan ke menu utama" yang hanya menambah satu item ke daftar. Sumber kebenarannya tetap daftar menu.

### 3.7 Halaman statis
- **Masalah:** form hanya berisi judul, slug, status, dan isi. Tidak ada ringkasan, gambar unggulan, meta, atau template. Label "Halaman sistem" dan slug yang dikunci menambah konsep yang harus dipahami admin.
- **Rekomendasi:** panel samping "Pengaturan halaman" mengikuti Ghost: slug, status, ringkasan, gambar unggulan dan teks alt, judul dan deskripsi untuk mesin pencari, serta template (§5.4).

### 3.8 Berita
- **Masalah:** form punya *jenis*, *kategori*, dan *jenjang*, padahal fungsinya tumpang tindih. Ada sampul dan URL YouTube. Tidak ada meta SEO dan tidak ada tanda "unggulan".
- **Rekomendasi:** jenis dilebur ke kategori (berita yang dibuat dari modul Prestasi otomatis berkategori "Prestasi"). Jenjang tetap ada, tetapi sebagai filter opsional. Tambahkan meta dan tanda "unggulan" seperti `featured` di Ghost.

### 3.9 Galeri, video, kategori
- **Masalah:** kategori memiliki *tipe* (post/album), sehingga admin harus memahami dua set kategori. Video punya `is_featured` dan `sort_order`, sementara berita dan album tidak, jadi aturan "unggulan" berbeda antar konten.
- **Rekomendasi:** samakan perilakunya: setiap konten (berita, album, video) punya tanda *unggulan*, dan blok beranda memilih "terbaru" atau "unggulan".

---

## 4. Perbandingan dengan Ghost dan yang diadopsi

| Konsep Ghost | Cara kerja Ghost | Kondisi Pondok | Diadopsi? |
|---|---|---|---|
| Navigasi primer dan sekunder | Daftar *label + URL*, diseret untuk urutan; halaman tidak otomatis masuk | Menu: daftar halaman dengan saklar, batas 8; footer: repeater tautan terpisah | **Ya.** Dua daftar dengan model yang sama, ditambah pemilih tujuan (lebih ramah daripada URL mentah) dan submenu satu tingkat |
| Pengaturan per post/page | Slug, tanggal, tag, excerpt, feature image dan alt, meta title/description, kartu sosial, template, featured | Halaman: judul, slug, status, isi. Berita: ada excerpt dan sampul, tanpa meta | **Ya.** Ringkasan, gambar unggulan, meta, template, unggulan. Kartu sosial cukup memakai meta dan gambar unggulan |
| Pengaturan situs minimal | Judul, deskripsi, logo, icon, cover, accent color | Identitas: 4 gambar, nama Arab, slogan, alamat, telepon, email | **Ya.** Nama, nama singkat, deskripsi, 2 logo, favicon, gambar bagikan, warna aksen |
| Custom theme settings | Maks. 20 setting, tipe select/boolean/color/image/text, kelompok *Site-wide/Homepage/Post*; tidak untuk perubahan layout rumit atau tweak per elemen ([docs](https://docs.ghost.org/themes/custom-settings/)) | Field khusus di skema (kutipan Arab, video PSB) | **Ya, sebagai batasan desain.** Setiap template mendeklarasikan maks. ±12 setting generik. Field khas lembaga tidak boleh masuk skema |
| Homepage dan dynamic routing (`routes.yaml`) | Route ke template, *collection* (satu post satu koleksi), *channel* (filter, banyak), *taxonomy* tag/author | Route tetap di kode; beranda tetap | **Sebagian.** Tidak perlu `routes.yaml` (berat untuk admin awam). Yang diambil konsepnya: kategori sebagai arsip otomatis (`/berita/kategori/{slug}`) yang bisa dipilih sebagai tujuan menu. Beranda memakai blok |
| Tags (publik dan internal `#`) | Satu taksonomi fleksibel, *primary tag* untuk URL/tampilan | Jenis, kategori, dan jenjang bertumpuk | **Ya.** Satu taksonomi kategori. Jenjang sebagai filter opsional |
| Tema yang bisa diganti | Theme upload (Handlebars) | Satu set Blade | **Bertahap.** Tahap 1: token warna, font, dan radius dari admin. Tahap 2: beberapa *preset* template bawaan di repo (bukan upload tema, demi keamanan shared hosting) |
| Konten di konten, struktur di struktur | Teks hanya di post/page; navigasi dan routing terpisah; theme settings untuk tampilan | Teks konten (sapaan, ayat) disimpan sebagai pengaturan profil | **Ya, ini prinsip utamanya.** Lihat §5 |

Yang **tidak** diadopsi: Members/Portal/newsletter Ghost, code injection (berisiko di instance yang dikelola orang awam), upload tema, dan `routes.yaml`.

Pembanding lain:
- **WordPress block theme:** template part (header/footer) dan pola blok. Konsep "footer = beberapa blok" diambil, tanpa editor bebas penuh.
- **Statamic:** *Globals* untuk data situs (kontak, sosial) dan *Navigation* sebagai struktur tautan yang terpisah dari halaman. Ini cocok dengan pemisahan yang diusulkan.

---

## 5. Usulan model konten dan pengaturan baru

Prinsip: (a) konten di konten, struktur di struktur, tampilan di tampilan; (b) satu data satu tempat; (c) field generik dan bisa diulang, bukan field khas satu lembaga; (d) tetap tanpa konten bawaan, dan bagian kosong tidak dirender; (e) semua disimpan di `settings` (JSON) atau tabel yang ada, render dengan Blade per blok, tanpa proses tambahan, jadi cocok untuk cPanel.

### 5.1 Susunan menu admin

| Grup | Isi |
|---|---|
| **Situs** (baru, menggantikan grup Konten) | Beranda (blok) · Navigasi · Halaman · Berita · Kategori · Galeri · Video |
| **Pengaturan → Situs** | Identitas & logo · Kontak & sosial · Tampilan (warna, font, template) |
| **Pengaturan → Dokumen** | Kop surat (logo cetak, nama Arab bila dipakai, telepon cetak) |

### 5.2 Navigasi
- Setting `portal.navigation` berisi `{ primary: Item[], footer: Item[] }`. `Item` terdiri dari `label`, `target` (`page:{id}` · `posts` · `category:{id}` · `albums` · `videos` · `psb.info` · `psb.status` · `letters.verify` · `url`), `url` (bila target url), dan `children` (opsional, satu tingkat, hanya untuk primary).
- Item yang tujuannya tidak tersedia (modul mati, halaman draf/terhapus, daftar kosong) disembunyikan otomatis. Aturan ini sudah ada di F037 dan dipertahankan.
- Tombol CTA header (Daftar PSB) menjadi satu field "Tombol header" (label + tujuan, opsional), bukan saklar khusus PSB.
- **Dihapus:** daftar tetap semua halaman dengan saklar *Tampilkan*, batas 8 yang diam-diam memotong (diganti validasi saat menyimpan: "maks. 7 item utama"), dan tautan bawaan footer yang ditambahkan diam-diam.
- **Migrasi:** `portal.menu_items` yang `visible = true` menjadi `primary`, `school.footer.links` menjadi `footer`, dan tautan sistem footer yang dulu otomatis ditambahkan ke daftar `footer` bila modulnya aktif.

### 5.3 Beranda berbasis blok
Setting `portal.home_blocks` berisi daftar blok, diedit dengan `Filament\Forms\Components\Builder`. Setiap blok punya `type`, `visible`, dan `data`.

| Blok | Field | Menggantikan |
|---|---|---|
| Hero | label kecil, judul, teks, gambar *atau* album, tombol 1 & 2 (label + tujuan) | `hero_greeting/title/subtitle`, `hero_album_id`, nama Arab dan slogan di hero |
| Teks + gambar | judul, teks, gambar, posisi gambar kiri/kanan, tombol | ringkasan "Tentang" yang terkunci ke slug `profil`; sambutan pimpinan |
| Daftar poin | judul seksi, item[] (ikon opsional, judul, teks) | `highlights_heading`, `highlights`; juga dipakai untuk program, fasilitas, dll. |
| Angka | item[] (angka, label) | (baru, sesuai pola Course X) |
| Kutipan | teks, sumber, saklar kanan-ke-kiri untuk baris pertama | kutipan ayat footer (pindah ke beranda, opsional) |
| Video | judul seksi, video[] | `profile_video_id`, `psb_video_id` |
| Otomatis: Berita / Prestasi / Galeri / Video | judul seksi, jumlah, sumber (terbaru/unggulan/kategori), tautan "Lihat semua" | section tetap di `home.blade.php` |
| Otomatis: Info PSB ringkas | judul, teks (opsional); status gelombang, jenjang, dan biaya diambil dari modul | badge dan CTA PSB di hero |
| Ajakan (CTA) | judul, teks, tombol | (baru) |

Aturan: blok tanpa isi tidak dirender. Instalasi baru dimulai dengan daftar blok **kosong** dan satu tombol "Tambah blok". Saat semua kosong, beranda hanya menampilkan nama lembaga dan footer, sama seperti aturan F036. Katalog blok dibatasi (±10) agar tetap sederhana, mengikuti semangat batas 20 setting Ghost.

### 5.4 Pengaturan per halaman dan berita
Tambahan kolom `excerpt`, `featured_image_path` dan `featured_image_alt`, `meta_title`, `meta_description` (untuk `static_pages`, sebagian sudah ada di `posts`), `template` (khusus halaman: `default` · `contact` · `wide`), dan `is_featured` (posts, albums).
- **Dihapus:** konsep "halaman sistem" dan slug yang dikunci. Halaman Kontak cukup memakai template `contact`. Tautan "Visi dan Misi →" yang tetap di kode dihapus; admin menulis tautan sendiri di isi halaman.
- **Migrasi:** tiga halaman bawaan tetap ada sebagai halaman biasa (halaman `kontak` diberi template `contact`). Slug lama tetap sama, jadi URL tidak rusak.

### 5.5 Footer generik
Footer dirangkai dari: logo dan deskripsi singkat (Identitas), kontak dan sosial (otomatis, dengan saklar tampil/sembunyi), navigasi footer (§5.2), maks. 2 blok teks bebas (judul, teks, saklar kanan-ke-kiri), dan baris hak cipta.
- **Dihapus:** `footer.quote_arabic`, `footer.quote_translation`, `footer.quote_source` sebagai field khusus.
- **Migrasi:** bila kutipan terisi, pindahkan menjadi blok teks bebas pertama (teks Arab dengan RTL, terjemahan, dan sumber digabung). Admin bisa memindahkannya ke blok Kutipan di beranda.
- Varian ringkas otomatis di alur PSB dan halaman 404 (tanpa blok teks bebas).

### 5.6 Kontak satu sumber
Pindahkan `address`, `phone`, dan `email` dari Identitas ke Kontak & sosial. Satu kontak bisa ditandai "dicetak di kop/kuitansi", sehingga kop surat tidak lagi butuh field telepon terpisah. Halaman Kontak, footer, dan Info PSB membaca dari sini.

### 5.7 Taksonomi
- Satu kategori untuk berita, dengan tipe album tetap terpisah bila memang perlu. Field `type` (kegiatan/prestasi/berita) dihapus dari form dan dimigrasikan menjadi kategori. Berita dari modul Prestasi otomatis berkategori "Prestasi" yang dibuat saat migrasi bila belum ada. Nama kategori tetap isian admin, sesuai aturan tanpa konten bawaan.
- Filter jenjang di portal hanya tampil bila ada ≥2 jenjang yang punya berita, dan chip yang kosong disembunyikan.

### 5.8 Tampilan dan template (rencana lanjutan)
Setting `theme.*`: warna primer dan aksen (dengan validasi kontras 4.5:1, skala dihitung otomatis), pasangan font (pilihan dari 3–4 font yang dibundel saat build), dan radius. Token CSS dibaca dari variabel, jadi tidak perlu build ulang (usulan ini sudah ada di catatan sesi mockup `portal-v3-setting-admin.md`). Template berupa *preset* di repo (`resources/views/themes/<nama>/blocks/*.blade.php`) yang merender blok yang sama. Karena model kontennya berbasis blok, mengganti template tidak membuat isian hilang. Inilah syarat utama agar rencana "memilih template" bisa jalan.

---

## 6. Prioritas

### Cepat (tanpa ubah skema, bisa dikerjakan kapan saja)
| # | Perbaikan | Area |
|---|---|---|
| C1 | Grid adaptif sesuai jumlah item (keunggulan, prestasi, galeri, berita) | portal |
| C2 | Footer: kutipan dipadatkan, varian ringkas di PSB dan 404, logo di footer, ikon sosial, kolom 1024px seimbang | portal |
| C3 | Hero dua kolom: foto album pertama di kanan, slider tidak terpotong; nama Arab tidak tampil di hero | portal |
| C4 | Filter berita: sembunyikan chip kosong dan baris jenjang bila hanya satu | portal |
| C5 | "Lihat semua" konsisten di semua section otomatis | portal |
| C6 | Data demo: halaman Kontak tanpa duplikasi, placeholder navy, perbaiki label "Selayang Pandangan" | demo |
| C7 | Pindahkan cluster Profil lembaga dan Menu ke grup yang sama dengan Halaman/Berita | admin |
| C8 | Ganti contoh basmalah dan sapaan di teks bantuan hero dengan contoh pesan lembaga | admin |

### Struktural (mengubah model, sebaiknya diputuskan sebelum F038)
| # | Perubahan | Alasan urutan |
|---|---|---|
| S1 | Navigasi daftar tautan (primary dan footer) | Kecil, langsung menjawab keluhan user, dan menjadi dasar menu Info PSB |
| S2 | Beranda berbasis blok | Inti rasa "tidak cocok"; prasyarat template |
| S3 | Footer generik | Bergantung S1 |
| S4 | Pengaturan per halaman dan berita, template halaman, hapus halaman sistem | Dibutuhkan agar Info PSB dan Kontak menjadi halaman bertemplate |
| S5 | Kontak satu sumber | Kecil; F038 merujuk kontak |
| S6 | Taksonomi disederhanakan | Mandiri |
| S7 | Tampilan dari admin (token), lalu preset template | Setelah S2 |

**Kaitan dengan F038 (Info PSB):** rencana F038 sekarang menambah banyak field teks khusus (syarat umum, agenda, langkah, fasilitas, kurikulum, mutu lulusan, ekstrakurikuler, keterangan biaya). Ini pola yang sama dengan masalah footer. Saran: kerjakan **S1, S5, dan desain blok S2** dulu, lalu ubah F038 menjadi "Halaman bertemplate Info PSB" yang berisi **blok otomatis** (status gelombang, syarat berkas per jenjang, biaya dari `fee_rates`, kontak PSB) dan **blok generik** dari katalog §5.3 (Daftar poin untuk fasilitas/kurikulum, langkah bernomor, teks). F039–F041 (keuangan dan penomoran) tidak terpengaruh dan bisa jalan paralel.

---

## 7. Calon fitur baru (belum ditambahkan ke `feature_list.json`)

ID belum diberikan; pakai `next_id` saat user menyetujui.

1. **`konten-navigasi-tautan`: Navigasi portal sebagai daftar tautan**
   - Menu utama dan footer diedit sebagai daftar tautan (label + tujuan dari pemilih: halaman, daftar berita, kategori, galeri, video, Info PSB, cek status, verifikasi surat, URL) dengan urutan dan submenu satu tingkat.
   - Tujuan tidak tersedia (modul mati, halaman draf/terhapus, daftar kosong) tidak dirender; validasi maks. item utama saat menyimpan, tanpa pemotongan diam-diam.
   - Migrasi `portal.menu_items` dan `school.footer.links` tanpa kehilangan item; tautan sistem footer yang dulu otomatis menjadi item biasa.
   - Form halaman punya aksi "Tambahkan ke menu utama" yang menambah satu item.
2. **`konten-beranda-blok`: Beranda disusun dari blok**
   - Admin menambah, mengurutkan, menyembunyikan, dan menghapus blok dari katalog terbatas (§5.3) memakai Filament Builder; disimpan di settings (JSON) dan dibaca dengan satu query per request.
   - Blok kosong tidak dirender; instalasi baru tanpa blok dan tanpa teks konten bawaan.
   - Migrasi field hero, keunggulan, dan media beranda F036 menjadi blok dengan urutan tampilan yang sama seperti sekarang.
   - Dicek di 360px, 1024px, dan desktop; grid blok adaptif sesuai jumlah item.
3. **`konten-footer-generik`: Footer tanpa field khusus**
   - Footer dirangkai dari identitas, kontak dan sosial otomatis (bisa disembunyikan), navigasi footer, dan maks. 2 blok teks bebas (dengan opsi kanan-ke-kiri).
   - Field kutipan Arab/terjemahan/sumber dihapus dan isinya dimigrasikan ke blok teks pertama.
   - Varian ringkas di alur PSB dan 404.
4. **`konten-pengaturan-halaman`: Pengaturan per halaman dan berita**
   - Halaman dan berita punya ringkasan, gambar unggulan dan alt, meta title/description; halaman punya template (biasa, kontak, lebar penuh); berita dan album punya tanda unggulan.
   - Konsep halaman sistem dihapus tanpa mengubah URL lama; halaman `kontak` memakai template kontak yang menampilkan kartu kontak otomatis.
   - Meta dipakai di `<title>`, `meta description`, dan Open Graph, dengan fallback ke deskripsi situs.
5. **`konten-kontak-satu-sumber`: Kontak lembaga di satu tempat**
   - Alamat, email, dan telepon pindah ke Kontak & sosial; satu kontak bisa ditandai untuk kop surat dan kuitansi.
   - Kop surat dan kuitansi tetap menampilkan data yang sama setelah migrasi.
6. **`konten-taksonomi-sederhana`: Satu taksonomi kategori untuk berita**
   - Field jenis dimigrasikan ke kategori; berita dari modul Prestasi tetap bisa difilter sebagai prestasi.
   - Filter portal menyembunyikan chip kosong dan baris jenjang bila kurang dari dua jenjang punya berita.
7. **`portal-tata-letak-adaptif`: Perbaikan cepat tampilan portal (C1–C6, C8)**
   - Grid adaptif, hero dua kolom dengan foto, footer dipadatkan, "Lihat semua" konsisten, data demo dirapikan.
   - Lolos checklist §0 design guide, dicek di 360px, 1024px, dan desktop.
8. **`konten-navigasi-admin`: Susunan menu panel admin untuk situs (C7)**
   - Beranda, Navigasi, Halaman, Berita, Kategori, Galeri, dan Video ada di satu grup; Identitas, Kontak, dan Tampilan di Pengaturan; logo cetak dipindah ke pengaturan kop surat.
   - Permission per peran tidak berubah (test akses per peran tetap lulus).
9. **`portal-tema-admin`: Warna, font, dan radius portal dari admin**
   - Warna primer dan aksen divalidasi kontras 4.5:1 dan skalanya dihitung otomatis; font dipilih dari daftar yang dibundel; token diterapkan lewat variabel CSS tanpa build ulang.
   - Panel admin Filament tidak berubah.
10. **`portal-template-preset`: Pilihan template portal** (setelah 2 dan 9)
    - Admin memilih preset template; semua preset merender blok dan navigasi yang sama, sehingga isian tidak hilang saat berganti.
11. **Revisi ruang lingkup F038 (bukan entri baru):** Info PSB sebagai halaman bertemplate dengan blok otomatis dan blok generik, bukan daftar field teks khusus.

---

## 8. Catatan verifikasi
- Portal dicek visual: beranda (1440px, 1024px, 360px, termasuk menu mobile), `/berita`, detail berita, `/galeri`, `/video`, `/profil`, `/kontak`, `/psb`, `/psb/status`, dan 404.
- Admin **belum dicek visual** karena login diblokir pengaman kredensial sesi. Temuan admin berasal dari kode form dan teks UI. Disarankan user membuka halaman Profil lembaga dan Menu untuk memastikan kesan visualnya sama.
- Tidak ada test yang dijalankan (sesuai batasan RAM), dan tidak ada server tambahan yang dijalankan.

## 9. Keputusan user (17 Okt 2026)

Status: masih dievaluasi; arah audit secara keseluruhan belum disetujui.

- **Blok ayat di footer diganti blok generik.** Kolom khusus kutipan Arab/terjemahan/sumber tidak dipertahankan. Yang dipakai adalah dua blok terpisah:
  - **Ajakan:** label kecil (opsional), judul, teks singkat, tombol utama dan tombol kedua (tujuan dipilih lewat pemilih tautan yang sama dengan navigasi), varian latar dari tema (terang/navy/emas).
  - **Kutipan:** teks, terjemahan/penjelasan (opsional), sumber (opsional), saklar kanan-ke-kiri.
- **Pita ajakan hanya di beranda,** tidak tampil di semua halaman.
- **Kutipan hanya di beranda,** tidak di footer dan tidak di halaman statis.
- **Footer tetap ringan:** identitas, kontak, navigasi footer, dan paling banyak satu teks pendek.
- **Batasan desain blok:** level judul diatur sistem (h2), warna hanya dari varian tema (tanpa color picker bebas), dan isi teks terbatas pada paragraf, tebal, dan tautan.

### Footer sebagai area widget (keputusan 17 Okt 2026)

Disetujui user, dikerjakan sebagai bagian paket 2 (beranda berbasis blok) dengan satu mesin blok untuk dua area: beranda dan footer.

- Footer berisi widget yang jumlah dan isinya diatur admin, **paling banyak 4**. Grid menyesuaikan jumlah widget (1–4 kolom di desktop, bertumpuk di seluler).
- Setiap widget punya **heading opsional**.
- Katalog awal:
  - **Teks:** teks singkat (paragraf, tebal, tautan), dengan opsi menampilkan logo.
  - **Tautan:** daftar label + tujuan dari pemilih yang sama dengan menu utama. Menggantikan "navigasi footer" F043; boleh lebih dari satu kelompok.
  - **Sosmed:** menampilkan semua akun dari data Kontak & sosial, tanpa mengetik ulang dan tanpa pilihan per widget.
  - **Kontak:** menampilkan semua alamat, email, peta, dan kontak dari data Kontak & sosial, tanpa mengetik ulang dan tanpa pilihan per widget.
  - Keputusan user 17 Okt 2026: pemilihan sebagian akun/kontak per widget ditunda sampai benar-benar dibutuhkan (dicatat sebagai entri baru bila diminta).
- Tetap di luar widget: baris hak cipta, serta footer ringkas di alur PSB dan 404.
- Migrasi dari F043: deskripsi dan teks pendek menjadi widget Teks, navigasi footer menjadi widget Tautan, saklar kontak/sosial menjadi widget Kontak/Sosmed. Instalasi baru dimulai tanpa widget.
