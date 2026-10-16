# F034 inti-seeder-demo — Seeder data demo Ma'had Al-Khair Belitang

Status rencana: APPROVED

## Ringkasan & tujuan

User meminta data contoh yang terasa seperti data pondok sungguhan (bukan faker acak) agar panel admin dan portal publik bisa dicek secara visual. Nama lembaga: **Ma'had Al-Khair Belitang** (Belitang, Kabupaten Ogan Komering Ulu Timur, Sumatera Selatan).

F034 menyediakan `Database\Seeders\DemoSeeder` yang:

1. Dijalankan terpisah: `php artisan db:seed --class=DemoSeeder`. Tidak dipanggil `DatabaseSeeder`, `InstallSeeder`, `SystemSeeder`, maupun `pondok:install`/`pondok:upgrade`.
2. Menolak berjalan di `APP_ENV=production`.
3. Idempotent: jalan kedua tidak menambah baris, berkas, atau pembayaran.
4. Deterministik: semua nama, alamat, nominal, dan pola pembayaran berasal dari data kurasi di kode. Tanggal dihitung dari "hari ini" (Asia/Jakarta) dengan aturan tetap, jadi hasilnya sama untuk tanggal yang sama.
5. Membuat data lewat Action yang sudah ada, sehingga aturan bisnis (tarif, override, gabungan tagihan, alokasi cicilan, konversi PSB, penomoran) benar-benar dijalankan. Insert langsung hanya untuk master data yang memang tidak punya Action (daftar di bagian Action).

Tidak ada tabel, migration, halaman Filament, atau route baru.

## Ruang lingkup

- Termasuk:
  - `database/seeders/DemoSeeder.php` (penjaga lingkungan, orkestrasi, ringkasan ke konsol).
  - `database/seeders/Demo/` untuk data kurasi dan pembantu. Susunan yang disarankan: `DemoData.php` (konstanta array data kurasi), `DemoCalendar.php` (perhitungan tanggal dari hari ini), `DemoFiles.php` (berkas placeholder PDF/PNG sebagai `UploadedFile`), dan boleh dipecah per modul (`DemoAkademik`, `DemoAsrama`, `DemoKeuangan`, `DemoPsb`, `DemoDokumen`, `DemoPrestasi`, `DemoKonten`, `DemoPengguna`). Susunan file final diserahkan ke green-coder selama `DemoData` tetap jadi satu sumber data kurasi yang dibaca test.
  - Konfigurasi `config/pondok.php` key `demo.password` dari env `DEMO_PASSWORD`; `.env.example` mendapat baris `DEMO_PASSWORD=` dengan komentar.
  - Teks konsol/eksepsi di `lang/id/pondok.php` grup `pondok.demo.*`: `refused_production`, `summary`, `default_password_warning`, `skipped` (bagian dilewati).
  - `docs/demo.md`: cara menjalankan, daftar akun demo per peran, password bawaan dan cara menggantinya lewat `DEMO_PASSWORD`, catatan bahwa semua data fiktif, dan keterbatasan (lihat Risiko).
  - Satu file test `tests/Feature/Database/Seeders/DemoSeederTest.php`.
- Tidak termasuk (eksplisit):
  - Data modul Fase 2/3/4 (tahfidz, perizinan, absensi, tabungan, dll.).
  - Perubahan Action, model, migration, Filament, atau route. Bila Action menolak data demo yang wajar, catat sebagai temuan dan laporkan, jangan mengubah Action di fitur ini.
  - Logo sekolah (biarkan kosong; portal sudah punya fallback), link media sosial (dibiarkan null agar tidak menunjuk akun nyata).
  - Perintah artisan khusus (`pondok:demo`) dan tombol reset demo.
  - Factory baru atau perubahan factory test.

## Model data

Tidak ada perubahan skema. Tabel yang diisi: `units`, `academic_years`, `settings`, `users`, `unit_user`, tabel Spatie (`model_has_roles`), `staff`, `cohorts`, `classrooms`, `students`, `enrollments`, `guardians`, `student_guardian`, `dormitories`, `rooms`, `room_assignments`, `dormitory_supervisors`, `fee_types`, `fee_rates`, `scholarships`, `fee_overrides`, `invoices`, `invoice_items`, `payments`, `payment_allocations`, `admission_waves`, `admission_tracks` (update), `admission_track_fee_type`, `admission_requirements`, `applicants`, `applicant_documents`, `documents`, `issued_letters`, `achievements`, `achievement_student`, `categories`, `posts`, `albums`, `photos`, `videos`, `pages`, `activity_log` (efek samping Action).

Aturan yang tetap berlaku: uang `unsignedBigInteger` rupiah kelipatan 1.000; tagihan dan pembayaran tidak dihapus (koreksi dengan `CancelPayment`); riwayat kelas/kamar berupa baris baru; nomor KK di `documents.number` lewat cast `encrypted`; berkas pribadi di disk `private`, foto konten di disk `public`.

### Kalender demo (`DemoCalendar`)

`D` = `CarbonImmutable::now('Asia/Jakarta')->startOfDay()`. `Y` = `D->year` bila `D->month >= 7`, selain itu `D->year - 1`.

| Simbol | Tahun ajaran | Tanggal | Peran |
|---|---|---|---|
| `P` | `"{Y-1}/{Y}"` | `{Y-1}-07-01` s.d. `{Y}-06-30` | tahun lalu (riwayat kelas, tarif dasar berlaku mulai `P`) |
| `A` | `"{Y}/{Y+1}"` | `{Y}-07-01` s.d. `{Y+1}-06-30` | tahun aktif |
| `N` | `"{Y+1}/{Y+2}"` | `{Y+1}-07-01` s.d. `{Y+2}-06-30` | tujuan gelombang PSB |

- Dicari berdasarkan `name` (`firstOrCreate`, tanggal standar di atas hanya dipakai saat membuat). Bila `A` belum aktif, aktifkan lewat `ActivateAcademicYear`. `P` dan `N` tidak aktif.
- Periode tagihan: empat bulan `M-3`, `M-2`, `M-1`, `M` dengan `M = D->startOfMonth()`. Semua periode jatuh di `P` atau `A`.
- Semua tanggal lain (penempatan kamar, pembayaran, prestasi, berita, gelombang) diturunkan dari `D`, `A`, dan `P` dengan offset tetap, dan **tidak pernah melewati `D`** kecuali yang memang masa depan (`closes_on` gelombang, `expires_on` dokumen, `entered_on` santri hasil daftar ulang).

### Komposisi data kurasi (`DemoData`)

Nama orang fiktif bergaya Muslim Indonesia; tempat lahir dan alamat memakai nama kecamatan/desa sekitar Belitang (mis. Belitang, Belitang II, Belitang III, Gumawang, Martapura, Buay Madang, Semendawai Suku III), Kab. OKU Timur. Tidak memakai nama tokoh, lembaga, sekolah, atau perusahaan nyata selain nama pondok yang diminta; nama penyelenggara lomba ditulis generik ("Panitia MTQ tingkat kabupaten"). Nomor telepon berpola jelas fiktif (mis. `0812-0000-0xxx`), email memakai domain `.test`.

**Profil sekolah** (via `UpdateSchoolProfile`, hanya bila `school.address` masih kosong): `name` "Ma'had Al-Khair Belitang", `tagline` (mis. "Mendidik generasi Qur'ani yang berakhlak dan mandiri"), `address` fiktif di Kec. Belitang, Kab. OKU Timur, Sumatera Selatan, `phone`, `whatsapp`, `email` (`info@alkhair-belitang.test`). Sosial media null.

**Halaman statis** (via `UpdateStaticPage`, hanya bila `body` kosong): `profil` (sejarah singkat fiktif, jenjang SD/SLTP/SLTA, asrama putra/putri), `visi-misi` (visi + 4–6 butir misi), `kontak` (alamat, telepon, jam layanan TU). HTML sederhana (`<p>`, `<ul>`, `<h2>`), bahasa Indonesia wajar, tanpa lorem ipsum.

**Staff** (insert langsung, kunci `nip`, format fiktif mis. `MAK-2015-001`): minimal 22 orang aktif.
- 1 pimpinan pondok (`position` "Mudir"), penandatangan surat.
- 15 ustadz/ustadzah wali kelas (`position` "ustadz"); wali kelas rombel putri berjenis kelamin P.
- 4 musyrif/musyrifah (`position` "musyrif"): 2 L, 2 P.
- 2 TU (`position` "TU").
- Staff yang punya akun: musyrif putra pertama ↔ akun `musyrif`, satu ustadz ↔ akun `ustadz-tahfidz`, satu TU ↔ akun `bendahara` (`staff.user_id`).

**Akun demo** (insert langsung `User`, kunci `email`, `is_active` true, peran via `assignRole`):

| Peran | Email | Cakupan jenjang (`unit_user`) |
|---|---|---|
| super-admin | `super-admin@demo.test` | semua |
| bendahara | `bendahara@demo.test` | semua |
| admin-sekolah | `admin-sekolah@demo.test` | SLTP saja |
| admin-psb | `admin-psb@demo.test` | semua |
| admin-konten | `admin-konten@demo.test` | semua |
| kesantrian | `kesantrian@demo.test` | SLTP dan SLTA |
| musyrif | `musyrif@demo.test` | semua |
| ustadz-tahfidz | `ustadz-tahfidz@demo.test` | semua |
| petugas-pos | `petugas-pos@demo.test` | semua |

Password = `config('pondok.demo.password')` (env `DEMO_PASSWORD`); bila kosong memakai konstanta `DemoSeeder::DEFAULT_PASSWORD = 'demo-alkhair-belitang'` yang ditulis di `docs/demo.md`, dan seeder menampilkan `__('pondok.demo.default_password_warning')` lewat `$this->command?->warn(...)`. `User.password` bercast `hashed`: berikan **password teks biasa** ke model (jangan `Hash::make`, agar tidak ter-hash dua kali). `email_verified_at` diisi waktu pembuatan. Password hanya di-set saat akun dibuat; akun yang sudah ada tidak diubah (nama, password, peran, cakupan). Akun super-admin dari `SuperAdminSeeder` tidak disentuh.

**Rombel** (tingkat 1–12, nama `<tingkat><huruf>`). Struktur sama di `P` dan `A`:
- SD: `4A`, `5A`, `6A` gender `campur`.
- SLTP: `7A`, `8A`, `9A` gender `L`; `7B`, `8B`, `9B` gender `P`.
- SLTA: `10A`, `11A`, `12A` gender `L`; `10B`, `11B`, `12B` gender `P`.
- Rombel `P` dibuat langsung (tidak ada Action pembuat rombel) dengan wali kelas; rombel `A` lewat `CopyClassroomsFromYear(P, A)`, lalu wali kelas `A` diisi langsung (Action salinan sengaja tidak menyalin wali kelas).

**Santri** (91 total, `CreateStudent`; angkatan lewat `FindOrCreateCohort`). `DemoData` **tidak** menyimpan NIS literal atau tahun absolut: setiap santri menyimpan offset tahun masuk relatif `Y` (mis. `-1`, `-5`) dan nomor urut dalam angkatannya. `DemoCalendar` menghitung tahun masuk = `Y + offset`, NIS = `{tahun masuk}{urut 4 digit}` (pola bawaan `{YYYY}{NNNN}`), dan `entered_on` = `{tahun masuk}-07-01`. Kunci idempotensi `nis` hasil hitungan. Untuk SD, tahun masuk adalah tahun masuk kelas 1; untuk santri yang pindah jenjang, NIS lama dipertahankan.

| Kelompok | Jumlah | Riwayat |
|---|---|---|
| SD aktif kelas 4/5/6 (`A`) | 12 (4 per rombel, 2 L + 2 P) | kelas 5 & 6 naik dari 4A/5A `P` lewat `PromoteStudents`; kelas 4 baru masuk rombel di `A` |
| SLTP aktif kelas 7 (`A`) | 12 (6 L, 6 P) | 4 di antaranya dari SD 6A `P`: `ChangeStudentUnit` SD → SLTP (angkatan SLTP `Y`), lalu `EnrollStudent`; 8 santri baru angkatan `Y` |
| SLTP aktif kelas 8 & 9 | 24 | naik dari 7/8 `P` lewat `PromoteStudents` |
| SLTA aktif kelas 10 | 10 (5 L, 5 P) | 4 dari SLTP 9 `P` lewat `ChangeStudentUnit` SLTP → SLTA; 6 santri baru angkatan `Y` |
| SLTA aktif kelas 11 & 12 | 20 | naik dari 10/11 `P` |
| Lulus | 7 | 5 dari SLTA 12 `P`, 2 dari SLTP 9 `P` yang tidak melanjutkan; terdaftar di rombel `P`, lalu status `lulus` |
| Keluar | 2 | 1 dari SLTP 8 `P`, 1 dari SLTA 11 `P`; status `keluar` |
| Pindah | 1 | 1 dari SD 4A `P`; status `pindah` |
| Hasil daftar ulang PSB | 3 | dibuat `ReRegisterApplicant`; angkatan `Y+1`, belum punya rombel |

Status non-aktif diubah langsung pada model (sama seperti form edit santri; tidak ada Action status) **setelah** enrollment `P` dibuat, dan santri itu tidak ditempatkan di kamar.

