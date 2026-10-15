# ERD Pondok

> Sumber: diskusi desain Pondok (pondok.org), Oktober 2026. Diagram Mermaid tampil langsung di GitHub. Kolom `created_at`/`updated_at` ada di semua tabel dan tidak ditulis di diagram.

Skema database untuk modul di PRD: Akademik & Santri, Asrama, Tahfidz, Perizinan, Dokumen, Prestasi, PSB, Keuangan, Konten Publik, dan Pengguna & RBAC. Nama tabel memakai bahasa Inggris agar sesuai konvensi Eloquent; istilah domain dijelaskan di kolom komentar.

## Ringkasan relasi antar modul

Pusatnya adalah `students`. Jenjang (SD/SLTP/SLTA) disimpan di satu tabel `units`, dan semua data siswa ada dalam satu tabel dengan `unit_id`. Ini menjawab pertanyaan di PRD bagian 3: tabel terpusat, bukan skema terpisah per jenjang.

Notasi relasi:

- `||--o{` satu ke banyak
- `|o--o|` satu ke nol/satu
- `}o--o{` banyak ke banyak (pivot)

```mermaid
erDiagram
  units ||--o{ cohorts : "punya angkatan"
  units ||--o{ classrooms : "punya kelas"
  units ||--o{ students : "jenjang aktif"
  cohorts ||--o{ students : "angkatan"
  students ||--o{ enrollments : "riwayat kelas"
  classrooms ||--o{ enrollments : ""
  academic_years ||--o{ enrollments : ""
  students }o--o{ guardians : "student_guardian"
  staff ||--o{ classrooms : "wali kelas"
  dormitories ||--o{ rooms : "kamar"
  rooms ||--o{ room_assignments : "penghuni"
  students ||--o{ room_assignments : "riwayat asrama"
  staff ||--o{ dormitory_supervisors : "musyrif"
  dormitories ||--o{ dormitory_supervisors : ""
  staff ||--o{ halaqahs : "pengampu"
  halaqahs ||--o{ halaqah_members : "anggota"
  students ||--o{ halaqah_members : ""
  students ||--o{ memorization_records : "setoran"
  students ||--o{ tahfidz_exams : "ujian"
  students ||--o{ leave_requests : "izin"
  homecoming_periods ||--o{ leave_requests : "pulang massal"
  students ||--o{ visits : "kunjungan"
  document_types ||--o{ documents : "jenis"
  students ||--o{ documents : "berkas santri"
  students ||--o{ issued_letters : "surat keluar"
  achievements ||--o{ achievement_student : "peserta"
  students ||--o{ achievement_student : "prestasi"
  achievements |o--o| posts : "dipublikasikan"
  admission_waves ||--o{ applicants : "gelombang"
  applicants |o--o| students : "diterima jadi"
  applicants ||--o{ applicant_documents : "berkas"
  admission_tracks ||--o{ applicants : "jalur"
  applicants ||--o{ invoices : "biaya PSB"
  fee_types ||--o{ fee_rates : "tarif"
  units ||--o{ fee_rates : "tarif default"
  cohorts ||--o{ fee_rates : "tarif angkatan"
  students ||--o{ fee_overrides : "nominal khusus"
  scholarships ||--o{ fee_overrides : "beasiswa"
  students ||--o{ invoices : "tagihan"
  invoices ||--o{ invoice_items : "rincian biaya"
  fee_types ||--o{ invoice_items : ""
  invoices ||--o{ payments : "cicilan"
  users }o--o{ units : "unit_user"
  users ||--o{ posts : "penulis"
  
```

## Akademik & Santri

```mermaid
erDiagram
  units {
    bigint id PK
    string code UK "SD | SLTP | SLTA"
    string name
    tinyint sort_order
  }
  academic_years {
    bigint id PK
    string name UK "2026/2027"
    date starts_on
    date ends_on
    boolean is_active "hanya satu yang aktif"
  }
  cohorts {
    bigint id PK
    bigint unit_id FK
    smallint entry_year "tahun masuk, mis. 2026"
    string name "Angkatan 2026"
  }
  classrooms {
    bigint id PK
    bigint academic_year_id FK "rombel per tahun ajaran"
    bigint unit_id FK
    tinyint grade "tingkat 1 sampai 12"
    string name "1A, 1B, 1C"
    enum gender "L | P | campur"
    bigint homeroom_staff_id FK "wali kelas, nullable"
    smallint capacity "nullable"
  }
  staff {
    bigint id PK
    bigint user_id FK "nullable, hanya jika perlu login"
    string nip UK "nomor pegawai, nullable"
    string name
    enum gender "L | P"
    string phone
    string position "ustadz, musyrif, TU"
    boolean is_active
  }
  students {
    bigint id PK
    bigint unit_id FK "jenjang saat ini"
    bigint cohort_id FK "angkatan di jenjang ini"
    bigint applicant_id FK "asal PSB, nullable"
    string nis UK
    string nisn UK "nullable"
    string name
    enum gender "L | P"
    string birth_place
    date birth_date
    text address
    enum status "aktif | lulus | keluar | pindah"
    date entered_on
    string photo_path "nullable"
    timestamp deleted_at "soft delete"
  }
  enrollments {
    bigint id PK
    bigint student_id FK
    bigint classroom_id FK
    bigint academic_year_id FK
  }
  guardians {
    bigint id PK
    string name
    string phone
    string occupation "nullable"
    text address "nullable"
    bigint user_id FK "akun portal wali, nullable"
  }
  student_guardian {
    bigint student_id PK,FK
    bigint guardian_id PK,FK
    enum relation "ayah | ibu | wali"
    boolean is_billing_contact "penerima info tagihan"
  }
  units ||--o{ cohorts : ""
  units ||--o{ classrooms : ""
  academic_years ||--o{ classrooms : ""
  staff ||--o{ classrooms : "wali kelas"
  units ||--o{ students : ""
  cohorts ||--o{ students : ""
  students ||--o{ enrollments : ""
  classrooms ||--o{ enrollments : ""
  academic_years ||--o{ enrollments : ""
  students ||--o{ student_guardian : ""
  guardians ||--o{ student_guardian : ""
  
```

