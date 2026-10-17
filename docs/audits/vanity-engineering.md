# Vanity Engineering Review — Pondok

**Tanggal**: 17 Oktober 2026
**Auditor**: Claude Code (skill `vanity-engineering-review`)
**Repositori**: `pondok/pondok`
**Cakupan**: Audit dua putaran pada aplikasi Laravel 13 + Filament 5 untuk pondok pesantren Indonesia.

Dokumen ini sengaja ditulis **dua lapis**:

- **Bagian A** — ringkasan untuk orang awam (operator sekolah, reviewer non-teknis).
- **Bagian B** — detail teknis untuk developer/Claude.

---

# Bagian A — Ringkasan Bahasa Sederhana

## Apa yang diperiksa?

Saya memeriksa apakah kode Pondok mengandung *"vanity engineering"* — yaitu kerumitan yang dibuat bukan karena dibutuhkan pengguna, tetapi karena pembuatnya ingin terlihat pintar atau ingin belajar sesuatu. Contoh kerumitan yang tidak perlu: memecah aplikasi jadi banyak server padahal satu server cukup, membuat sistem plugin padahal tidak ada yang pakai, atau menulis 500 baris untuk hal yang cukup 50 baris.

## Hasilnya: Bagus

**Kode Pondok sehat.** Kerumitannya sebanding dengan kebutuhannya — tidak berlebihan.

Skala kerumitan 1-10 (1 = terlalu sederhana, 10 = terlalu rumit), Pondok berada di **3**. Itu posisi yang pas untuk aplikasi sebesar ini (keuangan + akademik + PSB + dokumen + konten).

## Satu hal yang perlu dicatat

Ada satu area yang **sedikit** lebih banyak dibanding yang lain: bagian kode untuk **konten** (berita, galeri, halaman, video) punya 45 file kecil, sedangkan bagian **keuangan** (yang aturannya jauh lebih rumit) hanya 21 file.

Saya awalnya curiga sebagian dari 45 file itu sudah tidak terpakai ("kode mati"). **Ternyata tidak** — saya periksa satu per satu, semuanya masih dipakai dan berfungsi. Jadi ini bukan masalah, hanya perlu dijelaskan di dokumentasi supaya orang yang baru baca tidak bingung.

## Risiko utama yang perlu diketahui

Pondok dirancang untuk mendukung **banyak jenjang sekolah sekaligus** (misalnya satu yayasan punya MTW dan TSN yang datanya dipisah). Ini keputusan yang bagus untuk **open source**, karena setiap pesantren yang menginstall bisa punya kebutuhan berbeda.

**Risikonya**: untuk pesantren kecil yang hanya punya satu jenjang, fitur pemisahan ini terasa berlebihan. Tapi itu **bukan masalah** — biaya menjalankannya kecil, dan manfaatnya besar untuk pesantren yang punya banyak jenjang.

## Kesimpulan untuk orang awam

| Pertanyaan | Jawaban |
|---|---|
| Apakah kodenya berlebihan? | Tidak |
| Apakah ada yang harus dihapus? | Tidak ada |
| Apakah aman ditunjukkan ke orang lain? | Ya |
| Berapa biaya perawatan yang "terbuang" karena kerumitan? | Sekitar 2-4 jam per bulan (sangat kecil) |
| Apa yang perlu dilakukan? | Dokumentasi penjelasan + risikonya (dokumen ini) |

---

# Bagian B — Detail Teknis

## Phase 1 — Requirement Anchor

### Siapa penggunanya?

| Aktor | Kebutuhan Inti |
|---|---|
| Operator sekolah (bendahara, admin) | Input tagihan, catat pembayaran, cetak kuitansi, ekspor laporan |
| Staf akademik | Kelola data siswa, rombel, kenaikan kelas, rapor |
| Musyrif asrama | Penempatan kamar, mutasi siswa |
| Panitia PSB | Verifikasi pendaftar, seleksi, daftar ulang, konversi ke siswa |
| Wali siswa (publik) | Lihat berita, brosur PSB, cek status pendaftaran (login ditunda ke F022) |
| Tim rilis (operator cPanel) | Install di shared hosting tanpa proses IT |

### Apa yang harus dilakukan (5 cerita teratas)?