Catatan `ChangeStudentUnit`: Action hanya mengganti `unit_id`/`cohort_id`; enrollment `P` santri yang pindah jenjang tetap di rombel jenjang lama (SD 6A, SLTP 9A/9B). Jadi untuk enrollment `P`, `classrooms.unit_id` boleh berbeda dari `students.unit_id`. Itu perilaku sah dan tidak ada AC yang menuntut keselarasan tersebut; keselarasan hanya dituntut untuk enrollment `A`.

**Wali** (insert langsung `Guardian`, kunci `phone`; pivot lewat `AttachGuardian` dengan `isBillingContact`): satu wali per santri sebagai kontak tagihan (ayah, atau ibu/wali untuk santri yatim). Minimal 8 keluarga punya 2 santri dan 1 keluarga punya 3 santri (kakak-adik lintas jenjang). Satu wali keluarga bersaudara juga menjadi wali salah satu pendaftar PSB (nama dan HP sama persis) agar `ResolveApplicantGuardian` memakai ulang walinya.

**Asrama** (insert langsung `Dormitory`, kamar lewat `SaveRoom`):
- `ABK` "Asrama Abu Bakar Ash-Shiddiq" (L), kamar `Kamar 101`–`Kamar 104`, kapasitas 8.
- `KHD` "Asrama Khadijah" (P), kamar `Kamar 201`–`Kamar 204`, kapasitas 8.
- Penempatan `AssignRoom` (start `A.starts_on`): 26 santri SLTP dan 24 santri SLTA mukim (50); SD seluruhnya nonmukim. Satu santri putra pindah kamar lewat `AssignRoom` kedua dengan `start_on = max(A.starts_on + 1 hari, D − 30 hari)` dan catatan alasan, sehingga punya 2 baris (satu tertutup).
- `AssignSupervisor` untuk `A`: tiap asrama 1 `kepala_asrama` (seluruh asrama) dan 2 `musyrif` (satu per asrama, satu per kamar), `start_on = A.starts_on`.

**Jenis biaya** (`SaveFeeType`, kunci `code`):

| code | nama | frekuensi | applies_to | billing_group | priority |
|---|---|---|---|---|---|
| `SPP` | SPP | bulanan | semua | `BULANAN` | 30 |
| `ASRAMA` | Biaya asrama | bulanan | mukim | `BULANAN` | 20 |
| `MAKAN` | Uang makan | bulanan | mukim | `BULANAN` | 10 |
| `FORMULIR` | Formulir pendaftaran | saat_daftar | semua | `PSB-DAFTAR` | 10 |
| `UANG_PANGKAL` | Uang pangkal | saat_diterima | semua | `PSB-TERIMA` | 30 |
| `SERAGAM` | Seragam | saat_diterima | semua | `PSB-TERIMA` | 20 |
| `KITAB` | Kitab dan buku | saat_diterima | semua | `PSB-TERIMA` | 10 |

**Tarif** (`SaveFeeRate`, `effective_from_year = P` kecuali disebut; kunci = kombinasi unik tarif):
- SPP: SD 150.000; SLTP 250.000; SLTA 300.000. Tarif angkatan: SLTA angkatan `Y`, berlaku mulai `A`, 325.000.
- ASRAMA: SLTP 200.000; SLTA 200.000; jenjang + asrama `KHD` (SLTP dan SLTA) 225.000.
- MAKAN: SLTP 450.000; SLTA 450.000.
- FORMULIR: SD 100.000; SLTP 150.000; SLTA 150.000.
- UANG_PANGKAL: SD 2.000.000; SLTP 3.500.000; SLTA 4.000.000.
- SERAGAM: semua jenjang 850.000. KITAB: semua jenjang 400.000.

**Beasiswa** (`SaveScholarship`, kunci `name`, sponsor fiktif "Donatur Yayasan Al-Khair Belitang"):
- "Beasiswa Yatim": `nominal`, 0.
- "Beasiswa Tahfidz": `persen`, 50.
- "Keringanan Anak Ustadz": `potongan`, 100.000.

**Keringanan santri** (`SaveFeeOverride`, disetujui akun bendahara, jenis biaya SPP, `valid_from = P.starts_on`, `valid_until` null; dilewati bila santri sudah punya override SPP): 3 santri yatim, 3 santri tahfidz, 2 anak ustadz (mode/nilai dari program), dan 1 keringanan non-beasiswa `potongan` 50.000 alasan "Keringanan saudara kandung".

**Jalur PSB** (`SaveAdmissionTrack` pada jalur bawaan, hanya bila `scholarship_id` masih null): `TAHFIDZ` → Beasiswa Tahfidz untuk `UANG_PANGKAL`; `YATIM` → Beasiswa Yatim untuk `UANG_PANGKAL` dan `SPP`.

**Tagihan & pembayaran**:
- `GenerateMonthlyInvoices` untuk keempat periode, `generatedBy` = akun bendahara (setelah kamar dan keringanan dibuat).
- Pola pembayaran per santri ditentukan oleh urutan santri di `DemoData` (`i` = indeks 0-based santri aktif yang punya rombel `A`), bukan id database:
  - `i % 10` ∈ 0–5 (**lunas**): setiap periode dibayar penuh.
  - `i % 10` ∈ 6–7 (**cicilan**): `M-3` dan `M-2` penuh; `M-1` satu pembayaran `floor(total / 2 / 1000) * 1000`; `M` belum dibayar.
  - `i % 10` ∈ 8–9 (**tunggakan**): hanya `M-3` dibayar penuh.
- Tanggal bayar rencana = tanggal `5 + (i % 5)` pada bulan periode. Bila tanggal rencana > `D`, pembayaran itu dilewati (tagihan tetap `belum`). Metode `tunai` untuk `i` genap, `transfer` (tanpa bukti) untuk `i` ganjil. Pencatat = akun bendahara.
- Tagihan bertotal 0 atau sudah lunas dilewati. Pembayaran hanya dicatat bila tagihan belum punya pembayaran `diterima` (kunci idempotensi).
- Satu koreksi: pada tagihan `M-3` santri lunas pertama, catat pembayaran salah nominal (total − 50.000), batalkan dengan `CancelPayment` alasan "Salah input nominal", lalu catat pembayaran yang benar. Dilewati bila tagihan itu sudah punya pembayaran apa pun.

**PSB** (gelombang `N`):
- `SaveAdmissionWave`: "Gelombang 1", `opens_on = D − 45 hari`, `closes_on = D + 45 hari`, kuota 120, `is_open` true. Kunci (`academic_year_id`, `name`).
- `SaveAdmissionRequirement` (semua jenjang): KK wajib, AKTA wajib, FOTO tidak wajib. Dilewati bila sudah ada.
- 14 pendaftar (kunci idempotensi: gelombang + `name` + `birth_date`, termasuk terhapus; bila sudah ada, seluruh alurnya dilewati). Semua lewat `SubmitApplication` (consent true), kecuali satu `draft`:

| Status akhir | Jml | Alur |
|---|---|---|
| draft | 1 | **insert langsung** (tidak ada Action yang menghasilkan draft): nomor dari `GenerateApplicantNumber`, `access_token_hash` dari `ResetApplicantToken::hash(ResetApplicantToken::newToken())`, tanpa tagihan |
| diajukan | 2 | formulir belum dibayar |
| diajukan | 2 | formulir dibayar (`RecordPayment`); satu berkas KK `menunggu`, satu berkas KK `ditolak` (`VerifyApplicantDocument` dengan catatan) |
| diverifikasi | 2 | formulir dibayar, KK & AKTA diunggah dan `valid`, `MarkApplicantVerified` |
| cadangan | 1 | seperti diverifikasi, lalu `ReserveApplicant` |
| ditolak | 1 | `RejectApplicant` dari diajukan (formulir belum dibayar ikut `batal`) |
| diterima | 2 | diverifikasi lalu `AcceptApplicant`; satu jalur TAHFIDZ (keringanan jalur otomatis), satu reguler dengan pembayaran tagihan penerimaan 30% (di bawah `psb.min_paid_percent`) |
| diterima + daftar ulang | 3 | diterima, tagihan penerimaan dibayar ≥ 50% (satu lunas, dua cicilan), `ReRegisterApplicant` (NIS otomatis); satu jalur YATIM, satu adik santri aktif (wali dipakai ulang), satu reguler |

Tanggal bayar PSB: antara `wave.opens_on` dan `D` (offset tetap, tidak melewati `D`). Pencatat pembayaran = akun bendahara; keputusan PSB = akun admin-psb.

`ReRegisterApplicant` dipanggil **tanpa** `entered_on` (dan tanpa `nis`), sehingga Action memakai `ReRegisterApplicant::defaultEnteredOn()` (≥ `earliestEnteredOn()`, awal bulan depan) dan NIS otomatis; data lain: `relation` sesuai `DemoData` (bawaan `wali`).

**Dokumen santri** (`UploadDocument`, `VerifyDocument`, `ReplaceDocument`; pengunggah admin-sekolah, verifikator admin-sekolah; dilewati bila santri sudah punya dokumen current jenis itu):
- 20 santri aktif: KK `valid` (nomor KK fiktif 16 digit berawalan `1608`, terenkripsi) + AKTA `valid`.
- 6 santri: KK `menunggu`. 1 santri: AKTA `ditolak`.
- 1 santri: BPJS `valid` dengan `expires_on = D + 20 hari`.
- 1 santri: KK lama diganti `ReplaceDocument` (KK baru), sehingga 1 dokumen punya `replaced_by_id`.
- Dokumen pendaftar yang `valid` ikut tersalin saat daftar ulang (efek `ReRegisterApplicant`).
- 1 dokumen `SERTIFIKAT` untuk prestasi MTQ.
- Berkas: PDF placeholder kecil (< 2 KB, header `%PDF-1.4`, berisi teks "Dokumen contoh - data demo Ma'had Al-Khair Belitang"), dibuat sebagai `UploadedFile` dari berkas sementara (mode test `true`) sehingga validasi MIME Action lulus.

**Surat keluar** (`IssueLetter`, penerbit admin-sekolah, penandatangan Mudir; kunci santri + jenis + `purpose`, lihat tabel kunci idempotensi): 2 `keterangan_aktif` untuk dua santri berbeda (keperluan "Persyaratan pengajuan beasiswa", "Pendaftaran BPJS"), 1 `rekomendasi` (keperluan "Pendaftaran lomba tingkat provinsi"), 1 `kartu_santri` (`purpose` null), dan 1 `keterangan_aktif` dengan keperluan "Pengajuan beasiswa daerah" untuk **santri kelima yang tidak menerima surat lain**, lalu dibatalkan `CancelIssuedLetter` (alasan "Salah keperluan"). Profil sekolah sudah terisi sebelum surat diterbitkan.

**Prestasi** (`SaveAchievement`, `VerifyAchievement`, `PublishAchievementAsPost`; pencatat dan verifikator akun kesantrian; kunci `title` + `achieved_on`):

| Judul | Kategori | Tingkat | Jenis | Tanggal | Verifikasi | Publik |
|---|---|---|---|---|---|---|
| Juara 1 MTQ Cabang Hifzhil Qur'an 10 Juz | tahfidz | kabupaten | individu | D − 20 | ya | ya, terbit jadi berita |
| Juara 2 Futsal Antar Pondok se-OKU Timur | olahraga | kabupaten | beregu (5 putra, satu "ketua tim") | D − 45 | ya | ya |
| Juara 3 Pidato Bahasa Arab | keagamaan | provinsi | individu | D − 80 | ya | tidak |
| Juara 1 Hafalan Hadits Arbain Beregu Putri | keagamaan | kecamatan | beregu (6 putri) | D − 120 | ya | ya |
| Juara 1 Cerdas Cermat Matematika | akademik | kecamatan | individu | D − 200 | belum | tidak |
| Juara 1 Kaligrafi Musabaqah Internal | seni | pondok | individu | D − 260 | belum | tidak |

Peserta setiap prestasi hanya dipilih dari santri yang `entered_on` ≤ tanggal prestasi dan berstatus aktif (dalam praktik: angkatan dengan offset ≤ `-1` terhadap `Y`; santri angkatan `Y` tidak dipakai sebagai peserta). Pemilihan tetap dari daftar indeks di `DemoData`.

Berita prestasi dari `PublishAchievementAsPost` (draft) lalu diterbitkan lewat `SavePost` (status `terbit`, `published_at = D − 18 hari`).

**Konten** (aktor admin-konten):
- Kategori berita `SaveCategory` tipe `post`: "Kegiatan Santri", "Pengumuman", "Tahfidz". Kategori album bawaan (Fasilitas, Kegiatan) dipakai.
- Berita `SavePost` (kunci `slug` eksplisit): 7 terbit + 1 draft, ditambah berita prestasi di atas.
  - Terbit: "Penerimaan Santri Baru Tahun Ajaran {N} Dibuka" (berita, Pengumuman), "Pembukaan Tahun Ajaran {A} dan Masa Ta'aruf Santri" (kegiatan), "Kerja Bakti Santri Bersama Warga Desa" (kegiatan), "Wisuda Tahfidz Santri Ma'had Al-Khair Belitang" (kegiatan, Tahfidz), "Jadwal Libur Akhir Semester Ganjil" (berita, Pengumuman), "Kajian Kitab Bulanan Bersama Wali Santri" (kegiatan), "Santri SLTA Belajar di Perpustakaan Daerah" (kegiatan, `unit_id` SLTA). `published_at` berurutan `D − 2`, `D − 6`, … (≤ `D`).
  - Draft: "Rencana Pembangunan Asrama Putri Tahap II".
  - Isi 2–4 paragraf HTML bahasa Indonesia; 4 berita memakai sampul placeholder PNG.