- **Kelas paralel:** 1A, 1B, 1C adalah tiga baris `classrooms` dengan `grade = 1` dan `name` berbeda. Laporan "seluruh kelas 1" cukup dengan filter `grade`.
- `classrooms` dibuat per tahun ajaran (rombel), karena wali kelas, jumlah paralel, dan pembagian putra/putri bisa berubah tiap tahun. Kelas tahun lalu tetap utuh untuk arsip. Panel admin bisa menyalin daftar kelas dari tahun sebelumnya.
- `staff` menampung ustadz, wali kelas, dan musyrif. Tidak semua perlu login, jadi `user_id` boleh kosong.
- `enrollments` menyimpan riwayat kelas per tahun ajaran, jadi kenaikan kelas tidak menimpa data lama.
- Saat santri lanjut dari SD ke SLTP, `unit_id` dan `cohort_id` di `students` diperbarui ke jenjang baru. Riwayatnya tetap terbaca dari `enrollments` dan snapshot di `invoices`.
- `students.unit_id` sengaja diduplikasi dari `cohorts.unit_id` supaya filter dan scope per jenjang cepat. Validasi kecocokannya di Action class.
- Wali memakai pivot karena kakak-adik di pondok sering berbagi wali yang sama, sehingga tagihan bisa digabung per keluarga.

## Asrama & Musyrif

```mermaid
erDiagram
  dormitories {
    bigint id PK
    string code UK "ABK-1"
    string name "Asrama Abu Bakar"
    enum gender "L | P"
    string location "gedung atau blok, nullable"
    boolean is_active
  }
  rooms {
    bigint id PK
    bigint dormitory_id FK
    string name "Kamar 101"
    tinyint floor "nullable"
    smallint capacity
    boolean is_active
  }
  room_assignments {
    bigint id PK
    bigint student_id FK
    bigint room_id FK
    bigint academic_year_id FK
    date start_on
    date end_on "nullable = masih menempati"
    string note "alasan pindah, nullable"
    tinyint active_marker "generated stored: 1 bila end_on null, unique dengan student_id"
  }
  dormitory_supervisors {
    bigint id PK
    bigint dormitory_id FK
    bigint room_id FK "nullable = untuk seluruh asrama"
    bigint staff_id FK
    bigint academic_year_id FK
    enum role "kepala_asrama | musyrif"
    date start_on
    date end_on "nullable"
  }
  dormitories ||--o{ rooms : ""
  rooms ||--o{ room_assignments : ""
  students ||--o{ room_assignments : ""
  academic_years ||--o{ room_assignments : ""
  dormitories ||--o{ dormitory_supervisors : ""
  rooms ||--o{ dormitory_supervisors : ""
  staff ||--o{ dormitory_supervisors : ""
  
```

- **Santri di asrama mana:** baris `room_assignments` dengan `end_on` kosong. Asramanya dibaca lewat `rooms.dormitory_id`. Pindah kamar di tengah tahun cukup menutup baris lama dan membuat baris baru, sehingga riwayatnya tersimpan.
- **Musyrifnya siapa:** `dormitory_supervisors` bisa per asrama (`room_id` kosong) atau per kamar. Jadi pola "satu musyrif per asrama" dan "satu musyrif per kamar" sama-sama didukung, dan pergantian musyrif tercatat.
- Santri yang tidak punya penempatan aktif adalah santri nonmukim (pulang-pergi), tanpa kolom tambahan.
- `dormitories.gender` dicocokkan dengan `students.gender` saat penempatan, dan `rooms.capacity` dicek di Action `AssignRoom`.

## Tahfidz

```mermaid
erDiagram
  quran_surahs {
    smallint id PK "1 sampai 114, data seed"
    string name "Al-Baqarah"
    string name_arabic
    smallint ayah_count
    tinyint juz_start
  }
  halaqahs {
    bigint id PK
    bigint academic_year_id FK
    bigint unit_id FK "nullable = lintas jenjang"
    bigint teacher_staff_id FK "ustadz pengampu"
    string name "Halaqah Abu Hurairah"
    enum gender "L | P"
    string schedule "Ba'da Subuh dan Maghrib"
    boolean is_active
  }
  halaqah_members {
    bigint id PK
    bigint halaqah_id FK
    bigint student_id FK
    date joined_on
    date left_on "nullable = masih aktif"
  }
  memorization_records {
    bigint id PK
    bigint student_id FK
    bigint halaqah_id FK
    bigint listener_staff_id FK "penyimak"
    date recorded_on
    enum session "subuh | pagi | sore | maghrib | malam"
    enum type "ziyadah | murajaah | tasmi"
    smallint from_surah_id FK
    smallint from_ayah
    smallint to_surah_id FK
    smallint to_ayah
    decimal pages "jumlah halaman, mushaf 15 baris"
    enum grade "mumtaz | jayyid_jiddan | jayyid | maqbul | ulang"
    tinyint mistakes "jumlah kesalahan"
    text note "nullable"
  }
  student_juz_progress {
    bigint student_id PK,FK
    tinyint juz PK "1 sampai 30"
    enum status "belum | proses | hafal | lulus_ujian"
    date completed_on "nullable"
  }
  tahfidz_targets {
    bigint id PK
    bigint academic_year_id FK
    bigint unit_id FK
    tinyint grade "nullable = semua tingkat"
    decimal target_juz "target per tahun"
    decimal target_pages_weekly "nullable"
  }
  tahfidz_exams {
    bigint id PK
    bigint student_id FK
    tinyint juz_from
    tinyint juz_to
    enum kind "kenaikan_juz | tasmi_5_juz | khataman"
    date exam_on
    bigint examiner_staff_id FK
    tinyint score "0 sampai 100"
    enum result "lulus | ulang"
    string certificate_no UK "nullable, syahadah"
  }
  halaqahs ||--o{ halaqah_members : ""
  students ||--o{ halaqah_members : ""
  staff ||--o{ halaqahs : ""
  students ||--o{ memorization_records : ""
  halaqahs ||--o{ memorization_records : ""
  quran_surahs ||--o{ memorization_records : "rentang ayat"
  students ||--o{ student_juz_progress : ""
  students ||--o{ tahfidz_exams : ""
  staff ||--o{ tahfidz_exams : "penguji"
  
```