1. Catat pembayaran SPP → alokasi otomatis ke item tagihan sesuai prioritas → cetak kuitansi bernomor.
2. Generate tagihan bulanan idempotent per (santri, periode, billing_group) dari tabel tarif + beasiswa.
3. Alur PSB lengkap: form publik → verifikasi → seleksi → daftar ulang → konversi menjadi siswa.
4. Portal publik: berita, galeri, halaman statis, formulir PSB, verifikasi QR surat.
5. Rilis paket zip yang jalan di cPanel PHP 8.3 + MySQL dengan satu cron `schedule:run`.

### Skala aktual

- **User konkuren**: 1-5 admin per sekolah (target pasar: pondok kecil-menengah, satu yayasan).
- **Data**: 200-2000 siswa per sekolah, ~12 periode keuangan/tahun, ~1 MB aset terkompresi.
- **Throughput**: < 10 request/detik pada puncak (PSB pembukaan gelombang).
- **Constraint nyata**: shared hosting cPanel = `proc_open`/`symlink` sering dimatikan, upload 2-8 MB, satu cron, no SSH.

### Ukuran tim

- Solo developer + AI pair (Claude Code) + reviewer berkala. Bukan tim 5 orang.

### Verdict anchor

Aplikasi ini **harus** sederhana. Yang membuat Pondok terlihat "besar" adalah **cakupan domain** (keuangan + akademik + PSB + dokumen + konten) — bukan **kedalaman teknis** per domain. Kesalahan yang harus dihindari: menyamakan "banyak modul" dengan "butuh arsitektur kompleks".

---

## Phase 2 — Detection Scan (Putaran 1 + 2 digabung)

### Statistik codebase

| Lokasi | File PHP | Baris | Catatan |
|---|---:|---:|---|
| `app/Actions/` | 143 | 13.634 | Pola utama: 1 Action per aturan bisnis |
| `app/Filament/` | 123 | — | Resources, Schemas, Tables, Pages, Widgets |
| `app/Models/` | 40 | — | 37 model + 2 Concerns + 1 Scope |
| `app/Policies/` | 32 | — | 1 per model |
| `app/Services/` | 2 | — | QrCodeRenderer, SystemProbe |
| `app/Livewire/` | 2 | — | RegistrationForm, ApplicationStatus |
| `app/Http/` | 23 | — | Controller + Middleware |

Distribusi Actions per modul: Keuangan 21, Konten 45, Psb 26, Dokumen 11, Akademik 9, Prestasi 8, Core 7, Asrama 5, Dashboard 3, Release 3.

---

## Ringkasan Asesmen

Pondok adalah aplikasi CRUD spesifik-domain yang dibangun dengan disiplin. Stack-nya pragmatik (Laravel + Filament + Spatie), bukan fashion (bukan microservices, bukan K8s, bukan event-driven untuk hal yang seharusnya request-response). Yang ditemukan secara umum: **engineering yang terukur, bukan engineering yang pamer**.

**Requirement-to-Complexity Ratio (RCR): 3/10.**
Sweet spot. Untuk domain sebesar ini, skor 1-2 berarti under-engineered (tidak ada audit trail di keuangan → pesantren tidak bisa audit), skor 6+ berarti over-engineered. Pondok ada di tempat yang benar.

---

## Temuan (7, diurutkan dari yang paling serius)

### 1. 45 Actions di `app/Actions/Konten/` — fragmentasi konten CMS [V1 — Drag]

- **Apa**: 45 Action untuk modul konten (Post, Album, Video, Category, StaticPage, halaman blok, footer widget, navigasi, profil, sanitasi, dll). Modul ini **6x lebih banyak** dari modul Keuangan (21 Action) padahal fitur keuangannya lebih kompleks secara aturan.
- **Di mana**: `app/Actions/Konten/`, subfolder `Concerns/`.
- **Bukti fragmentasi**:
  - 7 Action sangat kecil (16-30 baris): `DeletePost` (16 baris, hanya `$post->delete()`), `RestorePost` (16), `DeleteVideo` (16), `DeletePhoto` (19), `DeleteCategory` (23), `DeleteAlbum` (30), `FindPublicPost` (20).
  - `FormatPhoneForDisplay` (65 baris) memformat nomor telepon Indonesia + buat URL WhatsApp — bisa berupa helper.
  - `ParseYoutubeId` (85 baris) parse URL YouTube jadi 11-char ID — bisa berupa helper statis.
  - `SummarizeHtml` (28 baris) strip tag HTML untuk excerpt — bisa berupa helper.
  - `GenerateSlug` (89 baris) + `ValidatesContentInput` (trait) + `ValidatesPublicUrls` (trait) = 3 file untuk satu kebutuhan (slug unik).