- Album `SaveAlbum` + `AddAlbumPhotos` (kunci `slug`; foto ditambah hanya bila album belum punya foto): "Asrama dan Masjid" (Fasilitas, 4 foto), "Masa Ta'aruf Santri Baru" (Kegiatan, 5 foto), "Wisuda Tahfidz" (Kegiatan, 4 foto). Keterangan foto bahasa Indonesia.
- Video `SaveVideo` (kunci `youtube_id`): 3 video, 1 `is_featured`. ID YouTube fiktif 11 karakter (lihat pertanyaan terbuka 1).
- Gambar placeholder: PNG 800×600 warna polos dari palet hijau/krem design guide dengan teks judul, dibuat GD bila tersedia; bila GD tidak ada, pakai PNG kecil dari konstanta base64. Ukuran < 200 KB.

### Kunci idempotensi per bagian

Semua pencarian kunci memakai `withoutUnitScope()` untuk `Student`, `Invoice`, `Applicant` (dan model ber-`HasUnitScope` lain), serta `withTrashed()` untuk `Student`, `Applicant`, `Post`. Bila kunci sudah ada, bagian itu **dilewati tanpa mengubah data** (kecuali disebut lain).

| Bagian | Kunci | Perilaku bila sudah ada |
|---|---|---|
| Tahun ajaran | `academic_years.name` | dipakai; tanggal tidak diubah; `A` diaktifkan bila belum aktif |
| Profil sekolah | `school.address` terisi | seluruh profil dilewati |
| Halaman statis | `pages.slug` dengan `body` tidak kosong | dilewati |
| Akun demo | `users.email` | dilewati (nama, password, peran, cakupan tidak diubah) |
| Staff | `staff.nip` | dilewati; `user_id` hanya diisi bila masih null |
| Angkatan | `FindOrCreateCohort` (`unit_id`, `entry_year`) | Action sudah idempotent |
| Rombel `P` | (`academic_year_id`, `unit_id`, `name`) | dilewati |
| Rombel `A` | `CopyClassroomsFromYear` | Action melewati nama yang sudah ada |
| Wali kelas `A` | `classrooms.homeroom_staff_id` | hanya diisi bila masih null |
| Santri | `students.nis` (hitungan `DemoCalendar`) | seluruh alur santri itu (wali, enrollment, status) dilewati |
| Wali | `guardians.phone` | dipakai ulang |
| Pivot wali-santri | (`student_id`, `guardian_id`) di `student_guardian` | `AttachGuardian` tidak dipanggil |
| Enrollment `P` | (`student_id`, `academic_year_id = P`) | dilewati |
| Enrollment `A` (kenaikan / baru) | (`student_id`, `academic_year_id = A`) | dilewati; `PromoteStudents` hanya diberi `studentIds` yang belum punya enrollment `A` |
| Pindah jenjang | `students.unit_id` sudah jenjang tujuan | `ChangeStudentUnit` dilewati |
| Status non-aktif | `students.status` bukan `aktif` | dilewati |
| Asrama | `dormitories.code` | dipakai |
| Kamar | (`dormitory_id`, `name`) | dipakai (`SaveRoom` tidak dipanggil) |
| Penempatan awal | santri punya baris `room_assignments` apa pun | dilewati |
| Pindah kamar | santri punya ≥ 2 baris `room_assignments` **atau** penempatan aktif di kamar tujuan | dilewati |
| Pembina asrama | baris `dormitory_supervisors` aktif (`end_on` null) dengan `dormitory_id`, `room_id`, `staff_id`, `role`, `academic_year_id` sama | dilewati (Action juga mengembalikan baris yang ada) |
| Jenis biaya | `fee_types.code` | dipakai |
| Tarif | (`fee_type_id`, `effective_from_year_id`, `unit_id`, `cohort_id`, `dormitory_id`, `admission_wave_id`) dengan pembandingan null eksplisit (`whereNull` bila null) | dilewati |
| Beasiswa | `scholarships.name` | dipakai |
| Keringanan santri | santri punya `fee_overrides` untuk `SPP` | dilewati |
| Jalur PSB | `admission_tracks.scholarship_id` tidak null | dilewati |
| Tagihan bulanan | `GenerateMonthlyInvoices` | Action idempotent |
| Pembayaran tagihan | tagihan punya `payments` apa pun (diterima atau batal) | dilewati |
| Koreksi pembayaran | tagihan target punya `payments` apa pun | dilewati |
| Gelombang | (`academic_year_id = N`, `name`) | dipakai |
| Syarat berkas | (`document_type_id`, `unit_id` null) | dilewati |
| Pendaftar | (gelombang, `name`, `birth_date`), termasuk terhapus | seluruh alur pendaftar dilewati |
| Berkas pendaftar | (`applicant_id`, `admission_requirement_id`) di `applicant_documents` | unggah dan verifikasi dilewati |
| Dokumen santri | santri punya dokumen current (`replaced_by_id` null) jenis itu | dilewati |
| Penggantian KK | santri target punya dokumen ber-`replaced_by_id` | dilewati |
| Dokumen `SERTIFIKAT` prestasi | prestasi punya dokumen jenis `SERTIFIKAT` | dilewati |
| Surat keluar | (`student_id`, `type`, `purpose`), status apa pun | dilewati (surat batal juga dihitung, jadi tidak diterbitkan ulang) |
| Prestasi | (`title`, `achieved_on`) | dilewati; verifikasi hanya bila `verified_by` null |
| Berita prestasi | prestasi punya `posts` (termasuk terhapus) | dilewati |
| Kategori | (`type`, `name`) | dipakai |
| Berita | `posts.slug` termasuk terhapus | dilewati |
| Album | `albums.slug`; foto hanya bila album belum punya foto | dilewati |
| Video | `videos.youtube_id` | dilewati |

## Action class

Tidak ada Action baru. `DemoSeeder` adalah orkestrator di lapisan seeder dan hanya memanggil Action berikut (nama sudah diverifikasi di `app/Actions`):

- Inti: `Core\ActivateAcademicYear`. Data instalasi lewat `$this->call(InstallSeeder::class)` di awal (idempotent, agar `migrate:fresh` + `db:seed --class=DemoSeeder` langsung jalan).
- Akademik: `FindOrCreateCohort`, `CreateStudent`, `CopyClassroomsFromYear`, `EnrollStudent`, `PromoteStudents`, `ChangeStudentUnit`, `AttachGuardian`.
- Asrama: `SaveRoom`, `AssignRoom`, `AssignSupervisor`.
- Keuangan: `SaveFeeType`, `SaveFeeRate`, `SaveScholarship`, `SaveFeeOverride`, `GenerateMonthlyInvoices`, `RecordPayment`, `CancelPayment`.
- PSB: `SaveAdmissionWave`, `SaveAdmissionTrack`, `SaveAdmissionRequirement`, `SubmitApplication`, `UploadApplicantDocument`, `VerifyApplicantDocument`, `MarkApplicantVerified`, `ReserveApplicant`, `RejectApplicant`, `AcceptApplicant`, `ReRegisterApplicant`, `GenerateApplicantNumber`, `ResetApplicantToken` (statis).
- Dokumen: `UploadDocument`, `VerifyDocument`, `ReplaceDocument`, `IssueLetter`, `CancelIssuedLetter`.
- Prestasi: `SaveAchievement`, `VerifyAchievement`, `PublishAchievementAsPost`.
- Konten: `UpdateSchoolProfile`, `UpdateStaticPage`, `SaveCategory`, `SavePost`, `SaveAlbum`, `AddAlbumPhotos`, `SaveVideo`.

**Insert/update langsung (tidak ada Action):** `academic_years` (`firstOrCreate` by name), `staff`, `users` + peran + `unit_user`, `guardians`, `dormitories`, rombel tahun `P`, `classrooms.homeroom_staff_id` tahun `A`, `students.status` untuk lulus/keluar/pindah, dan satu pendaftar `draft`.

Ketentuan eksekusi:
- **Bebas dari Auth dan `UnitScope`.** Di awal `run()` (setelah penjaga production) seeder memanggil `Auth::forgetGuards()` sehingga `Auth::user()` null selama seeding, walau pemanggil sedang login (efek samping: status login pemanggil di proses itu dilepas; dicatat di `docs/demo.md`). Seeder tidak pernah `Auth::login`/`actingAs`. Aktor dikirim sebagai argumen `User` ke Action (bendahara, admin-psb, admin-sekolah, kesantrian, admin-konten, atau super-admin demo untuk `UpdateSchoolProfile`).
- Action yang membaca `Auth::user()` dan perilakunya tanpa user login (semuanya "tidak menyaring"): `UnitScope` pada `Student`/`Invoice`/`Applicant`; `SaveAdmissionRequirement::scopedUnitIds()` → [] (semua jenjang boleh); `StoresDocuments::ownerVisible()` untuk pemilik `Guardian` → true; `FailsWithPrestasiErrors::ensureFullyVisible()` → tidak memeriksa. Selain itu, semua pencarian kunci idempotensi dan pembacaan lintas jenjang di seeder tetap memakai `withoutUnitScope()` (dan `withTrashed()` untuk `Student`/`Applicant`/`Post`) sebagai pertahanan berlapis.
- Seluruh kegagalan Action (`ValidationException`) dibiarkan menggagalkan seeder dengan pesan jelas (jangan ditelan), kecuali yang memang dirancang dilewati di atas.
- Berkas lewat `Storage::disk('private'|'public')` (melalui Action), sehingga `Storage::fake` di test berlaku. Berkas sementara dihapus setelah dipakai.
- Tidak ada SQL mentah; hanya Eloquent/Query Builder agar kompatibel SQLite, MySQL/MariaDB, Postgres.
- Penjaga lingkungan: di awal `run()`, bila `app()->environment('production')` lempar `RuntimeException(__('pondok.demo.refused_production'))` sebelum menulis apa pun.
- Akhir `run()`: ringkasan ke konsol (`$this->command?->info`) jumlah santri, tagihan, pembayaran, pendaftar, berita; teks dari `lang/id`.

## UI (Filament / Blade)

Tidak ada UI baru. Data harus tampil wajar di UI yang sudah ada (panel dan portal). Teks konten mengikuti `docs/design-guide.md` bagian bahasa: kalimat Indonesia baku yang ramah, tanpa singkatan tak lazim, tanpa emoji. Warna placeholder gambar memakai palet design guide.

## Permission & cakupan

- Tidak ada permission baru. Peran dan permission dari `RolePermissionSeeder` (dipanggil lewat `InstallSeeder`).
- Cakupan jenjang demo: admin-sekolah → SLTP; kesantrian → SLTP + SLTA.
- Saklar modul tidak diubah; modul Fase 1 sudah aktif dari `SettingSeeder`.

## Kriteria penerimaan (bisa dites)

Tanggal uji utama `T1 = 2026-10-16 10:00 Asia/Jakarta` (`Y` = 2026, `A` = 2026/2027, `P` = 2025/2026, `N` = 2027/2028, periode 2026-07 s.d. 2026-10). Disk `private` dan `public` di-fake.