- **Setoran harian** dicatat sebagai rentang surah dan ayat, bukan teks bebas. Dengan begitu total halaman, capaian juz, dan grafik kemajuan bisa dihitung otomatis. `quran_surahs` adalah data tetap yang diisi saat instalasi.
- **Ziyadah, murajaah, dan tasmi'** dibedakan lewat `type`. Laporan ke wali bisa menampilkan hafalan baru dan pengulangan secara terpisah.
- `student_juz_progress` adalah ringkasan per juz yang diperbarui setiap ada setoran atau ujian, supaya dashboard dan portal wali tidak perlu menghitung ulang dari seluruh riwayat setoran.
- `tahfidz_targets` membandingkan capaian dengan target per jenjang dan tingkat, sehingga santri yang tertinggal bisa terlihat lebih awal.
- Anggota halaqah punya riwayat, jadi santri yang pindah halaqah tetap terlacak pengampunya di setiap periode.
- `pages` mengacu pada mushaf standar 15 baris. Kalau pondok memakai standar lain, cukup diatur di `settings`.

## Perizinan, Kepulangan & Kunjungan

```mermaid
erDiagram
  leave_types {
    bigint id PK
    string code UK "KELUAR | PULANG | SAKIT | KEPERLUAN"
    string name
    smallint max_hours "batas durasi, nullable"
    enum approver "musyrif | kepala_asrama | kesantrian"
    boolean requires_guardian "wajib dijemput atau dikonfirmasi wali"
    boolean is_active
  }
  homecoming_periods {
    bigint id PK
    bigint academic_year_id FK
    string name "Libur Ramadhan 1448 H"
    date depart_on
    date return_on "batas kembali"
  }
  leave_requests {
    bigint id PK
    bigint student_id FK
    bigint leave_type_id FK
    bigint homecoming_period_id FK "nullable, untuk pulang massal"
    enum requested_via "wali | santri | musyrif"
    bigint requested_by FK "users, nullable"
    text reason
    datetime planned_departure
    datetime return_due
    enum status "diajukan | disetujui | ditolak | keluar | kembali | batal"
    bigint approved_by FK "staff, nullable"
    datetime approved_at "nullable"
    string pickup_name "penjemput, nullable"
    string pickup_phone "nullable"
    datetime departed_at "dicatat di pos"
    bigint checked_out_by FK "users, nullable"
    datetime returned_at "nullable"
    bigint checked_in_by FK "users, nullable"
    text note "nullable"
  }
  visits {
    bigint id PK
    bigint student_id FK
    bigint guardian_id FK "nullable"
    string visitor_name
    string relation
    datetime started_at
    datetime ended_at "nullable"
    text items_note "titipan barang, nullable"
    bigint recorded_by FK "users"
  }
  leave_types ||--o{ leave_requests : ""
  students ||--o{ leave_requests : ""
  homecoming_periods ||--o{ leave_requests : ""
  staff ||--o{ leave_requests : "menyetujui"
  students ||--o{ visits : ""
  guardians ||--o{ visits : ""
  
```

- **Alur izin:** wali atau musyrif mengajukan, lalu disetujui oleh pihak sesuai `leave_types.approver`. Petugas pos mencatat jam keluar dan kembali. Persetujuan otomatis diarahkan ke musyrif kamar atau asrama santri lewat `dormitory_supervisors`, tanpa perlu memilih manual.
- **Terlambat kembali** tidak disimpan sebagai status. Cukup dibandingkan `returned_at` dengan `return_due`, atau santri yang belum kembali padahal sudah lewat batas. Daftar ini bisa langsung tampil di dashboard kesantrian.
- **Pulang massal** (libur semester, Ramadhan): buat satu `homecoming_periods`, lalu sistem membuat izin untuk semua santri mukim sekaligus. Petugas cukup mencatat keluar dan kembali, dan rekap santri yang belum kembali tersedia per asrama.
- **Notifikasi WA ke wali** dikirim saat izin disetujui, saat santri keluar dari pos, dan saat kembali.
- `visits` mencatat kunjungan wali (sambangan) beserta titipan barang, berguna untuk keamanan dan menjawab pertanyaan wali.

## Dokumen

Sebelumnya dokumen hanya ada di PSB (`applicant_documents`). Sekarang ada satu tabel dokumen umum untuk santri, wali, staff, dan prestasi, serta catatan surat yang dikeluarkan pondok.

```mermaid
erDiagram
  document_types {
    bigint id PK
    string code UK "KK | AKTA | IJAZAH | RAPOR | BPJS | SERTIFIKAT"
    string name
    enum owner "santri | wali | staff | prestasi"
    boolean is_required "wajib untuk santri aktif"
    boolean has_expiry "mis. kartu BPJS, paspor"
    boolean is_confidential "hanya peran tertentu yang bisa lihat"
    boolean is_active
  }
  documents {
    bigint id PK
    bigint document_type_id FK
    string documentable_type "Student, Guardian, Staff, Achievement"
    bigint documentable_id
    string number "nomor KK atau NIK, terenkripsi, nullable"
    date issued_on "nullable"
    date expires_on "nullable"
    string file_path "disk private"
    string original_name
    string mime_type
    int size_kb
    enum status "menunggu | valid | ditolak"
    bigint verified_by FK "users, nullable"
    timestamp verified_at "nullable"
    bigint uploaded_by FK "users"
    bigint replaced_by_id FK "nullable, versi lebih baru"
    bigint source_applicant_document_id FK "nullable, asal berkas PSB"
  }
  issued_letters {
    bigint id PK
    string letter_no UK "045/MAK/SKA/X/2026"
    enum type "keterangan_aktif | pindah | rekomendasi | syahadah | kartu_santri"
    bigint student_id FK
    bigint signed_by_staff_id FK
    date issued_on
    string purpose "keperluan, nullable"
    string verification_code UK "untuk QR cek keaslian"
    string file_path "PDF yang dicetak"
    bigint issued_by FK "users"
  }
  document_types ||--o{ documents : ""
  documents |o--o| documents : "digantikan"
  students ||--o{ issued_letters : ""
  staff ||--o{ issued_letters : "penandatangan"
  
```