- **Status konsumen (verifikasi faktual, 17 Okt 2026)**:
  - `DeletePost`/`RestorePost` → dipakai `PostsTable.php` (baris 104, 110).
  - `DeleteVideo` → dipakai `VideoResource.php` (baris 132).
  - `DeletePhoto` → dipakai `PhotosRelationManager.php` (baris 99).
  - `DeleteCategory` → dipakai `CategoryResource.php` (baris 128).
  - `DeleteAlbum` → dipakai `AlbumResource.php` (baris 138).
  - `FindPublicPost` → dipakai `ShowPost.php` (controller portal, baris 13).
  - **Kesimpulan: tidak ada kode mati. Semua masih dipakai.**
- **Mengapa mendekati vanity**: Modul Konten menganut **filosofi "1 Action per use case"** dengan konsistensi tinggi. Untuk delete/restore yang isinya satu baris, ini adalah indirection tanpa polymorphic benefit.
- **Mengapa bukan V2 (structural)**: Delete/restore via Action **memang idiom Filament modern** untuk konsistensi dengan alur yang lebih kompleks (`SavePost` 100+ baris, `DeleteApplicant` 30+ baris). Inkonsistensi (kadang Action, kadang inline) akan lebih buruk dari fragmentasi. Plus, Action pattern dipakai konsisten di SEMUA modul — bukan outlier.
- **Keputusan yang diambil (17 Okt 2026)**: **Biarkan.** Alasan: project open source punya kebutuhan instalasi yang berbeda-beda, konsistensi lebih berharga daripada penghematan 7 file kecil, dan semua masih relevan dengan fungsinya.
- **Rekomendasi tindak lanjut**: Dokumentasikan pola Action ini di `docs/` agar developer baru tidak bingung. Jangan refactor.

### 2. 32 Policy class — satu per model [V1 — Drag, tapi terjustifikasi]

- **Apa**: Setiap model Eloquent punya `*Policy.php` (Student, Invoice, Payment, Post, Album, dll).
- **Di mana**: `app/Policies/`, 32 file.
- **Mengapa mendekati vanity**: 32 file untuk 32 model terdengar banyak. Untuk aplikasi sederhana, satu policy generik sudah cukup.
- **Mengapa bukan V2 (structural)**: Filament Shield **membutuhkan** policy per-resource untuk generate permission yang granular. Pesantren jelas butuh perbedaan akses: "guru MTW tidak boleh lihat data TSN", "bendahara hanya bisa `view_payment` di unit-nya sendiri", "admin-sekolah bisa `update_*` tapi bukan `delete_*`". Setiap policy mengikat pada perbedaan perilaku otorisasi yang **nyata**.
- **Toleransi**: Biarkan. Untuk Filament + RBAC, 1 policy per resource adalah idiom, bukan vanity.

### 3. `CheckSystemHealth` 582 baris dengan 21 metode cek [V0 — Cosmetic, proporsional]

- **Apa**: Action `CheckSystemHealth` punya 21 metode `checkXxx()` yang masing-masing mengembalikan `['ok'/'warning'/'failure'/'skipped', pesan]`. Dipakai oleh `pondok:doctor`, `pondok:install`, `pondok:upgrade`.
- **Di mana**: `app/Actions/Core/CheckSystemHealth.php`.
- **Mengapa mendekati vanity**: 582 baris untuk *satu* kelas. Kedengarannya banyak.
- **Mengapa bukan V1**: Shared hosting cPanel punya 21 mode kegagalan umum (PHP version, ekstensi hilang, `.env` writable, `proc_open` dimatikan, `upload_max_filesize` 2M, queue worker macet, scheduler tidak jalan, dll). Tiap metode cek **satu mode** yang terdokumentasi. 1 cek = 1 pesan bahasa Indonesia = 1 baris di output `pondok:doctor`. Mengurangi ke 1 method generik akan menghasilkan pesan error generik yang tidak bisa ditindaklanjuti operator sekolah (yang bukan programmer).
- **Kill cost**: 0. Jangan disentuh.