- **AC-1 Menolak production.** Kondisi: `app()->detectEnvironment(fn () => 'production')`. Aksi: jalankan `DemoSeeder`. Hasil: `RuntimeException` dengan pesan `__('pondok.demo.refused_production')`; tabel `students`, `staff`, `invoices`, `applicants` kosong dan tidak ada user ber-email `@demo.test`.
- **AC-2 Tidak ikut seeder instalasi.** Kondisi: `SUPER_ADMIN_*` terisi. Aksi: `$this->seed(DatabaseSeeder::class)`. Hasil: `students`, `staff`, `posts` kosong; tidak ada user `@demo.test`. Sumber `DatabaseSeeder`, `InstallSeeder`, `SystemSeeder` tidak menyebut `DemoSeeder`.
- **AC-3 Jalan di database kosong.** Kondisi: hanya migrasi (tanpa seeder lain). Aksi: `DemoSeeder` pada `T1`. Hasil: tanpa exception; `units` berisi SD/SLTP/SLTA; peran bawaan ada; `modules.*.enabled` Fase 1 bernilai true.
- **AC-4 Tahun ajaran.** Hasil: ada `2025/2026` (2025-07-01–2026-06-30), `2026/2027` (aktif, satu-satunya yang aktif), `2027/2028`.
- **AC-5 Profil & halaman.** Hasil: `Setting::get('school.name')` = "Ma'had Al-Khair Belitang"; `school.address` memuat "Belitang" dan "Ogan Komering Ulu Timur" (atau "OKU Timur"); `school.phone` dan `school.email` terisi. Halaman `profil`, `visi-misi`, `kontak` ber-`body` tidak kosong. Tidak ada `posts.body`, `posts.title`, `pages.body` yang mengandung "lorem" (case-insensitive). Bila `school.address` sudah terisi sebelum seeding, nilainya tidak diubah.
- **AC-6 Akun demo.** Hasil: 9 user sesuai tabel, masing-masing punya tepat satu peran yang sesuai, `is_active` true, dan `email_verified_at` terisi. admin-sekolah `scopedUnitIds()` = [id SLTP]; kesantrian = [SLTP, SLTA]; lainnya []. Akun musyrif, ustadz-tahfidz, bendahara terhubung ke satu `staff`. Setelah seluruh seeding selesai, saat `actingAs(admin-sekolah)`, `Student::query()->count()` = jumlah santri SLTP (semua status), dan lebih kecil dari `Student::withoutUnitScope()->count()`.
- **AC-7 Password demo.** (a) Dengan `config(['pondok.demo.password' => 'rahasia-uji-123'])`, `Hash::check('rahasia-uji-123', $user->password)` true untuk semua akun demo. (b) Dengan config kosong, `Hash::check(DemoSeeder::DEFAULT_PASSWORD, …)` true dan keluaran konsol memuat `__('pondok.demo.default_password_warning')`; dengan config terisi, peringatan itu tidak muncul. (c) Bila password akun demo diubah lalu seeder dijalankan ulang, password tidak dikembalikan.
- **AC-8 Staff & rombel.** Hasil: staff aktif ≥ 22, `position` mencakup "ustadz", "musyrif", "TU"; tepat 15 rombel `A` (SD 3 `campur`, SLTP 3 `L` + 3 `P`, SLTA 3 `L` + 3 `P`) dan 15 rombel `P`; setiap rombel `A` punya `homeroom_staff_id`, wali kelas setiap rombel bergender putri (`gender` = `P`) berjenis kelamin P.
- **AC-9 Santri & data kurasi.** Hasil: total santri (termasuk terhapus) 60–100; nama setiap santri non-PSB ada di `DemoData` dan setiap NIS hasil hitungan `DemoCalendar` pada `T1` dari entri `DemoData` ada di DB (pemetaan satu-satu). Per status: aktif ≥ 75, lulus ≥ 5, keluar ≥ 1, pindah ≥ 1. Ketiga jenjang punya santri aktif L dan P. Setiap santri punya tepat satu wali `is_billing_contact`; ≥ 8 wali terhubung ke ≥ 2 santri; ≥ 1 wali terhubung ke 3 santri. `students.unit_id` = `cohorts.unit_id` untuk semua santri.
- **AC-10 Riwayat kelas.** Hasil: setiap santri aktif berangkatan ≤ `Y` punya enrollment `A`; ≥ 20 santri punya enrollment `P` dan `A` dengan tingkat `A` = tingkat `P` + 1; ≥ 1 santri punya enrollment `P` di SD dan `A` di SLTP; ≥ 1 punya `P` di SLTP dan `A` di SLTA; santri lulus/keluar/pindah tidak punya enrollment `A`; untuk setiap enrollment `A`, `classrooms.unit_id` = `students.unit_id` (keselarasan ini **tidak** diperiksa untuk enrollment `P`).
- **AC-11 Asrama.** Hasil: 2 asrama (L dan P) aktif, 8 kamar; penempatan aktif ≥ 40; tidak ada santri SD atau santri non-aktif dengan penempatan aktif; gender asrama = gender santri untuk setiap penempatan; jumlah penempatan aktif per kamar ≤ kapasitas; ≥ 1 santri punya 2 baris `room_assignments` (satu ber-`end_on`); tiap asrama di `A` punya tepat 1 `kepala_asrama` aktif dan ≥ 1 `musyrif` aktif.
- **AC-12 Jenis biaya & tarif.** Hasil: 7 jenis biaya sesuai tabel (frekuensi, applies_to, billing_group, priority); semua `fee_rates.amount` kelipatan 1.000 dan 50.000–5.000.000; ≥ 1 tarif ber-`cohort_id`, ≥ 1 tarif ber-`dormitory_id`; tarif dasar ber-`effective_from_year_id` = `P`.
- **AC-13 Beasiswa & keringanan.** Hasil: 3 program aktif dengan mode `nominal`/`persen`/`potongan`; override santri memakai ketiga mode; ≥ 1 override tanpa `scholarship_id`; semua override `approved_by` = akun bendahara.
- **AC-14 Tagihan bulanan.** Hasil: untuk setiap periode 2026-07..2026-10, jumlah tagihan aktif `BULANAN` = jumlah santri aktif dengan `entered_on` ≤ akhir periode (santri hasil daftar ulang tidak punya tagihan bulanan); tidak ada duplikat (`student_id`, `period`, `billing_group`) aktif; tagihan santri nonmukim tidak punya item `ASRAMA`/`MAKAN`, tagihan santri mukim punya keduanya; item SPP santri ber-override punya `discount_amount` > 0, dan item SPP santri yatim ber-`amount` 0; item SPP santri SLTA angkatan 2026 ber-`base_amount` 325.000; item ASRAMA santri di `KHD` ber-`base_amount` 225.000.
- **AC-15 Pembayaran.** Hasil: tagihan bulanan berstatus `lunas` ≥ 100, `sebagian` ≥ 5, `belum` dengan `due_on` < `T1` ≥ 10; untuk setiap tagihan `paid_total` = jumlah `payments.amount` berstatus `diterima`; tidak ada `payments.paid_on` > tanggal `T1`; tepat 1 pembayaran `batal` dengan alasan terisi; pada setiap tagihan `sebagian`, alokasi mengikuti prioritas (tidak ada item ber-`priority` lebih besar yang `paid_amount` > 0 selama item ber-`priority` lebih kecil belum lunas); ≥ 1 tagihan `sebagian` santri mukim punya item MAKAN lunas dan SPP belum dibayar.
- **AC-16 PSB.** Hasil: gelombang "Gelombang 1" tahun `N` `isAcceptingRegistrationsOn(T1)` true; 14 pendaftar dengan status draft 1, diajukan 4, diverifikasi 2, cadangan 1, ditolak 1, diterima 5; setiap pendaftar non-draft punya tagihan `PSB-DAFTAR`; pendaftar ditolak tidak punya tagihan aktif yang belum dibayar; 3 pendaftar diterima punya santri (`students.applicant_id`) dan semua tagihan miliknya ber-`student_id` terisi; pendaftar jalur TAHFIDZ dan YATIM yang diterima punya `fee_overrides` ber-`scholarship_id` program jalurnya; satu santri hasil daftar ulang memakai wali yang sudah terhubung ke santri lain; ≥ 2 `documents` ber-`source_applicant_document_id`; ada berkas pendaftar berstatus `menunggu` dan `ditolak`.
- **AC-17 Dokumen.** Hasil: dokumen santri `valid` ≥ 20, `menunggu` ≥ 5, `ditolak` ≥ 1; ≥ 1 dokumen ber-`replaced_by_id`; ≥ 1 dokumen `expires_on` antara `T1` dan `T1 + 60 hari`; setiap `documents.file_path` dan `applicant_documents.file_path` ada di disk `private` dan tidak ada di disk `public`; nilai mentah kolom `documents.number` (query builder) tidak sama dengan nilai yang terbaca model (terenkripsi); ≥ 1 dokumen milik prestasi.
- **AC-18 Surat keluar.** Hasil: surat `terbit` tepat 4 dan `batal` tepat 1; santri pemilik surat `batal` tidak punya surat lain; tidak ada dua surat dengan (`student_id`, `type`, `purpose`) sama; jenis mencakup `keterangan_aktif`, `rekomendasi`, `kartu_santri`; penandatangan semua surat = staff Mudir yang aktif; `letter_no` unik.
- **AC-19 Prestasi.** Hasil: 6 prestasi; ≥ 2 beregu dengan ≥ 3 peserta, ≥ 1 punya peran "ketua tim"; terverifikasi tepat 4, belum 2; tepat 1 prestasi punya `posts` (tipe `prestasi`, status `terbit`, `published_at` ≤ `T1`); setiap peserta prestasi ber-`entered_on` ≤ `achieved_on`.
- **AC-20 Konten & portal.** Hasil: kategori `post` ≥ 3; posts `terbit` dengan `published_at` ≤ `T1` ≥ 8 (termasuk berita prestasi), `draft` ≥ 1; 3 album masing-masing ≥ 4 foto yang berkasnya ada di disk `public`; ≥ 4 posts ber-`cover_path` yang berkasnya ada; 3 video, tepat 1 `is_featured`. `GET route('portal.home')`, `portal.posts.index`, `portal.albums.index`, `portal.videos.index`, `portal.pages.show` (`profil`) masing-masing 200 dan beranda memuat teks "Ma'had Al-Khair Belitang".
- **AC-21 Panel tetap render.** `actingAs(super-admin@demo.test)` lalu `GET` dashboard panel admin → 200 tanpa exception.
- **AC-22 Idempotent.** Kondisi: setelah seed pertama, test `actingAs(admin-sekolah)` (user bercakupan SLTP sedang login). Aksi: jalankan `DemoSeeder` kedua kali pada `T1`. Hasil: tanpa exception; `Auth::check()` false setelah seeding (login dilepas oleh seeder); jumlah baris semua tabel di bagian Model data (kecuali `activity_log`, `sessions`, `cache`) sama persis dengan setelah jalan pertama; `SUM(invoices.total)`, `SUM(payments.amount)` sama; jumlah berkas di disk `private` dan `public` sama.
- **AC-23 Relatif terhadap hari ini.** Kondisi: database baru, `T2 = 2027-07-03 08:00 Asia/Jakarta`. Aksi: `DemoSeeder`. Hasil: `2027/2028` aktif (satu-satunya), `2026/2027` ada dan tidak aktif; ada tagihan bulanan periode 2027-04-01 dan 2027-07-01; tidak ada `payments.paid_on` > 2027-07-03; gelombang tahun `2028/2029` menerima pendaftaran pada `T2`; tidak ada `posts.published_at` atau `achievements.achieved_on` setelah `T2`.
- **AC-24 Dokumentasi.** Hasil: `docs/demo.md` ada dan memuat ke-9 email demo, `DEMO_PASSWORD`, `DemoSeeder::DEFAULT_PASSWORD`, dan perintah `php artisan db:seed --class=DemoSeeder`; `.env.example` memuat `DEMO_PASSWORD=`.

## Rencana test

Semua di `tests/Feature/Database/Seeders/DemoSeederTest.php`. Untuk menjaga waktu suite, seeding penuh hanya dilakukan **tiga kali** di seluruh file (dua kali di test utama, sekali di test `T2`). Assertion per modul dikelompokkan dalam satu test besar dengan pesan assertion yang menyebut nomor AC. Target waktu file < 60 detik di SQLite memori; red-tester mencatat waktu aktual.

- Waktu dibekukan dengan `$this->travelTo(...)` (mempengaruhi `Carbon` dan `CarbonImmutable`, termasuk `DemoCalendar`, `RecordPayment::paidOn`, `ReRegisterApplicant::earliestEnteredOn`), bukan `Carbon::setTestNow` saja.
- Dua test yang melakukan seeding penuh diberi `->group('seeder-demo')` agar bisa dijalankan terpisah (`--group=seeder-demo`); tetap ikut full suite.
- Urutan di test utama: (1) `travelTo(T1)`, `Storage::fake` dua disk, config password uji, **tanpa user login**; (2) seed pertama; (3) assertion AC-3–AC-5, AC-7a, AC-8–AC-20 sebagai tamu; (4) ubah password satu akun demo; (5) snapshot hitungan; (6) `actingAs(admin-sekolah)` lalu seed kedua; (7) AC-22 dan AC-7c; (8) `actingAs(admin-sekolah)` untuk cek cakupan AC-6; (9) `actingAs(super-admin@demo.test)` untuk AC-21. Tidak ada `actingAs` sebelum seed pertama.
- Test `T2` menjalankan seeder lewat `$this->artisan('db:seed', ['--class' => DemoSeeder::class])` (bukan `$this->seed`) agar keluaran konsol bisa diperiksa untuk peringatan password bawaan (AC-7b).

| Test | AC |
|---|---|
| `it('AC-1: menolak berjalan di production tanpa menulis data')` | AC-1 |
| `it('AC-2: tidak dipanggil oleh seeder instalasi')` | AC-2 |
| `it('AC-24: dokumentasi demo dan env contoh tersedia')` | AC-24 |
| `it('AC-3..AC-22: mengisi data demo lengkap, mematuhi aturan bisnis, dan idempotent')->group('seeder-demo')` — urutan seperti di atas | AC-3–AC-22, AC-7a/c |
| `it('AC-23 dan AC-7b: tanggal mengikuti hari ini dan password bawaan dipakai bila env kosong')->group('seeder-demo')` — `travelTo(T2)`, config password kosong, lewat `artisan db:seed` | AC-23, AC-7b |

Helper lokal di file test (bukan `tests/Pest.php`): `demoTableCounts()` untuk snapshot AC-22.

## Urutan implementasi