- **Satu tabel untuk semua pemilik** lewat relasi polymorphic Laravel (`documentable`). Fitur unggah, verifikasi, dan penyimpanan cukup dibuat sekali.
- **Berkas PSB tidak perlu diunggah ulang.** `admission_requirements` kini merujuk ke `document_types`. Saat pendaftar menjadi santri, berkas yang sudah valid disalin menjadi `documents` milik santri.
- **Kelengkapan berkas** bisa dicek otomatis: jenis dokumen yang `is_required` tapi belum ada atau belum valid. Hasilnya bisa tampil sebagai widget di dashboard dan sebagai pengingat di portal wali.
- **Riwayat versi:** dokumen yang diperbarui (misalnya KK baru) tidak menimpa yang lama. Dokumen lama ditandai lewat `replaced_by_id`.
- **Data pribadi:** nomor KK dan NIK disimpan dengan cast `encrypted` Laravel. File tersimpan di disk private dan dokumen `is_confidential` hanya bisa dibuka peran yang punya permission khusus. Ini sejalan dengan kewajiban perlindungan data di UU PDP.
- **Surat keluar** (keterangan aktif, rekomendasi, pindah, syahadah) diberi nomor otomatis dan kode verifikasi. Pihak luar bisa memindai QR untuk mengecek keasliannya.

## Prestasi Santri

```mermaid
erDiagram
  achievements {
    bigint id PK
    bigint academic_year_id FK
    enum category "tahfidz | akademik | keagamaan | olahraga | seni | organisasi | lainnya"
    string title "Juara 1 MTQ Tahfidz 10 Juz"
    string event_name "MTQ Tingkat Kabupaten 2026"
    string organizer "nullable"
    enum level "pondok | kecamatan | kabupaten | provinsi | nasional | internasional"
    enum kind "individu | beregu"
    date achieved_on
    bigint recorded_by FK "users"
    bigint verified_by FK "users, nullable"
    boolean is_public "boleh tampil di website"
  }
  achievement_student {
    bigint achievement_id PK,FK
    bigint student_id PK,FK
    string rank "Juara 1, Harapan 2, Finalis, Peserta"
    string role "ketua tim, nullable"
  }
  achievements ||--o{ achievement_student : ""
  students ||--o{ achievement_student : ""
  achievements ||--o{ documents : "sertifikat dan foto"
  achievements |o--o| posts : "berita prestasi"
  
```

- **Prestasi tercatat di profil santri,** tidak hanya sebagai berita di website. Rekap per santri, per kategori, dan per tingkat bisa ditampilkan di rapor, portal wali, dan surat rekomendasi.
- **Prestasi beregu** cukup dicatat sekali lalu dihubungkan ke semua santri anggotanya lewat `achievement_student`, masing-masing dengan peringkat dan perannya.
- **Sertifikat dan foto** disimpan di tabel `documents` dengan pemilik `Achievement`.
- **Publikasi ke website:** prestasi dengan `is_public` bisa diterbitkan menjadi berita lewat `posts.achievement_id`. Data prestasi tetap satu sumber, dan widget prestasi di dashboard serta halaman publik mengambil dari tabel yang sama.
- Kelulusan ujian khataman di modul tahfidz bisa otomatis diusulkan sebagai prestasi kategori tahfidz tingkat pondok.
- Nanti, modul kedisiplinan bisa memakai prestasi ini sebagai poin positif yang mengimbangi poin pelanggaran.

## Penerimaan Santri Baru (PSB)

```mermaid
erDiagram
  admission_waves {
    bigint id PK
    bigint academic_year_id FK "tahun ajaran tujuan"
    string name "Gelombang 1"
    date opens_on
    date closes_on
    int quota "nullable"
    boolean is_open
  }
  admission_tracks {
    bigint id PK
    string code UK "REGULER | PRESTASI | TAHFIDZ | YATIM"
    string name
    bigint scholarship_id FK "nullable, potongan otomatis"
    text terms "syarat jalur, nullable"
    boolean is_active
  }
  admission_requirements {
    bigint id PK
    bigint unit_id FK "nullable = semua jenjang"
    bigint document_type_id FK "dipindah ke dokumen santri saat diterima"
    string name "Kartu Keluarga, Akta, Rapor"
    boolean is_required
    tinyint sort_order
    bigint unit_key "generated stored: COALESCE(unit_id, 0), ikut unique"
  }
  applicants {
    bigint id PK
    bigint admission_wave_id FK
    bigint admission_track_id FK "jalur pendaftaran"
    bigint unit_id FK "jenjang tujuan"
    string registration_no UK "PSB-2027-0001"
    string access_token_hash "untuk cek status tanpa akun"
    string name
    string nisn "nullable"
    enum gender "L | P"
    string birth_place
    date birth_date
    string previous_school
    string guardian_name
    string guardian_phone
    string email "nullable"
    enum status "draft | diajukan | diverifikasi | diterima | cadangan | ditolak"
    bigint reviewed_by FK "users, nullable"
    timestamp reviewed_at "nullable"
    text review_note "nullable"
    timestamp consented_at "nullable, bukti persetujuan data pribadi (UU PDP), diisi server"
    timestamp deleted_at "soft delete"
  }
  applicant_documents {
    bigint id PK
    bigint applicant_id FK
    bigint admission_requirement_id FK
    string file_path "disk private"
    string original_name "nama file asli"
    string mime_type "dibaca dari isi file"
    int size_kb
    enum status "menunggu | valid | ditolak"
    text note "nullable"
    bigint verified_by FK "users, nullable"
    timestamp verified_at "nullable"
  }
  admission_waves ||--o{ applicants : ""
  admission_tracks ||--o{ applicants : ""
  applicants ||--o{ applicant_documents : ""
  admission_requirements ||--o{ applicant_documents : ""
  
```