### 4. `GenerateInvoiceNumber` & `GenerateReceiptNumber` hard-coded format [V0 — Transisi yang sudah dijadwalkan]

- **Apa**: Dua Action kecil (53 dan 50 baris) yang menentukan format nomor tagihan `INV-{Ym}-NNNNN` dan kuitansi `KW-{Ym}-NNNNN` di kode.
- **Di mana**: `app/Actions/Keuangan/GenerateInvoiceNumber.php`, `GenerateReceiptNumber.php`.
- **Status**: **F039** (`todo`) akan memindahkan keduanya ke format yang diatur admin via setting. Saat ini keduanya berfungsi sebagai **placeholder yang menunggu keputusan user**.
- **Mengapa bukan V1**: Bukan vanity — ini *transitional complexity* yang akan hilang setelah F039. Membuangnya sekarang akan menggandakan pekerjaan F039 nanti.
- **Toleransi**: Biarkan. F039 akan membersihkannya.

### 5. `HasUnitScope` trait + `UnitScope` class + `applyUnitScope()` overridable [V1 — Sedikit over-abstracted, tapi punya konsumen nyata]

- **Apa**: Trait `HasUnitScope` (36 baris) + Scope class terpisah (35 baris), dengan model yang bisa override `applyUnitScope()` untuk kolom alternatif (`$unitScopeColumn`) atau join lewat relasi (`Invoice` lewat `Student`).
- **Di mana**: `app/Models/Concerns/HasUnitScope.php`, `app/Models/Scopes/UnitScope.php`.
- **Mengapa mendekati vanity**: Untuk *kasus aktual* pesantren (1-2 unit), `unit_id` di setiap tabel selalu sama. Trait + override terasa berlebihan.
- **Mengapa dibiarkan**: Abstraksi sudah **membayar ongkosnya**:
  - `Invoice::applyUnitScope()` join ke `students.unit_id` (bukan `invoices.unit_id`).
  - `FeeOverride` override ke `applicant_id`/`student_id` join.
  - Pola ini repeatable untuk 3-5 model.
  - Test cakupan (cakupan per-jenjang) ada di banyak Action.
- **Risiko yang diterima**: Open source berarti kebutuhan instalasi bervariasi. Ada pesantren 1 jenjang, ada yang 3 jenjang. Desain sekarang mengakomodasi keduanya. Biaya kognitifnya kecil, manfaatnya besar untuk yang multi-jenjang.
- **Toleransi**: Biarkan.

### 6. Filament Resources dipecah ke `Schemas/`, `Tables/`, `Pages/`, `RelationManagers/` [V0 — Idiom Filament 5]

- **Apa**: Tiap Filament Resource seperti `Posts` punya:
  - `PostResource.php` (103 baris)
  - `Schemas/PostForm.php` (konfigurasi form)
  - `Tables/PostsTable.php` (konfigurasi tabel)
  - `Pages/CreatePost.php`, `EditPost.php`, `ListPosts.php` (3 file untuk 3 halaman CRUD)
- **Di mana**: 29 folder Resource, masing-masing dengan subdirektori.
- **Mengapa mendekati vanity**: Kalau ditulis ulang sebagai kelas tunggal, `PostResource.php` akan jadi ~400 baris. Pecah jadi 4-5 file memang menambah navigasi.
- **Mengapa bukan V1**: Ini **idiom resmi Filament 5** untuk Resource yang form/tabelnya >= 50 baris. Filament generator default membuat struktur ini. Pondok mengikutinya.
- **Toleransi**: Biarkan. Bukan keputusan Pondok, keputusan Filament.

### 7. `SystemProbe` Service [V0 — Bener-bener dibutuhkan]