1. `config/pondok.php` (`demo.password`), `.env.example`, teks `lang/id/pondok.php` grup `demo`.
2. `DemoSeeder` kerangka: penjaga production, `InstallSeeder`, `DemoCalendar` + tahun ajaran (AC-1–AC-4).
3. `DemoData` (staff, keluarga, santri, akun) + akun demo dan staff (AC-6, AC-7).
4. Akademik: rombel `P`, santri, wali, enrollment `P`, status non-aktif, salin rombel `A`, kenaikan dan pindah jenjang (AC-8–AC-10).
5. Asrama: asrama, kamar, penempatan, pindah kamar, pembina (AC-11).
6. Keuangan master: jenis biaya, tarif, beasiswa, keringanan, jalur PSB (AC-12, AC-13).
7. Generator tagihan dan pola pembayaran + koreksi (AC-14, AC-15).
8. Profil sekolah dan halaman statis (AC-5), lalu PSB lengkap sampai daftar ulang (AC-16).
9. `DemoFiles` + dokumen santri dan surat keluar (AC-17, AC-18).
10. Prestasi dan berita prestasi (AC-19).
11. Konten: kategori, berita, album, video (AC-20, AC-21).
12. Penjagaan idempotensi tiap bagian (AC-22) dan uji `T2` (AC-23).
13. `docs/demo.md` (AC-24), Pint, lalu verifikasi manual di MySQL lokal: `php artisan migrate:fresh` → `php artisan db:seed --class=DemoSeeder` dua kali, catat hasil di laporan (tidak dijalankan di CI).

## Risiko & pertanyaan terbuka

Pertanyaan terbuka (butuh keputusan user; bawaan di bawah dipakai bila tidak dijawab):

1. **ID video YouTube.** ID nyata akan menampilkan konten pihak lain (melanggar arahan "tanpa nama orang/lembaga nyata"); ID fiktif membuat embed dan thumbnail kosong. Bawaan: ID fiktif, dicatat di `docs/demo.md` agar admin menggantinya.
2. **Password bawaan** `demo-alkhair-belitang` terdokumentasi publik. Aman karena seeder menolak production, tetapi instance staging yang `APP_ENV`-nya bukan production akan punya akun dengan password diketahui. Bawaan: tetap dipakai dengan peringatan di `docs/demo.md` agar mengisi `DEMO_PASSWORD`.
3. **Pengaman tambahan**: apakah seeder juga harus menolak bila database sudah berisi santri yang bukan data demo (mencegah data demo tercampur dengan data sekolah sungguhan di instance non-production)? Bawaan: tidak (hanya penjaga `APP_ENV`), diusulkan sebagai perbaikan bila user menginginkan.

Risiko:

- **Volume waktu test.** ~91 santri × 4 periode + ~300 pembayaran dengan activity log. Bila file test > 60 detik, green-coder boleh menurunkan jumlah santri ke batas bawah (≥ 60 total) asalkan semua AC tetap terpenuhi; jangan memecah ke banyak test yang masing-masing seed ulang.
- **Action menolak data demo.** Contoh: tanggal pindah kamar saat `D` = 1 Juli, gelombang tumpang tindih dengan gelombang buatan user di tahun `N`, kuota gelombang penuh. Bawaan: bagian yang tidak mungkin dibuat pada tanggal itu dilewati dengan pesan konsol, bukan menggagalkan seluruh seeder, **kecuali** pada `T1`/`T2` di mana AC wajib terpenuhi.
- **Idempotensi bergantung kunci alami.** Bila seeder terputus di tengah alur PSB, pendaftar yang sudah ada dilewati utuh dan alurnya tidak dilanjutkan. Dokumentasikan: jalankan ulang dari `migrate:fresh` bila seeding terputus.
- **Generator memakai status mukim saat dijalankan,** bukan saat periode. Karena kamar dibuat sebelum generator, hasilnya konsisten; pindah kamar tidak mengubah tagihan lama (snapshot).
- **`AcademicYearSeeder` mengunci 2026/2027.** Bila hari ini di luar 2026/2027, `DemoSeeder` mengaktifkan tahun `A` yang benar; 2026/2027 tetap ada sebagai baris tak aktif (diuji AC-23).
- **GD tidak tersedia** di sebagian hosting. Fallback PNG base64 menjaga seeder tetap jalan; jalur fallback tidak dites otomatis.
- **Nama fiktif bisa kebetulan sama dengan orang nyata.** Dipilih nama umum dan kombinasi tanpa gelar/jabatan publik; plan-reviewer memeriksa daftar `DemoData` saat review kode.

## Revisi 2 — sesuai brosur PSB

Permintaan user (diteruskan orkestrator, 16 Okt 2026): "Ya, sesuaikan seeder demo dengan brosur: khusus putra, tanpa jenjang SD, dan tarif mengikuti brosur." Klarifikasi: "brosur ... hanya sebagai referensi bukan sebagai pengganti informasi yang sudah ada". Sumber: `docs/brand/brosur-psb-2027-2028.md`, `docs/brief-awal.txt`. Implementasi sebelumnya sudah ter-commit (`ac9b141`, `f5070f9`). Ini revisi F034 (bukan ID baru).

**Urutan prioritas sumber:** instruksi user > `docs/brief-awal.txt` dan PRD/ERD > brosur > rencana di atas. Bila bagian di atas bertentangan dengan Revisi 2, **Revisi 2 yang berlaku**. Yang tidak disebut di sini tetap seperti di atas: penjaga production, Auth/UnitScope, kunci idempotensi, akun demo, pola pembayaran, dokumen, surat, dan `travelTo`.

### Larangan dari brosur (tetap fiktif)

- **Tidak dipakai:**
  - nomor telepon brosur (`0858-3233-3725`, `0856-6994-1929`);
  - nomor rekening (`7286406461`) dan nama bank pada rekening itu;
  - nama orang di brosur (pembina, panitia);
  - nama perguruan tinggi tujuan alumni;
  - nama usaha di sekitar alamat (penanda lokasi di sebelah alamat);
  - akun media sosial (`school.*_url` tetap null).
- **Boleh dipakai dari brosur:**
  - nama lembaga;
  - alamat (`Jl. Raya Belitang–Martapura, Mojosari, Belitang, OKU Timur, Sumatera Selatan`);
  - tagar sebagai `tagline` ("Generasi Beradab Bersama Al-Qur'an");
  - program (tahfidz, bahasa Arab, studi Islam);
  - fasilitas, ekstrakurikuler, nominal biaya, dan tanggal gelombang.
- **Kontak tetap fiktif:** telepon dan WhatsApp memakai pola `0812-0000-0xxx` / `0735 000 0xxx`, email `@alkhair-belitang.test`. Pengurus dan staff memakai nama fiktif.
- **Nama yayasan:** dari brief ("Yayasan Islam Al-Khair Belitang"). Boleh dipakai karena satu lembaga dengan pondok yang diminta user.

### Jenjang

- **Unit SD:** baris unit `SD` dari `UnitSeeder` dibiarkan apa adanya. Tidak ada rombel, angkatan, santri, tarif, syarat berkas, pendaftar, berita, atau cakupan user yang memakai SD.
- **Ganti nama unit:** `DemoSeeder` mengganti `units.name`, **hanya bila nama masih sama dengan nilai bawaan `UnitSeeder::UNITS`**. Bila admin sudah mengubahnya, nama tidak disentuh. Penggantian dilakukan dengan update langsung (tidak ada Action unit), kode unit tetap:
  - `SLTP` → "MTW/SMP"
  - `SLTA` → "TSN/SMA"
- **Rombel:** satu rombel per tingkat per tahun ajaran (`P` dan `A`), semuanya gender `L`: MTW `7A`, `8A`, `9A`; TSN `10A`, `11A`, `12A`. Jadi 6 rombel `A` + 6 rombel `P`.
- **Teks konten:** pakai "MTW/SMP" dan "TSN/SMA", bukan "SLTP/SLTA".

### Khusus putra

- **Santri dan pendaftar:** semuanya ber-`gender` `L`. Nama perempuan di `DemoData` (santri dan pendaftar) diganti nama laki-laki fiktif.
- **Wali kelas, ustadz, musyrif, dan Mudir:** semuanya laki-laki.
- **TU:** boleh campur; staff TU perempuan yang ada tetap dipakai.
- **Wali santri:** ayah, atau ibu/wali untuk santri yatim. Ibu tetap perempuan; ini data wali, bukan santri.
- **Asrama:** dua asrama putra, masing-masing 4 kamar. Kunci `code`.

| Kode | Nama | Untuk | Kamar | Kapasitas |
|---|---|---|---|---|
| `ABK` | Asrama Abu Bakar Ash-Shiddiq | MTW/SMP | `Kamar 101`–`Kamar 104` | 10 |
| `UMR` | Asrama Umar bin Khattab | TSN/SMA | `Kamar 201`–`Kamar 204` | 8 |

- **Musyrif per kamar** (brosur): 8 musyrif laki-laki, satu per kamar (`AssignSupervisor` peran `musyrif` dengan `room_id`). Ditambah 1 `kepala_asrama` per asrama dari ustadz laki-laki.
- **Prestasi beregu putri** diganti versi putra, misalnya "Juara 1 Hafalan Hadits Arba'in Beregu" (5–6 putra). Futsal beregu tetap.

### Komposisi santri (menggantikan tabel Santri di atas)

| Kelompok | Jumlah | Riwayat |
|---|---|---|
| MTW kelas 7 (`A`) | 12 | santri baru angkatan `Y` |
| MTW kelas 8 & 9 (`A`) | 24 | naik dari 7A/8A `P` (`PromoteStudents`) |
| TSN kelas 10 (`A`) | 10 | 4 dari MTW 9A `P` lewat `ChangeStudentUnit` SLTP → SLTA; 6 santri baru angkatan `Y` |
| TSN kelas 11 & 12 (`A`) | 20 | naik dari 10A/11A `P` |
| Lulus | 7 | 5 dari TSN 12A `P`, 2 dari MTW 9A `P` yang tidak melanjutkan |
| Keluar | 2 | 1 dari MTW 8A `P`, 1 dari TSN 11A `P` |
| Pindah | 1 | 1 dari MTW 7A `P` |
| Hasil daftar ulang PSB | 3 | angkatan tahun ajaran tujuan PSB, belum punya rombel |

Total 79 santri: 69 aktif, termasuk 3 hasil daftar ulang.

**Keluarga:** ≥ 5 wali punya ≥ 2 santri, dan ≥ 1 wali punya 3 santri. Semua kakak-adik putra.

**Mukim:** semua santri aktif yang punya rombel `A` ditempatkan di asrama (MTW 36 di `ABK`, TSN 30 di `UMR`). Satu-satunya santri aktif nonmukim adalah 3 santri hasil daftar ulang. Alasannya, mereka belum serah terima (`entered_on` di masa depan); kamar diatur panitia setelah serah terima.

**Pindah kamar:** tetap ada satu, di dalam asrama yang sama.

### Keuangan (menggantikan jenis biaya, tarif, beasiswa, dan keringanan di atas)

| code | Nama | Frekuensi | applies_to | billing_group | priority | Tarif (`effective_from_year = P`, jenjang SLTP dan SLTA) |
|---|---|---|---|---|---|---|
| `SPP` | SPP (termasuk makan 3× sehari, laundry, operasional) | bulanan | semua | `BULANAN` | 10 | 1.600.000 |
| `FORMULIR` | Biaya pendaftaran | saat_daftar | semua | `PSB_DAFTAR` | 10 | 300.000 |
| `UANG_MASUK` | Uang masuk | saat_diterima | semua | `PSB_TERIMA` | 10 | 10.000.000 |
| `DAFTAR_ULANG` | Daftar ulang tahunan | tahunan | semua | `DAFTAR_ULANG` | 20 | 1.500.000 (**contoh**, brosur tidak menyebut nominal; ditandai "contoh" di `docs/demo.md`) |

- **Jenis biaya terpisah dihapus:** `ASRAMA`, `MAKAN`, `LAUNDRY`, `UANG_PANGKAL`, `SERAGAM`, dan `KITAB` tidak dibuat. Brosur menyatakan SPP sudah mencakup makan, laundry, dan operasional. Uang masuk juga satu angka yang mencakup seragam, buku, perlengkapan, dan lainnya.
- **Daftar ulang tahunan:** hanya jenis biaya dan tarifnya yang dibuat, tanpa tagihan. Belum ada Action generator tagihan `tahunan`; generator bulanan hanya memproses `bulanan`. Ini dicatat di `docs/demo.md`.
- **Tarif angkatan** (tetap ada, untuk memperlihatkan aturan "tarif dikunci per angkatan" di ERD): SPP TSN angkatan `Y−2` (kelas 12 di `A`) = 1.500.000, "tarif lama terkunci sampai lulus".
- **Tarif per asrama:** tidak ada lagi (biaya asrama sudah masuk SPP).
- **Tidak ada tarif SD.**
- **Beasiswa/subsidi** (`SaveScholarship`). Brosur: "hanya untuk 3 santri". Jadi pemegang keringanan ber-`scholarship_id` paling banyak 3 orang (santri + pendaftar, dihitung per pemilik).