- Pendaftar tidak perlu akun di tabel `users`. Mereka cek status dan unggah ulang berkas dengan nomor pendaftaran + token (disimpan dalam bentuk hash).
- Saat status menjadi *diterima*, sebuah Action membuat baris `students` dengan `applicant_id` terisi dan menyalin data wali ke `guardians`.
- Berkas disimpan di disk `private` (bukan `public`) dan diakses lewat route yang dicek permission-nya, karena berisi KK dan akta.
- **Token akses:** 12 karakter dari alfabet tanpa huruf/angka yang mirip (`A-H J-N P-Z 2-9`), ditampilkan `XXXX-XXXX-XXXX`; yang disimpan hanya hash dari token ternormalisasi (huruf besar, tanpa spasi/tanda hubung). Token bisa diatur ulang panitia.
- **Kuota gelombang** dihitung dari pendaftar semua jenjang, tanpa status `draft` dan tanpa pendaftar terhapus (soft delete).
- **FK `applicant_id`** di `students`, `fee_overrides`, dan `invoices` kini dibuat (`restrictOnDelete`) setelah tabel `applicants` ada.

### Biaya PSB

Biaya PSB memakai mekanisme yang sama dengan SPP: jenis biaya, tarif yang berlaku per tahun ajaran, potongan, dan tagihan gabungan atau terpisah. Bedanya, tagihan dibuat untuk pendaftar (`applicant_id`) karena mereka belum menjadi santri.

| Tahap | Yang terjadi | Tagihan |
| --- | --- | --- |
| Daftar online | Status *diajukan* | Biaya formulir dibuat otomatis dari jenis biaya `saat_daftar`. Tarif bisa dibedakan per gelombang dan jenjang. |
| Formulir lunas | Unggah berkas dan jadwal tes dibuka | Lunas ditandai lewat pembayaran yang dicatat bendahara (`RecordPayment`), bukan oleh panitia. |
| Diterima | Status *diterima* | Uang pangkal, seragam, kitab dibuat dari jenis biaya `saat_diterima`. Potongan jalur diterapkan otomatis, dan pembayaran bisa dicicil. |
| Daftar ulang | Data santri dibuat | Tagihan dan keringanan yang belum selesai ikut pindah ke santri. Tagihan bulanan dimulai bulan berikutnya. |

- **Tarif per gelombang:** `fee_rates.admission_wave_id` memungkinkan gelombang 1 lebih murah dari gelombang 2. Kalau kosong, tarif berlaku untuk semua gelombang. Kolom biaya di `admission_waves` dihapus agar semua tarif ada di satu tempat.
- **Berubah tiap tahun atau tetap:** sama seperti SPP, lewat `effective_from_year_id`.
- **Jalur pendaftaran:** `admission_tracks` seperti Tahfidz atau Yatim bisa dihubungkan ke program beasiswa. Saat pendaftar jalur itu diterima, keringanannya dibuat otomatis, misalnya uang pangkal gratis atau SPP potong 50%.
- **Keringanan khusus:** panitia bisa memberi potongan per pendaftar (misalnya diskon saudara kandung) lewat `fee_overrides.applicant_id`, termasuk potongan SPP yang mulai berlaku setelah jadi santri.
- **Pindah ke santri:** saat santri dibuat, `student_id` diisi pada tagihan dan keringanan milik pendaftar. `applicant_id` tetap disimpan, jadi tunggakan uang pangkal terlihat di profil santri dan asalnya tetap terlacak.
- **Pemisahan `billing_group`:** satu kelompok tagihan hanya boleh berisi jenis biaya dari kelas frekuensi yang sama: `saat_daftar`, `saat_diterima`, atau lainnya (`bulanan`, `tahunan`, `manual`). Tagihan formulir tidak menghalangi tagihan saat diterima, dan setelah konversi tidak bertabrakan dengan generator bulanan.
- Syarat minimal pembayaran untuk daftar ulang (misalnya lunas atau minimal 50%) diatur di `settings` sebagai `psb.min_paid_percent`.

## Keuangan & Tagihan

SPP, asrama, makan, dan biaya lain diperlakukan sebagai *jenis biaya*. Apakah semuanya digabung dalam satu tagihan atau dipisah ditentukan oleh `fee_types.billing_group`, dan bisa diatur dari panel admin tanpa ubah kode.