- **Apa**: Wrapper 48-baris untuk `PHP_VERSION`, `extension_loaded`, `functionEnabled`, `iniGet`, `isWritable`.
- **Di mana**: `app/Services/SystemProbe.php`.
- **Mengapa mendekati vanity**: Bisa ditulis inline.
- **Mengapa penting**: Shared hosting cPanel mematikan `proc_open`/`exec`/`symlink` secara acak per host. Wrapper ini **membolehkan test stub** tanpa mocking global PHP. F019 release smoke test bergantung padanya.
- **Toleransi**: Jangan disentuh.

---

## Temuan Tambahan yang **Bukan** Vanity (untuk dicatat, supaya tidak salah ditandai)

### A. `app('pondok.settings')` cache binding per-request (F033)

Bukan vanity. Justru **koreksi atas N+1** yang sebelumnya terjadi di otorisasi resource Filament. `feature_list.json` F033 eksplisit menyebut bukti: query settings hanya satu per key per request. Pola `app()->scoped` adalah idiom Laravel modern. **Jangan dihapus.**

### B. `Activitylog` Spatie untuk setiap mutation di Keuangan/Dokumen/PSB

Bukan vanity. Pesantren **wajib** bisa menjawab "siapa yang hapus data siswa Ani?", "kapan pembayaran atas tagihan #123 dibatalkan?", "siapa yang ubah tarif SPP?". Audit trail = kebutuhan regulasi, bukan embellishment.

### C. 4536+ test Pest dengan `VerifyGapsTest` per fitur

Bukan gold plating. Untuk aplikasi yang memegang uang (keuangan pesantren) dan data siswa, cakupan test ini adalah **prasyarat kepatuhan**. Red-tester terpisah memverifikasi adversarial cases. Justru ini **anti-vanity** — tim menolak mengirim tanpa adversarial verification.

### D. Filament Shield + Spatie Permission

Bukan overkill. 49 fitur × multi-role = kebutuhan RBAC granular yang tidak bisa dipenuhi oleh Auth bawaan Laravel.

### E. Custom Commands untuk `pondok:install`, `pondok:doctor`, `pondok:upgrade`

Bukan berlebihan. Shared hosting **tidak punya Artisan biasa** — paket rilis harus bisa di-install, di-upgrade, dan di-diagnosa via command kustom yang dipanggil dari cPanel Terminal atau cron. Ini operasi runtime, bukan developer convenience.

### F. 2 services (QrCodeRenderer, SystemProbe) — sangat sedikit

Justru ini **anti-vanity signal**. Tim menahan diri dari membuat `Service` class untuk hal yang tidak perlu dibungkus. QrCodeRenderer pun dipakai 1 tempat (surat keluar) — tapi karena konfigurasi QR-nya rumit (SVG inline tanpa prolog XML, modul transparan, accessibility), pembungkus masuk akal.

---

## Vanity Debt Estimate

**Sangat rendah: ~2-4 jam/bulan** attributable to keputusan arsitektur yang sedikit lebih abstrak dari kebutuhan (fragmentasi Action di Konten, UnitScope trait, policy-per-model). Sisanya adalah *kompleksitas yang dibenarkan* oleh domain.

Sebagai perbandingan:
- 0 jam/bulan = under-engineering (tidak ada audit, RBAC, idempoten tagihan) — mustahil untuk pesantren.
- 20+ jam/bulan = over-engineering — Pondok tidak sampai sini.
- 2-4 jam/bulan = **sweet spot** — tim menanggung ongkos yang setara dengan nilai yang diterima.

Estimasi breakdown:
- Fragmentasi `Actions/Konten/` (navigasi 45 file, tapi semua terpakai): 1-2 jam/bulan.
- `HasUnitScope` trait (overhead mental saat menambah model baru): 0.5-1 jam/bulan.
- 32 Policy classes (review apakah Shield default cukup): 0.5-1 jam/bulan.

---

## Kill Criteria — Versi Pondok

Karena Pondok adalah **produk** (bukan eksperimen), kill criteria di sini berbeda: bukan "apakah fitur ini hidup?", tapi "apakah keputusan arsitektur ini masih membayar ongkosnya?".

### Day-0 Setup

```
PROJECT: Pondok
OWNER: Solo developer + AI pair
KILL CRITERIA OWNER: Reviewer eksternal (tim audit pondok pesantren)
START DATE: Sudah berjalan (~F001 dimulai)
EVALUATION WINDOW: 90 hari per keputusan
BUDGET CAP: N/A (aplikasi internal, biaya = dev-time)
SUCCESS METRIC: "Semua fitur phase 1 lulus uji di cPanel production dalam 90 hari"
SUCCESS THRESHOLD: 18/19 fitur phase 1 lulus
MEASUREMENT METHOD: F019 release smoke test + DemoSeeder run
```