| Program | Mode | Nilai | Penerima |
|---|---|---|---|
| "Subsidi Yatim" | nominal | 800.000 (bayar SPP Rp800.000) | 1 santri, SPP |
| "Beasiswa Tahfidz" | persen | 50 | 1 santri, SPP |
| "Subsidi Uang Masuk" | potongan | 5.000.000 | jalur `SUBSIDI`, `UANG_MASUK`; 1 pendaftar yang diterima lalu daftar ulang |

- **Keringanan non-beasiswa:** 1 santri, `potongan` 300.000 pada SPP, alasan "Kesepakatan wawancara biaya". Ini mencerminkan "sesi wawancara biaya" di brosur dan tidak dihitung sebagai beasiswa.
- **Tagihan bulanan** berisi satu item SPP. Pola lunas/cicilan/tunggakan, tanggal bayar, dan koreksi `CancelPayment` tetap. Pembayaran cicilan = `floor(total / 2 / 1000) * 1000`.
- **Uji alokasi prioritas:** tidak ada lagi tagihan multi-item di data demo, jadi uji alokasi prioritas di AC-15 dihapus. Alokasi cicilan tetap diuji oleh test F007.

### PSB (menggantikan bagian PSB di atas)

**Jalur:**
- `REGULER` tetap aktif.
- `SUBSIDI` "Subsidi/Beasiswa" dibuat lewat `SaveAdmissionTrack`: `scholarship_id` = Subsidi Uang Masuk, `fee_type_ids` = [UANG_MASUK]. Kunci `code`.
- `PRESTASI`, `TAHFIDZ`, dan `YATIM` dinonaktifkan lewat `SaveAdmissionTrack` (`is_active` false), hanya bila masih aktif dan belum punya pendaftar. Tujuannya agar formulir publik demo hanya menampilkan dua jalur seperti brosur.

**Syarat berkas** (`SaveAdmissionRequirement`, semua jenjang, memakai jenis dokumen yang ada):
- Wajib: `KK` "Kartu Keluarga", `AKTA` "Akta kelahiran", `RAPOR` "Rapor semester terakhir", `FOTO` "Pas foto 3×4 latar biru".
- Tidak wajib: `IJAZAH` "Ijazah terakhir (legalisir, diserahkan saat serah terima)".
- KTP orang tua dan surat validasi NISN tidak punya jenis dokumen, jadi tidak dibuat. Membuat jenis dokumen baru di luar ruang lingkup.
- Alur pendaftar yang terverifikasi mengunggah keempat berkas wajib.

**Gelombang berkesinambungan.** Brief: "Pendaftaran dibuka sepanjang tahun, kecuali kuota telah penuh."
- **Tahun ajaran tujuan PSB `S`:** `S` = `D->year + 1` bila `D` ≥ tanggal `{D->year}-10-05`, selain itu `D->year`. Tahun ajaran `"{S}/{S+1}"` dibuat bila belum ada, tidak aktif kecuali sama dengan `A`.
  - Pada `T1` (2026-10-16): `S` = 2027, tujuan 2027/2028 (= `N`).
  - Pada `T2` (2027-07-03): `S` = 2027, tujuan 2027/2028 (= `A`, yang sedang aktif).
- **Tanggal gelombang:**
  - **Gelombang I:** buka `2026-10-05` + (`S` − 2027) tahun. Tutup = sehari sebelum Gelombang II buka. Kuota 40.
  - **Gelombang II:** buka `2027-02-08` + (`S` − 2027) tahun. Tutup = sehari sebelum Gelombang I tahun ajaran `S+1` buka, yaitu `2027-10-04` + (`S` − 2027) tahun. Kuota 20.
  - Untuk `S` = 2027, tanggal brosur dipakai apa adanya (5 Okt 2026 dan 8 Feb 2027). Tahun lain digeser per tahun penuh dengan `addYears`, sehingga harinya bisa bukan Senin.
- **Akibatnya:** selalu tepat satu gelombang yang menerima pendaftaran pada `D`, dan tidak ada celah tanggal antar-gelombang maupun antar-tahun ajaran. Gelombang tahun ajaran lain tidak dibuat.
- **Kunci idempotensi gelombang:** (`academic_year_id`, `name`), dengan nama "Gelombang I" dan "Gelombang II".
- **Pendaftar:** semua 14 pendaftar masuk ke gelombang yang sedang menerima pendaftaran. `SubmitApplication` hanya menerima gelombang yang buka hari ini.
- **Tanggal bayar PSB:** `max(opens_on gelombang itu, D − k)`, tidak pernah melewati `D`.

**Komposisi pendaftar:** jumlah dan sebaran status sama (14: draft 1, diajukan 4, diverifikasi 2, cadangan 1, ditolak 1, diterima 5 yang 3 di antaranya sudah daftar ulang), dengan perubahan:
- Semua laki-laki, jenjang SLTP atau SLTA saja, dan nama perempuan diganti.
- Jalur hanya `REGULER` atau `SUBSIDI`.
- Tepat 1 pendaftar `SUBSIDI` yang diterima, yaitu yang daftar ulang. Pendaftar `SUBSIDI` lain hanya boleh berstatus belum diterima (tanpa keringanan).
- Pendaftar diterima yang memakai jalur `TAHFIDZ`/`YATIM` diganti `REGULER`.
- Tagihan penerimaan berisi satu item `UANG_MASUK` 10.000.000 (dikurangi 5.000.000 untuk `SUBSIDI`).
- Pembayaran sebelum daftar ulang ≥ 50%, dengan contoh cicilan. Pendaftar diterima yang belum daftar ulang membayar 30%.

### Konten (menggantikan bagian Konten dan Profil di atas)

**Profil sekolah** (`UpdateSchoolProfile`, hanya bila `school.address` kosong):

| Kolom | Isi |
|---|---|
| `name` | "Ma'had Al-Khair Belitang" |
| `tagline` | "Generasi Beradab Bersama Al-Qur'an" |
| `address` | alamat brosur, tanpa penanda usaha |
| `phone`, `whatsapp`, `email` | fiktif |

**Kategori berita** (`SaveCategory` tipe `post`), tepat mengikuti brief:
- Kegiatan Al-Khair
- Prestasi Al-Khair
- Info Kajian
- Akidah & Manhaj
- Tafsir & Hadits
- Fiqh
- Umum

Kategori lama hasil versi sebelumnya ("Kegiatan Santri", "Pengumuman", "Tahfidz") tidak dibuat di database baru.

**Berita** (7 terbit + 1 draft + berita prestasi; minimal 6 dari 7 kategori terpakai):

| Judul | Tipe | Kategori | Catatan |
|---|---|---|---|
| Penerimaan Santri Baru Tahun Ajaran {S} Dibuka | berita | Umum | memuat "pendaftaran dibuka sepanjang tahun selama kuota tersedia" |
| Masa Ta'aruf Santri Baru | kegiatan | Kegiatan Al-Khair | |
| Santri Ikut Kerja Bakti Bersama Warga Mojosari | kegiatan | Kegiatan Al-Khair | |
| Jadwal Kajian Kitab Pekanan untuk Wali Santri | berita | Info Kajian | |
| Ringkasan Kajian: Pentingnya Menuntut Ilmu Sebelum Beramal | berita | Akidah & Manhaj | |
| Program Hafalan Matan dan Hadits Arba'in Santri | kegiatan | Tafsir & Hadits | |
| Santri Belajar Praktik Penyembelihan Hewan Kurban saat Idul Adha | kegiatan | Fiqh | |
| Rencana Pembangunan Ruang Kelas Tahap II | berita | Umum | draft |

- Berita prestasi masuk kategori "Prestasi Al-Khair".
- Isi kajian ditulis sebagai pengumuman atau ringkasan umum. **Tidak mengutip teks ayat atau hadits baru** selain QS. Ali 'Imran: 104 yang sudah ada di brief, untuk menghindari kutipan keliru.
- Manhaj tetap: tidak ada konten perayaan selain Idul Fitri dan Idul Adha (tanpa maulid, isra mi'raj, haul, nisfu sya'ban, tahun baru).
- Judul dan isi berita disaring dengan konstanta `DemoData::FORBIDDEN_CONTENT_TERMS`, yang dipakai juga oleh test.

**Halaman statis.** `StaticPage::SLUGS` tetap `['profil', 'visi-misi', 'kontak']` dan route portal `/{page}` dibatasi ke slug itu. Kode aplikasi **tidak diubah**. Isi setara menu brief:

| Slug | Isi |
|---|---|
| `profil` "Profil Ma'had" | bagian `<h2>` "Tentang Yayasan" (Yayasan Islam Al-Khair Belitang, fiktif wajar), "Selayang Pandang" (fokus tahfidz, bahasa Arab, studi Islam; jenjang MTW/SMP dan TSN/SMA khusus putra; fasilitas dari brosur), "Pengurus Al-Khair" (jabatan + nama fiktif) |
| `visi-misi` | visi dan misi sesuai fokus brief, tanpa mutu lulusan berangka yang belum dikonfirmasi |
| `kontak` | alamat brosur, kontak fiktif, jam layanan |

Info PSB tetap lewat `/psb` yang sudah ada.

**Usulan pekerjaan lanjutan di luar F034** (entri baru, ID ditentukan orkestrator/user, tidak diselipkan): menu portal mengikuti brief (Tentang Yayasan, Pengurus Al-Khair, Selayang Pandang, Info PSB, Kontak) sebagai halaman statis terpisah. Ini butuh perubahan `StaticPage::SLUGS`, route, dan navigasi portal.

**Album** (kunci `slug`):
- "Fasilitas Ma'had" (kategori Fasilitas), 6 foto dengan keterangan sesuai slide brief: bangunan luar masjid, bagian dalam masjid, asrama, kelas, ruang rapat, kantor yayasan.
- "Masa Ta'aruf Santri Baru" (Kegiatan), 5 foto.
- "Ekstrakurikuler Santri" (Kegiatan), 4 foto: futsal, karate, tenis meja, berenang.

**Video:** 3 video dengan ID fiktif. Yang `is_featured` adalah "Video Info PSB Ma'had Al-Khair Belitang" (brief: Video PSB), sisanya video terbaru kegiatan.

### Akun demo

| Akun | Cakupan |
|---|---|
| admin-sekolah | SLTP (MTW/SMP) |
| kesantrian | SLTA (TSN/SMA), diubah dari SLTP+SLTA agar tetap memperlihatkan pembatasan |
| akun lain | tanpa cakupan |

Tidak ada akun yang bercakupan SD.

### Data demo versi lama

Seeder idempotent berdasarkan kunci alami. Database yang sudah berisi data demo versi sebelumnya (SD, putri, biaya asrama/makan) **tidak dimigrasikan**. `docs/demo.md` menyatakan revisi ini butuh `php artisan migrate:fresh` sebelum `db:seed --class=DemoSeeder`.

### Kriteria penerimaan Revisi 2

AC yang tidak disebut di sini tetap berlaku seperti di atas, yaitu AC-1–AC-4, AC-7, AC-13 kecuali bagian yang diganti, AC-17, AC-18, AC-21, AC-22, dan AC-24. Penanda: **[ubah]** mengganti AC lama, **[baru]** tambahan.

- **AC-5 [ubah] Profil & halaman.**
  - `school.name` = "Ma'had Al-Khair Belitang", `school.tagline` = "Generasi Beradab Bersama Al-Qur'an".
  - `school.address` memuat "Mojosari", "Belitang", dan "OKU Timur".
  - `school.phone` dan `school.email` terisi dan fiktif.
  - Body `profil` memuat "Tentang Yayasan", "Selayang Pandang", "Pengurus Al-Khair", dan "khusus putra".
  - Body `kontak` memuat "Mojosari".
  - Body `visi-misi` tidak kosong.
  - Tidak ada "lorem".
  - Bila `school.address` sudah terisi sebelumnya, nilainya tidak diubah.
- **AC-6 [ubah] Akun demo.** Sama seperti sebelumnya, kecuali `scopedUnitIds()` kesantrian = [id SLTA]. Tidak ada user demo dengan cakupan SD.
- **AC-8 [ubah] Staff & rombel.**
  - Staff aktif ≥ 20.
  - Semua staff ber-`position` "ustadz", "musyrif", atau "Mudir" berjenis kelamin L.
  - Tepat 6 rombel `A` (`7A`–`12A`) dan 6 rombel `P`, semuanya gender `L`, jenjang SLTP (7–9) atau SLTA (10–12).
  - Setiap rombel `A` punya wali kelas laki-laki.
- **AC-9 [ubah] Santri & data kurasi.**
  - Total santri 60–100.
  - Pemetaan nama dan NIS dengan `DemoData` tetap (dihitung pada `T1`).
  - Semua santri ber-`gender` `L`.
  - Tidak ada santri, angkatan, rombel, atau tarif ber-`unit_id` SD.
  - Per status: aktif ≥ 65, lulus ≥ 5, keluar ≥ 1, pindah ≥ 1.
  - SLTP dan SLTA masing-masing punya santri aktif.
  - Satu kontak tagihan per santri.
  - ≥ 5 wali dengan ≥ 2 santri, dan ≥ 1 wali dengan 3 santri.
  - `students.unit_id` = `cohorts.unit_id`.