```mermaid
erDiagram
  fee_types {
    bigint id PK
    string code UK "SPP | ASRAMA | MAKAN | LAUNDRY"
    string name
    enum frequency "bulanan | tahunan | saat_daftar | saat_diterima | manual"
    enum applies_to "semua | mukim | nonmukim"
    string billing_group "BULANAN, ASRAMA, ... sama = digabung"
    smallint priority "urutan pelunasan cicilan"
    boolean is_active
  }
  fee_rates {
    bigint id PK
    bigint fee_type_id FK
    bigint effective_from_year_id FK "berlaku mulai tahun ajaran ini"
    bigint unit_id FK
    bigint cohort_id FK "nullable = default jenjang"
    bigint dormitory_id FK "nullable = semua asrama"
    bigint admission_wave_id FK "nullable, tarif khusus gelombang PSB"
    bigint amount "rupiah"
  }
  scholarships {
    bigint id PK
    string name "Beasiswa Tahfidz, Yatim, Anak Ustadz"
    enum mode "nominal | potongan | persen"
    bigint default_value "nominal/potongan rupiah atau persen"
    string sponsor "donatur atau yayasan, nullable"
    boolean is_active
  }
  fee_overrides {
    bigint id PK
    bigint student_id FK "nullable"
    bigint applicant_id FK "nullable, keringanan saat PSB"
    bigint fee_type_id FK
    bigint scholarship_id FK "nullable = keringanan non-beasiswa"
    enum mode "nominal | potongan | persen"
    bigint value "nominal 0 = gratis"
    string reason
    date valid_from
    date valid_until "nullable = sampai lulus"
    bigint approved_by FK "users"
  }
  invoices {
    bigint id PK
    string invoice_no UK "INV-202610-00123"
    bigint student_id FK "nullable sebelum diterima"
    bigint applicant_id FK "nullable, terisi untuk tagihan PSB"
    bigint academic_year_id FK
    date period "tanggal 1 bulan tagihan"
    string billing_group "salinan dari fee_types"
    bigint total "jumlah semua item"
    bigint paid_total "cache jumlah terbayar"
    enum status "belum | sebagian | lunas | batal"
    tinyint active_marker "generated stored: 1 bila status bukan batal, ikut unique"
    date due_on
    bigint generated_by FK "users, nullable jika via scheduler"
  }
  invoice_items {
    bigint id PK
    bigint invoice_id FK
    bigint fee_type_id FK
    string description "SPP Oktober 2026"
    bigint base_amount "tarif normal"
    bigint discount_amount "beasiswa atau keringanan"
    bigint amount "yang harus dibayar"
    enum rate_source "angkatan | jenjang | manual"
    bigint fee_override_id FK "nullable"
    bigint paid_amount "hasil alokasi pembayaran"
  }
  payment_allocations {
    bigint id PK
    bigint payment_id FK
    bigint invoice_item_id FK
    bigint amount
  }
  payments {
    bigint id PK
    string receipt_no UK "nomor kuitansi"
    bigint invoice_id FK
    bigint amount "rupiah"
    date paid_on
    enum method "tunai | transfer | gateway"
    string proof_path "bukti transfer, nullable"
    string gateway_ref "id transaksi Midtrans/Xendit, nullable"
    bigint received_by FK "users, nullable untuk callback gateway"
    text note "nullable"
    enum status "diterima | batal (keputusan F007)"
    timestamp cancelled_at "nullable"
    bigint cancelled_by FK "users, nullable"
    string cancel_reason "nullable"
  }
  fee_types ||--o{ fee_rates : ""
  admission_waves ||--o{ fee_rates : ""
  applicants ||--o{ invoices : ""
  applicants ||--o{ fee_overrides : ""
  units ||--o{ fee_rates : ""
  cohorts ||--o{ fee_rates : ""
  dormitories ||--o{ fee_rates : ""
  students ||--o{ fee_overrides : ""
  fee_types ||--o{ fee_overrides : ""
  scholarships ||--o{ fee_overrides : ""
  fee_overrides ||--o{ invoice_items : ""
  students ||--o{ invoices : ""
  invoices ||--o{ invoice_items : ""
  fee_types ||--o{ invoice_items : ""
  invoices ||--o{ payments : ""
  payments ||--o{ payment_allocations : ""
  invoice_items ||--o{ payment_allocations : ""
  
```

| Mode | Pengaturan `billing_group` | Hasil per santri mukim per bulan |
| --- | --- | --- |
| Digabung | SPP, ASRAMA, MAKAN semuanya `BULANAN` | 1 tagihan berisi 3 item |
| Dipisah | SPP → `SPP`, ASRAMA dan MAKAN → `ASRAMA` | 2 tagihan: SPP dan Asrama |
| Dipisah penuh | Setiap jenis punya grup sendiri | 3 tagihan terpisah |