### Tier 1 — Hard Kill (Otomatis)

| # | Pemicu | Cara Deteksi |
|---|--------|---------------|
| H1 | Server cPanel mati-mati fungsi yang dipakai `SystemProbe` (mis. `proc_open`) dan patch 30 hari tidak memungkinkan | Log error F019 release smoke test |
| H2 | Tagihan PSB double-terbit karena race condition pada `GenerateInvoiceNumber` yang belum menjadi transactional | Laporan tim operasional |
| H3 | Filament Shield dan Spatie Permission konflik setelah upgrade Laravel minor | `composer outdated` mingguan + CI test suite |

### Tier 2 — Review Trigger (default-to-kill)

| # | Pemicu | Ambang | Default |
|---|--------|--------|---------|
| R1 | `app('pondok.settings')` cache binding terbukti tidak perlu (query N+1 tidak muncul setelah 90 hari di production) | 90 hari tanpa masalah | Hapus binding, pakai query langsung |
| R2 | UnitScope trait dipakai < 2 model setelah F050 ke atas | Per kuartal | Sederhanakan ke closure global di `AppServiceProvider` |
| R3 | Action `GenerateInvoiceNumber` / `GenerateReceiptNumber` masih hard-coded setelah F039 selesai | 30 hari pasca F039 | Inline ke model Invoice/Payment |
| R4 | 32 policy class tapi < 10 yang punya logika berbeda dari default Shield | Per kuartal | Hapus policy kustom, biarkan Shield default |
| R5 | Action kecil di `Actions/Konten/` (Delete*, Restore*, FindPublicPost) menjadi tidak terpakai setelah refactor navigasi/blok | 60 hari pasca F049 | Hapus Action yang benar-benar mati |
| R6 | 5+ fitur phase 2 ditunda → indikasi over-scope | Per kuartal | Tunda phase 3+ |

### Tier 3 — Soft-Go (Sudah Lulus)

Pondok sudah melewati soft-go untuk keputusan-keputusan ini:
- **F019 (rilis cPanel)**: suite penuh PHP 8.3 3996/3996 lulus, migrasi MySQL OK → lulus.
- **F033 (cache setting)**: 303 test lulus, terdokumentasi sebagai koreksi anti-pola → lulus.
- **F034 (DemoSeeder)**: idempotent, deterministik, ditolak di `APP_ENV=production` → lulus.

---

## Anti-Vanity Addendum (A1-A7, khusus Pondok)

| # | Kriteria | Status di Pondok |
|---|----------|------------------|
| A1 | Tidak boleh menambah abstraksi tanpa 2+ konsumen nyata | `HasUnitScope` punya 3-5 konsumen (✓). Action kecil Konten adalah single-purpose wrapper, bukan abstraksi — tidak melanggar A1 (✓). |
| A2 | Tidak menambah dependensi tanpa justifikasi tertulis | `composer.json` minimalis (Laravel + Filament + Spatie + Pest). Audit `composer outdated` per kuartal. |
| A3 | Komponen yang tidak diubah dalam 90 hari ditandai untuk tinjauan hapus | Terapkan via `git log --since="90 days ago"` per kuartal. |
| A4 | Perubahan arsitektur butuh bagian "apa yang bisa lebih sederhana?" di RFC | `docs/plans/Fxxx-plan.md` harus berisi bagian "Simplifikasi" sejak F050 ke atas. |
| A5 | Tidak ada pilihan teknologi berdasarkan "learning opportunity" di production | Cek di PR review: tolak jawaban "pengen coba" untuk pilihan stack. |
| A6 | Budget kompleksitas: setiap fitur dapat max file/modul count proporsional | Rata-rata Actions/fitur: Keuangan 21/8 = 2.6, Konten 45/5 = 9.0. **Perlu dokumentasi, bukan refactor.** |
| A7 | "Bisakah junior engineer memaintain ini?" dijawab di setiap design review | Terapkan di review `docs/plans/Fxxx-plan.md`. |