- **AC-10 [ubah] Riwayat kelas.**
  - Klausul "SD → SLTP" dihapus.
  - ≥ 20 santri naik tingkat `P` → `A`.
  - ≥ 1 santri punya enrollment `P` di SLTP dan `A` di SLTA.
  - Santri hasil daftar ulang dikecualikan dari kewajiban enrollment `A`.
  - Lainnya tetap.
- **AC-11 [ubah] Asrama.**
  - 2 asrama aktif, keduanya gender `L`, total 8 kamar.
  - Setiap santri aktif yang punya enrollment `A` punya penempatan aktif.
  - Santri aktif tanpa penempatan aktif hanya santri ber-`applicant_id`.
  - Tidak ada penempatan aktif untuk santri non-aktif.
  - Jumlah penghuni per kamar ≤ kapasitas.
  - ≥ 1 santri punya 2 baris `room_assignments`.
  - Setiap kamar punya tepat 1 `musyrif` aktif ber-`room_id` kamar itu, dan staff musyrif berjenis kelamin L.
  - Setiap asrama punya tepat 1 `kepala_asrama` aktif.
- **AC-12 [ubah] Jenis biaya & tarif.**
  - Tepat 4 jenis biaya sesuai tabel Revisi 2 (`SPP`, `FORMULIR`, `UANG_MASUK`, `DAFTAR_ULANG`) dengan frekuensi, applies_to, dan billing_group sesuai. Tidak ada `ASRAMA`, `MAKAN`, `LAUNDRY`, `UANG_PANGKAL`, `SERAGAM`, `KITAB`.
  - Tarif dasar SLTP dan SLTA: SPP 1.600.000, FORMULIR 300.000, UANG_MASUK 10.000.000, DAFTAR_ULANG 1.500.000.
  - Tepat 1 tarif ber-`cohort_id` (SPP SLTA, 1.500.000). Tidak ada tarif ber-`dormitory_id`. Tidak ada tarif SD.
  - Semua nominal kelipatan 1.000 dan 50.000–10.000.000.
  - `docs/demo.md` memuat kata "contoh" pada baris daftar ulang tahunan.
- **AC-13 [ubah] Beasiswa & keringanan.**
  - 3 program aktif (nominal, persen, potongan).
  - Jumlah pemilik berbeda (santri atau pendaftar) dari `fee_overrides` ber-`scholarship_id` ≤ 3.
  - Tepat 1 keringanan santri tanpa `scholarship_id`.
  - Keringanan santri `approved_by` bendahara. Keringanan jalur PSB boleh `approved_by` admin-psb (catatan RED).
- **AC-14 [ubah] Tagihan bulanan.**
  - Untuk setiap periode, jumlah tagihan aktif `BULANAN` = jumlah santri aktif dengan `entered_on` ≤ akhir periode.
  - Tanpa duplikat.
  - Setiap tagihan bulanan berisi tepat 1 item `SPP`.
  - `base_amount` 1.600.000, kecuali santri SLTA angkatan `Y−2` (1.500.000).
  - Santri Subsidi Yatim: `amount` 800.000. Santri Beasiswa Tahfidz: `amount` = 50% `base_amount`. Santri keringanan wawancara: `discount_amount` 300.000.
- **AC-15 [ubah] Pembayaran.**
  - Tagihan bulanan `lunas` ≥ 100, `sebagian` ≥ 5, `belum` dengan `due_on` < `T1` ≥ 10.
  - `paid_total` = jumlah pembayaran `diterima`.
  - Tidak ada `paid_on` > `T1`.
  - Tepat 1 pembayaran `batal`.
  - Setiap tagihan `sebagian` punya `paid_total` kelipatan 1.000 dan < `total`.
  - Klausul alokasi prioritas dan klausul MAKAN/SPP dihapus.
- **AC-16 [ubah] PSB.**
  - Tahun ajaran 2027/2028 punya tepat 2 gelombang:
    - "Gelombang I" 2026-10-05 s.d. 2027-02-07, kuota 40;
    - "Gelombang II" 2027-02-08 s.d. 2027-10-04, kuota 20.
  - Tepat satu gelombang yang `isAcceptingRegistrationsOn(T1)`, yaitu Gelombang I.
  - Jalur aktif tepat `REGULER` dan `SUBSIDI`. `SUBSIDI.scholarship_id` = Subsidi Uang Masuk.
  - Syarat berkas: `KK`, `AKTA`, `RAPOR`, `FOTO` wajib; `IJAZAH` tidak wajib.
  - 14 pendaftar, semua `L`, jenjang SLTP/SLTA, dengan sebaran status seperti AC-16 lama.
  - Setiap pendaftar non-draft punya tagihan `PSB_DAFTAR` 300.000.
  - Tagihan penerimaan berisi satu item `UANG_MASUK`.
  - Pendaftar `SUBSIDI` yang daftar ulang punya item `UANG_MASUK` dengan `discount_amount` 5.000.000.
  - 3 santri hasil daftar ulang; satu memakai wali yang sudah ada.
  - ≥ 2 dokumen hasil salinan PSB.
  - Berkas pendaftar `menunggu` dan `ditolak` ada.
- **AC-19 [ubah] Prestasi.**
  - Sama seperti sebelumnya: 6 prestasi, 4 terverifikasi, 1 jadi berita, peserta ber-`entered_on` ≤ `achieved_on`.
  - Semua peserta berjenis kelamin L.
  - Tidak ada judul yang memuat "Putri".
  - Berita prestasi berkategori "Prestasi Al-Khair".
- **AC-20 [ubah] Konten & portal.**
  - Kategori `post` yang dibuat seeder tepat 7 nama brief, dan ≥ 6 di antaranya punya berita.
  - Posts `terbit` ≥ 8 (termasuk prestasi) dengan `published_at` ≤ `T1`, `draft` ≥ 1.
  - Album "Fasilitas Ma'had" punya 6 foto. Album lain ≥ 4 foto. Berkas foto ada di disk `public`.
  - ≥ 4 posts bersampul.
  - 3 video, tepat 1 `is_featured` yang judulnya memuat "PSB".
  - Route portal yang sama mengembalikan 200, dan beranda memuat "Ma'had Al-Khair Belitang".
- **AC-23 [ubah] Relatif terhadap hari ini** (database baru, `T2 = 2027-07-03 08:00 Asia/Jakarta`).
  - `2027/2028` aktif (satu-satunya), dan `2026/2027` ada dan tidak aktif.
  - Ada tagihan periode 2027-04-01 dan 2027-07-01.
  - Tidak ada `paid_on` > 2027-07-03.
  - Gelombang yang menerima pendaftaran pada `T2` adalah "Gelombang II" tahun ajaran **2027/2028** (bukan 2028/2029), dengan `closes_on` 2027-10-04.
  - Tidak ada gelombang 2028/2029.
  - Tidak ada `published_at` atau `achieved_on` setelah `T2`.
- **AC-25 [baru] Nama jenjang.**
  - Setelah seeding pada database baru: `units` SLTP bernama "MTW/SMP", SLTA bernama "TSN/SMA", kode tidak berubah, dan baris SD tetap ada.
  - Bila nama SLTP sudah diubah admin sebelum seeding (mis. "SMP IT"), nama itu tidak ditimpa.
- **AC-26 [baru] Larangan data brosur.**
  - Tidak ada string berikut di database hasil seeding, yang diperiksa pada:
    - `settings.value`, `pages.body`;
    - `posts.title` dan `posts.body`;
    - `staff.name`, `staff.phone`;
    - `guardians.name`, `guardians.phone`;
    - `applicants.guardian_phone`;
    - `users.name`.
  - Daftar string: `0858-3233-3725`, `085832333725`, `0856-6994-1929`, `085669941929`, `7286406461`, `Wujud`, `Said Yai`, `Rendi Sulistiawan`, `Arwitra`, `Waterboom`.
  - Daftar string tersebut didefinisikan di test, bukan di kode aplikasi.
- **AC-27 [baru] Manhaj konten.**
  - Tidak ada `posts.title`/`posts.body`, `pages.body`, `albums.title`/`description`, `photos.caption`, atau `videos.title`/`description` yang memuat (case-insensitive) salah satu istilah di `DemoData::FORBIDDEN_CONTENT_TERMS`.
  - Daftar itu minimal berisi "maulid", "isra mi", "isra' mi", "haul", "nisfu", dan "tahun baru".

### Rencana test Revisi 2

- **File dan jumlah seeding tetap.** Masih satu file `tests/Feature/Database/Seeders/DemoSeederTest.php`, dengan tiga kali seeding penuh, `travelTo`, dan group `seeder-demo`.
- **Test utama:** assertion lama yang bertentangan diganti sesuai AC [ubah], dan AC-25 bagian 1, AC-26, serta AC-27 ditambahkan.
- **AC-25 bagian 2:** digabung ke test `T2`. Sebelum seeding, `InstallSeeder` dijalankan lalu nama SLTP diubah, sehingga tidak ada seeding tambahan.
- **Test `VERIFY`:** assertion yang mengandalkan santri nonmukim, SD, putri, atau biaya asrama/makan disesuaikan oleh red-tester, tanpa menambah seeding.
- **Test dokumentasi AC-24:** ditambah pemeriksaan kata "contoh" untuk daftar ulang (AC-12) dan catatan `migrate:fresh`.

### Urutan implementasi Revisi 2

1. `DemoData`: nama putra, komposisi santri dan keluarga, staff laki-laki, asrama putra dan musyrif per kamar, jenis biaya/tarif/program brosur, pendaftar, kategori/berita/halaman/album/video brief, dan `FORBIDDEN_CONTENT_TERMS`.
2. `DemoCalendar`: `admissionYear()` (`S`) dan tanggal kedua gelombang.
3. Nama unit (AC-25), rombel, asrama, dan penempatan semua santri (AC-8–AC-11).
4. Keuangan dan jalur `SUBSIDI` (AC-12–AC-15).
5. PSB dengan dua gelombang (AC-16, AC-23).
6. Konten (AC-5, AC-19, AC-20, AC-26, AC-27).
7. `docs/demo.md`: tarif "contoh", `migrate:fresh`, dan jenjang MTW/SMP–TSN/SMA khusus putra. Lalu Pint, dan verifikasi MySQL dua kali seed seperti sebelumnya.

### Risiko & pertanyaan Revisi 2

- **Nominal daftar ulang tahunan** (1.500.000) adalah contoh. Konfirmasi ke pondok sudah tercatat di `docs/pertanyaan-mahad-al-khair.md` bila ada; bila belum, orkestrator menambahkannya.
- **Unit SD tetap ada** dan masih bisa dipilih di formulir PSB publik, karena tidak ada saklar per unit. Menyembunyikan jenjang yang tidak dipakai adalah usulan pekerjaan lanjutan di luar F034.
- **Menonaktifkan jalur bawaan** (`PRESTASI`, `TAHFIDZ`, `YATIM`) mengubah data instalasi di database demo. Ini disengaja dan dicatat di `docs/demo.md`.
- **Tanpa tagihan multi-item,** data demo tidak lagi memperlihatkan alokasi cicilan per prioritas. Fungsi itu tetap teruji di test F007.
- **Gelombang di luar 2027/2028** memakai tanggal geser per tahun penuh, sehingga harinya bisa bukan Senin.

## Riwayat revisi