- Santri nonmukim otomatis tidak ditagih biaya dengan `applies_to = mukim`. Status mukim dibaca dari `room_assignments` yang aktif.
- `fee_rates.dormitory_id` memungkinkan tarif asrama berbeda per gedung, misalnya asrama baru lebih mahal. Kalau tidak dipakai, biarkan kosong.
- **Tarif berubah atau tetap:** `fee_rates` berlaku mulai tahun ajaran tertentu sampai ada baris yang lebih baru. Kalau SPP tidak berubah, cukup satu baris dan tidak perlu disalin tiap tahun. Kalau naik, tambahkan baris baru dengan tahun ajaran berikutnya, dan tarif lama tetap tersimpan sebagai riwayat.
- **Tarif dikunci per angkatan:** isi `cohort_id`. Contoh: angkatan 2025 tetap Rp500.000 sampai lulus, sementara angkatan 2026 membayar Rp600.000. Tarif angkatan selalu menang atas default jenjang.
- **Beasiswa dan keringanan:** `fee_overrides` per santri, per jenis biaya, dengan masa berlaku. Tiga mode: *nominal* (bayar Rp250.000, atau Rp0 untuk gratis), *potongan* (dikurangi Rp100.000), dan *persen* (dikurangi 50%). Mode potongan dan persen ikut menyesuaikan saat tarif normal naik.
- `scholarships` mengelompokkan program beasiswa, sehingga bendahara bisa melaporkan total subsidi per program atau per donatur. Keringanan yang bukan beasiswa (misalnya kesepakatan dengan wali) cukup tanpa `scholarship_id`.
- `invoice_items` menyimpan tarif normal, potongan, dan nominal akhir, jadi besarnya beasiswa yang diberikan tiap bulan bisa dihitung langsung dari tagihan.
- Pembayaran cicilan pada tagihan gabungan dialokasikan ke item sesuai `fee_types.priority` (misalnya makan dulu, lalu asrama, lalu SPP) dan dicatat di `payment_allocations`. Laporan bendahara per jenis biaya jadi akurat.
- Pembayaran tidak dihapus. Pembayaran salah input dibatalkan (`payments.status = batal`, beserta `cancelled_at`, `cancelled_by`, `cancel_reason`); baris, nomor kuitansi, alokasi, dan bukti transfer tetap ada, sedangkan `paid_amount` item dan `paid_total` tagihan dikurangi kembali. Alokasi berlaku selama pembayarannya `diterima`; pembayaran lain tidak dialokasikan ulang (keputusan F007).
- Nomor kuitansi `KW-YYYYMM-NNNNN`, urut per bulan pencatatan untuk semua jenjang; pembayaran `batal` tetap memakai nomornya. Bukti transfer disimpan di disk `private`.
- Biaya `saat_daftar` dan `saat_diterima` (formulir, uang pangkal, seragam, kitab) tidak dibuat oleh generator bulanan, tetapi oleh alur PSB. Lihat bagian [PSB](#psb).
- Semua nominal uang memakai `bigint` rupiah tanpa desimal, dan nominal item adalah snapshot sehingga perubahan tarif tidak mengubah tagihan lama.

## Konten Publik (CMS)

```mermaid
erDiagram
  categories {
    bigint id PK
    enum type "post | album"
    string name "Fasilitas, Olahraga, Tahfidz"
    string slug
  }
  posts {
    bigint id PK
    bigint category_id FK "nullable"
    bigint unit_id FK "nullable = umum"
    bigint author_id FK "users"
    enum type "kegiatan | prestasi | berita"
    string title
    string slug UK
    string excerpt "nullable"
    longtext body
    string cover_path "nullable"
    string youtube_url "nullable"
    bigint achievement_id FK "nullable, untuk berita prestasi"
    json meta "data tambahan, nullable"
    enum status "draft | terbit"
    timestamp published_at "nullable"
  }
  albums {
    bigint id PK
    bigint category_id FK
    string title
    string slug UK
    text description "nullable"
  }
  photos {
    bigint id PK
    bigint album_id FK
    string path
    string caption "nullable"
    smallint sort_order
  }
  videos {
    bigint id PK
    string title
    string youtube_id
    text description "nullable"
    boolean is_featured
    smallint sort_order
  }
  pages {
    bigint id PK
    string slug UK "profil | visi-misi | kontak"
    string title
    longtext body
  }
  settings {
    string key PK "school.name, school.logo, psb.contact"
    json value
  }
  categories ||--o{ posts : ""
  categories ||--o{ albums : ""
  albums ||--o{ photos : ""
  
```

- Kegiatan, prestasi, dan berita disatukan di `posts` dengan kolom `type`. Berita prestasi merujuk ke `achievements` lewat `achievement_id`, jadi data tingkat, peringkat, dan santri yang terlibat tidak ditulis ulang.
- `settings` menyimpan profil sekolah sebagai key-value, sehingga instalasi baru cukup mengisi lewat panel tanpa ubah kode.

## Pengguna & RBAC

```mermaid
erDiagram
  users {
    bigint id PK
    string name
    string email UK
    string password
    boolean is_active
    timestamp last_login_at "nullable"
  }
  unit_user {
    bigint user_id PK,FK
    bigint unit_id PK,FK
  }
  roles {
    bigint id PK
    string name "super-admin | bendahara | admin-konten | admin-sekolah | admin-psb | kesantrian | musyrif | ustadz-tahfidz | petugas-pos"
    string guard_name
  }
  permissions {
    bigint id PK
    string name "view-dashboard, view-finance-metrics, ..."
    string guard_name
  }
  model_has_roles {
    bigint role_id PK,FK
    string model_type PK
    bigint model_id PK
  }
  model_has_permissions {
    bigint permission_id PK,FK
    string model_type PK
    bigint model_id PK
  }
  role_has_permissions {
    bigint permission_id PK,FK
    bigint role_id PK,FK
  }
  users ||--o{ unit_user : ""
  units ||--o{ unit_user : ""
  users ||--o{ model_has_roles : "model_id"
  roles ||--o{ model_has_roles : ""
  users ||--o{ model_has_permissions : "model_id"
  permissions ||--o{ model_has_permissions : ""
  roles ||--o{ role_has_permissions : ""
  permissions ||--o{ role_has_permissions : ""
  
```

- Tabel `roles`, `permissions`, dan tiga pivotnya dibuat oleh migration Spatie Permission. Tidak perlu ditulis sendiri.
- `unit_user` mengatur cakupan jenjang. Contohnya admin-sekolah yang hanya mengurus SLTP. Jika user tidak punya baris di sini, artinya ia boleh melihat semua jenjang. Diterapkan lewat global scope pada model `Student`, `Invoice`, dan `Applicant`.
- Permission menentukan *modul apa* yang boleh diakses, sedangkan `unit_user` menentukan *data jenjang mana*. Dua hal ini sengaja dipisah.

## Aturan tarif & constraint penting

### Urutan penentuan nominal per jenis biaya

Dijalankan oleh `GenerateMonthlyInvoices` lewat scheduler setiap tanggal 1. Untuk setiap jenis biaya bulanan yang berlaku bagi santri, tarif normal dicari dari yang paling spesifik: angkatan + asrama, angkatan, jenjang + asrama, lalu jenjang. Dari kandidat itu dipilih baris dengan `effective_from_year` terbaru yang sudah berlaku.

Setelah itu, override yang aktif di bulan tagihan diterapkan. Santri yang dibebaskan tetap mendapat item Rp0 beserta alasannya.

```php
foreach (students aktif as $s) {
  $dorm = $s->activeRoom?->dormitory_id;   // null = nonmukim

  foreach (feeTypes bulanan berlaku untuk $s as $fee) {
    $base = rate($fee, $s, $dorm, $tahunAjaran); // tarif terbaru
    $ovr  = override($s, $fee, $bulan);          // beasiswa aktif

    if ($base === null && $ovr?->mode !== 'nominal') {
      log('tarif belum diatur'); continue;
    }

    $amount = match ($ovr?->mode) {
      'nominal'  => min($ovr->value, $base ?? $ovr->value), // tidak pernah di atas tarif
      'potongan' => max(0, $base - $ovr->value),
      'persen'   => $base - intdiv($base * $ovr->value, 100),
      default    => $base,
    };

    $inv = Invoice::firstOrCreate([
      'student_id'    => $s->id,
      'period'        => $bulan,
      'billing_group' => $fee->billing_group,
    ]);
    $inv->items()->firstOrCreate(
      ['fee_type_id' => $fee->id],
      ['base_amount' => $base, 'amount' => $amount, ...]
    );
  }
  // hitung ulang invoices.total
}
```

| Tabel | Constraint / index | Alasan |
| --- | --- | --- |
| `invoices` | generated column stored `active_marker = CASE WHEN status <> 'batal' THEN 1 END`; unique (`student_id`, `period`, `billing_group`, `active_marker`) | Generator aman dijalankan ulang tanpa membuat tagihan ganda. Hanya satu tagihan aktif per kombinasi; tagihan `batal` bernilai NULL sehingga boleh banyak dan tidak menghalangi tagihan pengganti (keputusan F006) |
| `invoices` | check: `student_id` atau `applicant_id` wajib terisi; unique (`applicant_id`, `billing_group`, `active_marker`) | Tagihan selalu punya pemilik, dan biaya PSB tidak dobel selama tagihannya aktif |
| `invoice_items` | unique (`invoice_id`, `fee_type_id`) | Satu jenis biaya hanya muncul sekali per tagihan |
| `payments` | unique `receipt_no`; index (`invoice_id`, `status`), (`status`, `paid_on`); semua FK `restrictOnDelete` | Nomor kuitansi tidak ganda; daftar pembayaran per tagihan dan laporan kas harian (F008) |
| `payment_allocations` | unique (`payment_id`, `invoice_item_id`); FK `restrictOnDelete` | Satu baris alokasi per item per pembayaran |
| `fee_overrides` | index (`student_id`, `fee_type_id`, `valid_from`) | Cari override yang berlaku di bulan tagihan |
| `invoices` | index (`status`, `period`) | Widget tunggakan dan rasio koleksi di dashboard |
| `fee_rates` | unique (`fee_type_id`, `effective_from_year_id`, `unit_id`, `cohort_key`, `dormitory_key`, `wave_key`) | Satu tarif per kombinasi. Lihat catatan NULL di bawah |
| `enrollments` | unique (`student_id`, `academic_year_id`) | Satu kelas per santri per tahun ajaran |
| `students` | unique `nis`; index (`unit_id`, `status`) | Hitungan santri aktif per jenjang |
| `cohorts` | unique (`unit_id`, `entry_year`) | Tidak ada angkatan ganda dalam satu jenjang |
| `classrooms` | unique (`academic_year_id`, `unit_id`, `name`) | 1A hanya ada satu per jenjang per tahun ajaran |
| `room_assignments` | index (`student_id`, `end_on`), (`room_id`, `end_on`) | Cari kamar aktif santri dan hitung sisa kapasitas kamar |
| `room_assignments` | generated column stored `active_marker = CASE WHEN end_on IS NULL THEN 1 END`; unique (`student_id`, `active_marker`) | Satu penempatan aktif per santri. Baris riwayat bernilai NULL sehingga tidak saling bentrok (pola sama dengan catatan NULL di bawah) |
| `rooms` | unique (`dormitory_id`, `name`) | Nama kamar unik per asrama |
| `memorization_records` | index (`student_id`, `recorded_on`), (`halaqah_id`, `recorded_on`) | Riwayat setoran per santri dan rekap harian per halaqah |
| `halaqah_members` | index (`student_id`, `left_on`) | Cari halaqah aktif santri |
| `leave_requests` | index (`status`, `return_due`), (`student_id`, `status`) | Daftar santri yang sedang di luar dan yang terlambat kembali |
| `leave_requests` | unique (`student_id`, `homecoming_period_id`) | Satu izin pulang massal per santri per periode |
| `documents` | index (`documentable_type`, `documentable_id`, `document_type_id`), (`expires_on`) | Cek kelengkapan berkas dan dokumen yang akan kedaluwarsa |
| `achievements` | index (`academic_year_id`, `category`, `level`) | Rekap prestasi per tahun, kategori, dan tingkat |
| `applicant_documents` | unique (`applicant_id`, `admission_requirement_id`) | Unggah ulang menimpa berkas lama, tidak menumpuk |
| `admission_waves` | unique (`academic_year_id`, `name`) | Nama gelombang tidak ganda dalam satu tahun ajaran (keputusan F009). Rentang tanggal gelombang dalam satu tahun ajaran juga tidak beririsan, dijaga Action `SaveAdmissionWave` |
| `admission_requirements` | generated column stored `unit_key = COALESCE(unit_id, 0)`; unique (`document_type_id`, `unit_key`) | Satu syarat per jenis dokumen per jenjang (keputusan F009). Syarat semua jenjang dan syarat per jenjang untuk jenis yang sama tidak boleh berdampingan (dijaga Action). Keterbatasan: satu jenis dokumen hanya bisa jadi satu syarat per jenjang, jadi dua syarat bertipe sama (mis. rapor semester 1 dan 2) belum bisa dimodelkan; F012 memetakan berkas valid ke `documents` dengan asumsi satu jenis ke satu syarat. Melonggarkannya nanti adalah perubahan skema |
| `applicants` | index (`admission_wave_id`, `status`), (`created_at`) | Breakdown status dan pendaftar 7 hari terakhir |

### Catatan NULL pada unique key

MySQL dan Postgres menganggap setiap `NULL` berbeda, jadi unique key biasa di `fee_rates` tetap membolehkan dua tarif default jenjang untuk jenis biaya dan tahun yang sama. Solusinya, tambahkan generated column `cohort_key = COALESCE(cohort_id, 0)` `dormitory_key = COALESCE(dormitory_id, 0)`, dan `wave_key = COALESCE(admission_wave_id, 0)`, lalu pasang unique di kolom itu. Cara ini jalan di MySQL, MariaDB, Postgres, dan SQLite.

### Tabel bawaan yang tidak digambar

- `sessions`, `cache`, `password_reset_tokens`
- `jobs`, `job_batches`, `failed_jobs` (queue driver database)
- `notifications` (notifikasi in-app Filament)
- `activity_log` dari Spatie Activitylog untuk audit `invoices`, `payments`, dan perubahan override

### Konvensi umum

- Semua tabel memakai `created_at` dan `updated_at` (tidak ditulis di diagram).
- Soft delete hanya untuk `students`, `applicants`, dan `posts`. Data keuangan tidak dihapus, cukup diberi status *batal*.
- Foreign key ke data keuangan memakai `restrictOnDelete`, bukan cascade.