---

## Jawaban atas Pertanyaan Sulit

Pada audit putaran pertama, tiga pertanyaan sulit diajukan. Setelah verifikasi faktual dan diskusi dengan owner, jawabannya sebagai berikut (dijawab AI, bukan dilempar ke owner):

### Jawaban 1 — Fragmentasi Action di Konten

**Keputusan: Biarkan.**

Semua 45 Action masih terpakai dan berfungsi. Project open source punya kebutuhan instalasi yang bervariasi — pola Action yang konsisten lebih berharga daripada penghematan 7 file kecil. Risiko utama (kode mati) sudah diverifikasi dan tidak terjadi.

### Jawaban 2 — Asumsi multi-jenjang

**Keputusan: Perlu, pertahankan.**

Open source berarti ada pesantren dengan 1 jenjang dan ada yang 3 jenjang. Desain `UnitScope` mengakomodasi keduanya. Biaya kognitif kecil (0.5-1 jam/bulan), manfaat besar untuk yang multi-jenjang. Tidak ada alasan untuk menghapus.

### Jawaban 3 — Kode mati

**Fakta: Tidak ada.**

Semua Action yang dicurigai mati ternyata masih dipakai. Verifikasi via grep pada 17 Okt 2026:

- `DeletePost`/`RestorePost` → `PostsTable.php`
- `DeleteVideo` → `VideoResource.php`
- `DeletePhoto` → `PhotosRelationManager.php`
- `DeleteCategory` → `CategoryResource.php`
- `DeleteAlbum` → `AlbumResource.php`
- `FindPublicPost` → `ShowPost.php`
- `AddPageToPrimaryNavigation` → `EditStaticPage.php`
- `SaveAlbum`/`SavePost`/`SaveCategory`/`SaveVideo` → masih dipakai di Resource masing-masing

---

## Ringkasan Eksekutif (1 paragraf)

Pondok **bukan** codebase yang dibangun untuk pamer. Stack-nya standar, pemisahan tanggung jawabnya konsisten, dan setiap Action punya justifikasi domain. Satu-satunya area yang sempat dicurigai (fragmentasi 45 Action di `Actions/Konten/`) ternyata **bukan masalah** — semua masih terpakai, dan keputusan akhir adalah mempertahankannya demi konsistensi dan kebutuhan open source yang bervariasi. Temuan-temuan lain (32 Policy, 21 cek di `CheckSystemHealth`, Filament Resources ter-decompose ke Schemas/Tables/Pages) **bukan vanity** — itu idiom resmi Filament + Laravel. **Vanity debt rendah: 2-4 jam/bulan.** Tidak ada yang perlu dihapus atau dirombak. Yang perlu dilakukan: memastikan dokumentasi menjelaskan keputusan arsitektur dan risikonya — dokumen ini adalah langkah pertama.

---

## Lampiran: Statistik Pendukung

```
app/Actions/             143 file  13.634 baris
app/Filament/            123 file
app/Models/               40 file
app/Policies/             32 file
app/Services/              2 file
app/Livewire/              2 file
app/Http/                 23 file

Distribusi Actions per modul:
  Konten    : 45 (40.4% dari 143)
  Psb       : 26
  Keuangan  : 21
  Dokumen   : 11
  Akademik  :  9
  Prestasi  :  8
  Core      :  7
  Asrama    :  5
  Dashboard :  3
  Release   :  3

Action terkecil (< 35 baris): 27 dari 143 (18.9%)
  - DeletePost, RestorePost, DeleteVideo: 16 baris
  - DeletePhoto, RecordSchedulerHeartbeat: 18-19 baris
  - FindPublicPost, DeleteCategory: 20-23 baris

Test suite: ~4536 test Pest, PHP 8.3 + 8.4, MySQL + SQLite
Fitur phase 1 selesai: F001-F019 + F033 + F034 + F035-F049 (F038, F039, F040, F041 todo)
```

---

*Audit ini dilakukan terhadap commit `12f81e0` di branch `main`. Tidak ada perubahan kode yang dilakukan — dokumen ini murni observasi dan rekomendasi. Pertanyaan sulit dijawab oleh AI setelah verifikasi faktual (grep) pada 17 Okt 2026.*