- DRAFT 1 (product-manager): rencana awal.
- Catatan RED (red-tester): AC-13 dilonggarkan, keringanan jalur PSB (override milik pendaftar) boleh approved_by aktor penerima (admin-psb); keringanan santri tetap bendahara. billing_group PSB memakai underscore (PSB_DAFTAR, PSB_TERIMA).
- REVISED 1 (product-manager, menanggapi Review #1):
  - Major 1: ketentuan eksekusi baru "Bebas dari Auth dan `UnitScope`" (`Auth::forgetGuards()` di awal `run()`, daftar Action yang membaca `Auth::user()` dan perilakunya tanpa login, lookup wajib `withoutUnitScope()`/`withTrashed()`); urutan test utama ditetapkan (tanpa login sebelum seed pertama, seed kedua dijalankan saat admin-sekolah login, `actingAs` cakupan setelah seluruh seeding); AC-22 menambah syarat seed kedua saat login dan `Auth::check()` false sesudahnya.
  - Major 2: tabel baru "Kunci idempotensi per bagian" (termasuk asrama, kamar, pindah kamar, pembina, enrollment `P`/`A`, wali kelas, pivot wali-santri, kolom kunci tarif, sertifikat prestasi, berkas pendaftar, penggantian KK, surat santri + jenis + `purpose`); surat batal memakai santri kelima yang tidak punya surat lain; AC-18 diperketat.
  - Minor: NIS dihitung dari offset tahun masuk relatif `Y` (AC-9 pada `T1`); `travelTo` + group `seeder-demo`; password teks biasa (cast `hashed`) + `email_verified_at`; catatan enrollment `P` tidak selaras jenjang setelah `ChangeStudentUnit` (AC-10 hanya memeriksa `A`); peserta prestasi ber-`entered_on` ≤ tanggal prestasi (AC-19); `ReRegisterApplicant` tanpa `entered_on` (pakai `defaultEnteredOn`); peringatan konsol `default_password_warning` bila `DEMO_PASSWORD` kosong (AC-7b lewat `artisan db:seed`).
- REVISED 2 (product-manager, 16 Okt 2026): bagian "Revisi 2 — sesuai brosur PSB": khusus putra, tanpa data SD, unit SLTP/SLTA dinamai MTW/SMP dan TSN/SMA, tarif brosur (SPP 1,6 jt all-in, pendaftaran 300 rb, uang masuk 10 jt, daftar ulang contoh), beasiswa ≤ 3 penerima, semua santri mukim kecuali hasil daftar ulang, jalur REGULER + SUBSIDI, dua gelombang berkesinambungan (tanggal brosur sebagai acuan, tahun ajaran tujuan `S`), kategori dan halaman mengikuti brief tanpa mengubah `StaticPage::SLUGS`; AC diubah: 5, 6, 8–16, 19, 20, 23; AC baru: 25–27.
- Revisi 3 (17 Okt 2026, user): video demo dikosongkan karena ID rekaan tidak bisa diputar; logo cetak diisi logo simbol; placeholder gambar tanpa teks

## Review #1 — 2026-10-16

Verdict: CHANGES_REQUESTED

Nama Action, signature, dan perilaku utama sudah dicek ke `app/Actions` (CreateStudent, EnrollStudent, PromoteStudents, ChangeStudentUnit, CopyClassroomsFromYear, AssignRoom, GenerateMonthlyInvoices, RecordPayment, ReRegisterApplicant, UpdateSchoolProfile, dst.) dan seeder yang ada. Semua Action yang disebut ada. Penjaga production, pemisahan dari `DatabaseSeeder`/`InstallSeeder`, kepatuhan pada konvensi data keuangan, soft delete, `encrypted`, dan portabilitas DB sudah benar. Dua temuan major di bawah harus diperbaiki sebelum RED.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | Ketentuan eksekusi + Rencana test (test utama) | Plan menyatakan seeder jalan "tanpa user login", tetapi test utama memanggil `actingAs(admin-sekolah)` (AC-6) lalu menjalankan seeder kedua kali (AC-22) di test yang sama. Beberapa Action membaca `Auth::user()` (`SaveAdmissionRequirement::scopedUnitIds`, `StoresDocuments::ownerVisible`/Guardian, `FailsWithPrestasiErrors::ensureFullyVisible`), dan `UnitScope` pada `Student`/`Invoice`/`Applicant` ikut aktif bila ada user login. Akibatnya seed ulang bisa gagal atau pengecekan kunci alami (`students.nis`, pendaftar, invoice) tidak melihat baris jenjang lain lalu mencoba membuat duplikat (unique violation). Plan juga tidak mewajibkan `withoutUnitScope()` dan `withTrashed()` pada pengecekan idempotensi (pendaftar dan santri terhapus disebut di kunci, tetapi mekanismenya tidak). | Tambahkan ke Ketentuan eksekusi: semua lookup idempotensi dan pembacaan data lintas jenjang memakai `withoutUnitScope()` (dan `withTrashed()` untuk `Student`/`Applicant`/`Post`). Di test, panggil `auth()->logout()` / `Auth::forgetGuards()` sebelum seed kedua (atau ambil `actingAs` hanya setelah seluruh seeding, termasuk seed ulang, selesai) dan tulis di rencana test urutannya. Tambahkan assertion bahwa seed kedua yang berjalan saat user admin-sekolah login tidak menggandakan data (atau catat eksplisit bahwa seeder mengabaikan `Auth`). |
| 2 | major | Komposisi data kurasi (kunci idempotensi) vs AC-22 | Kunci alami hanya disebut untuk sebagian bagian. Tidak ada kunci untuk: `Dormitory` (usul `code`), `Room` (`dormitory_id`+nama), `AssignRoom` pindah kamar (baris kedua bisa terulang atau bentrok dengan `start_before_current` pada seed ulang), `AssignSupervisor`, `SaveScholarship`/`SaveAlbum` sudah ada tetapi `SaveFeeRate` hanya "kunci = kombinasi unik" tanpa daftar kolom, enrollment tahun `P` dan `A`, pengaturan `homeroom_staff_id`, serta pasangan wali-santri. Surat keluar: "dilewati bila santri sudah punya surat jenis itu" ambigu untuk surat `keterangan_aktif` tambahan yang dibatalkan (santri yang sama dengan salah satu surat aktif akan membuatnya dilewati selamanya, atau terduplikasi bila santri berbeda). Dokumen `SERTIFIKAT` prestasi, berkas pendaftar, dan `ReplaceDocument` juga tidak punya kunci eksplisit. | Tambahkan tabel "Kunci idempotensi per bagian" (kolom: bagian, kunci, perilaku bila sudah ada). Untuk surat gunakan kunci santri + jenis + `purpose`; tetapkan santri berbeda untuk surat batal. Pindah kamar: lewati bila santri sudah punya >= 2 baris `room_assignments` atau penempatan aktif di kamar tujuan. Pengaturan `AssignSupervisor`: lewati bila pembina aktif dengan peran dan asrama/kamar sama sudah ada. |
| 3 | minor | Kalender demo / Komposisi (NIS) vs AC-9 dan AC-23 | NIS "tetap di `DemoData`" berpola `{tahun masuk}{urut}`, sedangkan tahun masuk dan `entered_on` relatif terhadap `Y`. Pada `T2` NIS literal tidak lagi cocok dengan tahun masuk. | Simpan di `DemoData` offset tahun masuk (mis. `Y-1`) dan urutan, NIS dihitung oleh `DemoCalendar`. AC-9 "setiap NIS di `DemoData`" dihitung pada `T1`. |
| 4 | minor | Rencana test (waktu) | `Carbon::setTestNow(T1)` hanya aman bila `DemoCalendar` (`CarbonImmutable::now`), `RecordPayment::paidOn`, dan `ReRegisterApplicant::earliestEnteredOn` (semuanya `CarbonImmutable`) ikut terpengaruh. | Pakai `$this->travelTo(T1)` (menutup Carbon dan CarbonImmutable) dan nyatakan di rencana test. Tambahkan `->group('seeder-demo')` ke test seeding penuh agar bisa dijalankan terpisah, tetap ikut full suite. |
| 5 | minor | Akun demo | Plan tidak menyebut bahwa `User.password` bercast `hashed` (`app/Models/User.php`). Memanggil `Hash::make` lalu menyimpan akan meng-hash dua kali, dan AC-7 gagal. Plan juga tidak menyebut `email_verified_at`. | Tulis "berikan password teks biasa ke model (cast `hashed` yang meng-hash)". Isi `email_verified_at` bila panel mensyaratkannya. |
| 6 | minor | Santri (ChangeStudentUnit) | `ChangeStudentUnit` hanya mengganti `unit_id`/`cohort_id`; enrollment `P` santri tetap di rombel jenjang lama (SD 6A, SLTP 9). `students.unit_id` jadi tidak sama dengan `classrooms.unit_id` pada enrollment `P`. Ini perilaku Action yang sah, tetapi AC-10 dan assertion lain harus tidak mengasumsikan keselarasan itu. | Tambahkan catatan di plan dan pastikan tidak ada AC yang menuntut `enrollments.classroom.unit_id` = `students.unit_id` untuk enrollment `P`. |
| 7 | minor | Prestasi (AC-19) / Santri | Peserta prestasi dengan tanggal `D − 260` atau `D − 200` bisa jatuh pada santri angkatan `Y` yang `entered_on`-nya setelah tanggal prestasi. | Tetapkan peserta prestasi hanya dari santri yang `entered_on` <= tanggal prestasi (santri angkatan lebih lama). |
| 8 | minor | Kalender demo | Bila `D` jatuh sebelum 1 Juli, `AcademicYearSeeder` (dipanggil lewat `InstallSeeder`) tetap mengaktifkan `2026/2027` lalu `DemoSeeder` mengaktifkan `A`. Itu sudah ditangani, tetapi `entered_on` santri `Y+1` hasil daftar ulang harus >= `ReRegisterApplicant::earliestEnteredOn()` (awal bulan depan); plan hanya menyebut "masa depan". | Nyatakan memakai `defaultEnteredOn` (tidak mengirim `entered_on`) pada `ReRegisterApplicant`. |
| 9 | minor | Keamanan password | Disetujui orkestrator (publik, peringatan di `docs/demo.md`). Tambahan: ringkasan konsol seeder sebaiknya menampilkan peringatan bila `DEMO_PASSWORD` kosong (memakai password bawaan publik). | Tambah teks `pondok.demo.default_password_warning` di `lang/id` dan tampilkan lewat `$this->command?->warn`. |

Ringkasan: 0 blocker, 2 major, 7 minor. Yang sudah sesuai: AC memetakan seluruh `acceptance` F034, `depends_on` F002/F003/F007/F008/F012/F014/F015/F017/F018/F019 (periksa orkestrator bahwa semuanya `done`), test seeding penuh dibatasi tiga kali, tanpa SQL mentah, tanpa nama/lembaga nyata, uang kelipatan 1.000, tidak ada penghapusan data keuangan (koreksi lewat `CancelPayment`).

## Review #2 — 2026-10-16

Verdict: APPROVED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | Kunci idempotensi, "Berkas pendaftar" | Kunci memakai kolom `applicant_documents.admission_requirement_id`; nama kolom belum diverifikasi ke migration. | Green-coder cocokkan dengan skema nyata. |
| 2 | minor | `Auth::forgetGuards()` | Melepas login pemanggil di proses itu (sudah dicatat di `docs/demo.md`). Test harus `actingAs` ulang sesudah seed kedua (sudah ada di urutan test). | Tidak ada tindakan. |

Review #1: 2 major dan 7 minor tertangani (Auth/UnitScope, tabel kunci idempotensi, NIS relatif `Y`, `travelTo` + group, password `hashed`, `defaultEnteredOn`, peringatan password).

## Review #3 — 2026-10-16

Verdict: APPROVED

Yang diperiksa terhadap kode nyata: `UnitSeeder::UNITS`, `SaveAdmissionWave` (validasi nama, `closes_before_opens`, tumpang tindih hanya dalam satu tahun ajaran), `SubmitApplication` (wajib `isAcceptingRegistrationsOn(now Asia/Jakarta)`, kuota), `ReRegisterApplicant` (`defaultEnteredOn`), `SaveAdmissionTrack`, `SaveFeeType` (`tahunan` valid, `billing_group` terpisah), `GenerateMonthlyInvoices` (hanya `bulanan`, satu item tetap membuat satu tagihan), `ContentCategorySeeder` (hanya kategori album), `StaticPage::SLUGS`.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | AC-26 | String "Wujud" (huruf besar W) bisa cocok palsu dengan awal kalimat Indonesia ("Wujudkan ..."). | Cocokkan nama lengkap dari brosur, atau pencocokan kata utuh (`\b`) peka huruf besar. |
| 2 | minor | Asrama `UMR` | 30 santri TSN di 4 kamar berkapasitas 8 (32): muat, tetapi pindah kamar hanya mungkin ke kamar yang tidak penuh. | Distribusi 8/8/7/7, pindah kamar ke kamar berisi 7. |
| 3 | minor | AC-13 | "Pemilik berbeda" pendaftar yang sudah jadi santri memiliki override ber-`applicant_id` dan `student_id` sekaligus. | Hitung pemilik sebagai `COALESCE(student_id, applicant_id)` atau satu orang per `students.applicant_id`. |
| 4 | minor | Jalur PSB | `SaveAdmissionTrack` memperbarui seluruh field dari `$data` (mis. `scholarship_id` tanpa nilai menjadi null). Menonaktifkan jalur bawaan harus mengirim ulang `code`, `name`, `terms`, dan `scholarship_id` yang ada. | Catatan untuk green-coder. |
| 5 | minor | Kontrak AC-23 | Pada `T2` santri hasil daftar ulang berangkatan `Y` (sama dengan santri baru) dan `entered_on` 2027-08-01; NIS otomatis harus tidak bentrok dengan NIS kurasi angkatan `Y`. | Uji `GenerateStudentNumber` melanjutkan urutan; tidak ada perubahan rencana. |

Jawaban poin pemeriksaan:
- Menonaktifkan jalur dan mengganti nama unit aman. Nama unit hanya diganti bila masih bawaan, kode tidak berubah, dan test lain memakai `RefreshDatabase` tanpa `DemoSeeder` (termasuk `ActiveStudentsWidgetTest` yang mengasersi nama bawaan).
- Gelombang I (s.d. 2027-02-07) dan II (mulai 2027-02-08) tidak tumpang tindih dan sama-sama di tahun ajaran tujuan. Tidak ada validasi tahun ajaran aktif atau lampau. Tepat satu gelombang menerima pendaftaran pada `D` (juga `T2`: Gelombang II).
- SPP satu item tetap memicu cicilan (`floor(total/2/1000)*1000`, kurang dari total, kelipatan 1.000).
- Tanpa jenis biaya `mukim`, tidak ada aturan `applies_to` yang dilanggar. Jalur mukim tetap diuji test generator F007.
- AC-23, AC-26, AC-27 bisa diuji. Seeding penuh tetap 3 kali.
