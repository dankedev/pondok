# Pondok — Sistem Informasi Pesantren & Sekolah Terpadu

Situs proyek: **pondok.org**

Aplikasi open source untuk pondok pesantren dan sekolah: portal publik, PSB, data santri, asrama, tahfidz, perizinan, dokumen, prestasi, dan keuangan (SPP & biaya lain). Pengguna pertama: **Ma'had Al-Khair**.

Dokumen acuan:
- `docs/prd.md` — kebutuhan produk dan kriteria penerimaan
- `docs/erd.md` — skema database lengkap (Mermaid) beserta aturan bisnis per modul
- `docs/design-guide.md` — panduan desain UI (warna, tipografi, status, format data, layout, state, bahasa)

Bila ada konflik, urutan prioritas: instruksi user > `docs/prd.md` > `docs/erd.md` > file ini.

**Wajib:** sebelum membuat atau mengubah apa pun yang tampil ke pengguna (Filament resource/page/widget, Blade, Livewire, teks di `lang/id`, CSS), baca `docs/design-guide.md`, panggil skill `/anti-ui-slop` bila tersedia (alur §13, tetap dalam batas panduan), dan jalankan checklist §0 sebelum menyatakan pekerjaan selesai.

## Stack

- **Laravel 13**, PHP 8.3+
- **Filament** (versi stabil terbaru yang kompatibel) untuk seluruh panel admin
- **Blade + Livewire + Tailwind** untuk portal publik, portal wali, dan form PSB
- **Spatie Laravel Permission** untuk RBAC, **Filament Shield** untuk integrasi
- **Spatie Laravel Activitylog** untuk audit (wajib di modul keuangan)
- **Pest** untuk testing
- Database: **MySQL/MariaDB** di produksi, **SQLite** di development. Kode harus tetap kompatibel dengan Postgres. Jangan pakai fitur khusus satu database tanpa fallback.

## Batasan deployment (penting)

Target utama adalah **shared hosting cPanel** atau VPS kecil, karena sekolah pengguna open source umumnya hanya punya itu.

- Tidak boleh mewajibkan Redis, Horizon, Octane, Reverb, atau proses yang berjalan terus.
- Queue memakai driver `database`. Worker dijalankan dari scheduler (`queue:work --stop-when-empty`) lewat satu cron `schedule:run`.
- Tidak ada Node.js saat runtime. Aset di-build saat rilis dan ikut dalam paket rilis.
- File memakai disk lokal: `public` untuk konten website, `private` untuk dokumen santri dan berkas PSB. S3/R2 opsional lewat konfigurasi.
- Instalasi untuk satu sekolah per instance (single-tenant). Jangan membangun multi-tenant.

## Arsitektur

- Logika bisnis ada di **Action class** (`app/Actions/<Modul>/...`), satu Action untuk satu operasi, misalnya `GenerateMonthlyInvoices`, `AcceptApplicant`, `AssignRoom`, `RecordPayment`. Filament resource, controller, dan command hanya memanggil Action.
- **Service class** hanya untuk integrasi eksternal (payment gateway, WhatsApp, PDF).
- Struktur modular: Akademik, Asrama, Tahfidz, Perizinan, Dokumen, Prestasi, PSB, Keuangan, Konten. Setiap modul selain inti bisa diaktifkan atau dimatikan lewat `settings` (key `modules.<nama>.enabled`). Navigasi Filament dan route mengikuti status ini.
- Dashboard bersifat role-aware. Setiap widget dicek permission-nya dan tetap tampil aman ("Modul belum tersedia") jika tabel atau modulnya belum aktif.

### Prinsip: YAGNI, KISS, DRY

Berlaku untuk rencana, kode, dan test. Bila bertentangan, urutannya YAGNI, lalu KISS, lalu DRY.

- **YAGNI.** Bangun hanya yang dibutuhkan kriteria penerimaan sekarang. Jangan menambah tipe, opsi, setting, parameter, atau abstraksi "untuk nanti" yang belum punya pemakai. Fitur yang belum diminta user ditulis sebagai catatan, bukan dikerjakan.
- **Data demo bukan data produksi.** Tidak perlu kode perbaikan atau migrasi khusus untuk data demo lama; demo cukup diulang dengan `php artisan migrate:fresh --seed`. Migrasi data hanya untuk data nyata milik lembaga, dan `down()` boleh sederhana (cukup mengembalikan skema, catat bila data tidak bisa dipulihkan).
- **KISS.** Pilih solusi paling sederhana yang lulus kriteria. Pakai fitur bawaan Laravel/Filament sebelum membuat sendiri. Satu Action tambahan, trait, atau komponen baru harus punya alasan yang jelas di rencana.
- **DRY, secukupnya.** Pakai ulang Action, komponen, dan helper yang sudah ada sebelum membuat yang baru. Ekstrak abstraksi bersama setelah ada pemakai kedua yang nyata, bukan karena dugaan.

## Konvensi kode & data

- **Nama tabel, kolom, dan kode dalam bahasa Inggris.** Seluruh teks UI dalam bahasa Indonesia, lewat file `lang/id`.
- **Uang selalu `unsignedBigInteger` rupiah tanpa desimal.** Jangan pakai float atau decimal untuk uang.
- **Nominal tagihan adalah snapshot.** Perubahan tarif atau beasiswa tidak boleh mengubah tagihan yang sudah dibuat.
- Data keuangan tidak dihapus. Pembatalan memakai status `batal`. Foreign key ke tabel keuangan memakai `restrictOnDelete`.
- Soft delete hanya untuk `students`, `applicants`, dan `posts`.
- Nomor KK, NIK, dan sejenisnya memakai cast `encrypted`. Dokumen pribadi hanya di disk `private` dan diunduh lewat route yang dicek permission.
- Unique key dengan kolom nullable memakai generated column `COALESCE(col, 0)` (lihat `fee_rates` di ERD).
- Riwayat disimpan dengan baris baru, bukan menimpa: kelas (`enrollments`), kamar (`room_assignments`), halaqah (`halaqah_members`), musyrif (`dormitory_supervisors`), dokumen (`replaced_by_id`).
- **Konten keagamaan** (seeder demo, contoh konten, mockup, teks bawaan) mengikuti aqidah Ahlus Sunnah wal Jama'ah dengan pemahaman para sahabat. Hari besar hanya Idul Fitri dan Idul Adha; jangan menampilkan Maulid, Isra' Mi'raj, Nuzulul Qur'an, Tahun Baru Hijriah, Nisfu Sya'ban, haul, tahlilan, harlah, hadrah, atau shalawatan berirama. Kegiatan contoh yang sesuai: kajian kitab, daurah tahfidz, ujian tasmi', shalat Id dan qurban, persiapan Ramadhan.

## Otorisasi

- **Permission** (Spatie) menentukan modul atau aksi yang boleh diakses.
- **Cakupan jenjang** (`unit_user`) menentukan data jenjang mana yang terlihat. Tidak punya baris di `unit_user` berarti boleh melihat semua jenjang. Diterapkan sebagai global scope pada `Student`, `Invoice`, `Applicant`, dan model turunannya.
- Peran bawaan (di-seed saat instalasi): `super-admin`, `bendahara`, `admin-konten`, `admin-sekolah`, `admin-psb`, `kesantrian`, `musyrif`, `ustadz-tahfidz`, `petugas-pos`, serta `wali` untuk portal wali.

## Aturan bisnis utama

**Tarif biaya** (`fee_rates`): cari dari yang paling spesifik, yaitu angkatan + asrama, angkatan, jenjang + asrama, lalu jenjang. Pilih baris dengan `effective_from_year` terbaru yang sudah berlaku. Tarif yang tidak berubah cukup satu baris dan tidak disalin tiap tahun.

**Keringanan/beasiswa** (`fee_overrides`): per santri atau pendaftar, per jenis biaya, dengan masa berlaku. Mode `nominal` (bayar sejumlah ini, 0 = gratis), `potongan` (dikurangi rupiah), atau `persen`. `invoice_items` menyimpan `base_amount`, `discount_amount`, dan `amount`.

**Tagihan gabungan/terpisah**: jenis biaya dengan `billing_group` yang sama masuk ke satu `invoices`. Generator bulanan harus idempotent, dengan unique (`student_id`, `period`, `billing_group`). Biaya `applies_to = mukim` hanya untuk santri yang punya `room_assignments` aktif.

**Cicilan**: pembayaran pada tagihan gabungan dialokasikan ke item sesuai `fee_types.priority` dan dicatat di `payment_allocations`.

**PSB**: daftar, lalu tagihan `saat_daftar` dibuat. Formulir lunas membuka unggah berkas dan tes. Saat diterima, tagihan `saat_diterima` dibuat dan beasiswa jalur (`admission_tracks.scholarship_id`) diterapkan. Saat daftar ulang (minimal pembayaran sesuai `psb.min_paid_percent`), dibuat `students`. `student_id` diisi pada tagihan dan override milik pendaftar, dan berkas valid disalin ke `documents`.

**Perizinan**: approver ditentukan otomatis dari `leave_types.approver` dan `dormitory_supervisors` santri. "Terlambat" dihitung dari `returned_at` dibanding `return_due`, tidak disimpan sebagai status.

**Tahfidz**: setoran disimpan sebagai rentang surah dan ayat (`quran_surahs` adalah data seed 114 surah). `student_juz_progress` diperbarui oleh Action setiap ada setoran atau ujian.

## Testing

Wajib ada test Pest untuk:
- Akses per peran: setiap peran hanya melihat modul dan widget sesuai permission.
- Cakupan jenjang lewat `unit_user`.
- Resolusi tarif (semua urutan fallback) dan ketiga mode override.
- Generator tagihan: idempotent, gabungan atau terpisah, mukim atau nonmukim, santri tanpa tarif dicatat di log dan dilewati.
- Alokasi cicilan berdasarkan prioritas.
- Konversi pendaftar menjadi santri (tagihan, override, dan dokumen ikut pindah).
- Dashboard tetap render ketika modul atau tabel belum ada.

Cara menjalankan test (mesin pengembang hanya 8GB RAM):
- Selama bekerja, jalankan file test yang terdampak saja (`php artisan test --compact <path>`).
- Suite penuh dijalankan **sekali** di akhir fitur, di PHP 8.4, oleh sesi utama dan bukan oleh subagent. Cek `memory_pressure` dulu, dan jangan jalankan dua suite bersamaan, termasuk dari sesi lain (`Storage::fake` bisa bentrok).
- Suite penuh: `php artisan test --compact --parallel --processes=3`, sekitar 2 menit di PHP 8.4 (17 Okt 2026, ±4000 test). Bila tiba-tiba jauh lebih lambat, cari biaya setup yang berulang di setiap test (seeder, fixture di `beforeEach`), bukan test satu per satu. Contohnya `RolePermissionSeeder` yang dipanggil di setup sebagian besar test: dulu ±1 detik per panggilan (`findOrCreate` per permission) dan membuat suite ±18 menit.
- PHP 8.3 (target cPanel) dijalankan GitHub Actions (`.github/workflows/tests.yml`) setiap push ke `main`, atau manual lewat tab Actions. Repo private dengan kuota menit terbatas, jadi push dikelompokkan (mis. satu push per fitur selesai), bukan per commit. Suite PHP 8.3 tidak perlu dijalankan di laptop, kecuali CI gagal khusus di 8.3 (di Herd: `php83 artisan test --compact`).
- Test baru ditulis untuk perilaku yang berubah dan risiko nyata. Jangan menambah test hanya untuk menaikkan jumlah. Verifikasi adversarial (mode VERIFY) menambah paling banyak ±15 test, dan hanya untuk celah yang benar-benar dicurigai.

## Alur kerja agent

### 1. Commit

Setiap commit mengikuti skill **git-commit** (`~/.claude/skills/git-commit/SKILL.md`): micro-commits (satu perubahan logis per commit) dengan format Conventional Commits `type(scope): deskripsi`. Scope memakai `key` modul, misalnya `feat(asrama): ...`, `fix(keuangan): ...`. Commit hanya bila user meminta.

### 2. Daftar fitur (`feature_list.json`)

`feature_list.json` di root adalah sumber kebenaran status fitur. Aturan:

- **ID** berformat `F` + 3 digit (`F001`). Unik, permanen, tidak pernah diubah, dipakai ulang, atau diurutkan ulang. Fitur baru memakai `next_id`, lalu `next_id` dinaikkan satu. `key` (kebab-case, `<modul>-<topik>`) juga unik.
- **Status:** `todo` (belum), `in-progress` (sedang), `handoff` (dialihkan ke agent lain), `blocked` (tertahan, alasan di `notes`), `deferred` (ditunda), `done` (selesai).
- **`depends_on`** berisi ID yang wajib `done` sebelum fitur boleh mulai. Jangan mulai fitur yang dependensinya belum `done`; laporkan ke user.
- **Sebelum mulai:** ubah status ke `in-progress` dan isi `owner` (nama sesi/agent). Satu agent hanya memegang satu fitur `in-progress`. Jangan ambil fitur yang `owner`-nya agent lain.
- **Menandai `done`:** hanya setelah semua `acceptance` terpenuhi, test terkait lulus, dan Pint dijalankan. Isi `evidence` dengan hash commit dan test yang lulus, lalu kosongkan `owner`.
- Pekerjaan di luar daftar: tambahkan entri baru dulu, jangan kerjakan diam-diam. Fitur batal tidak dihapus; jadikan `deferred` dan tulis alasan di `notes`.
- Perubahan `feature_list.json` ikut commit fiturnya (atau `docs(features): ...` bila berdiri sendiri).

### 3. Handoff antar agent

Jika fitur dialihkan ke agent/sesi lain (konteks hampir habis, ganti sesi, atau dibagi ke agent paralel):

1. Salin `docs/handoffs/_TEMPLATE.md` menjadi `docs/handoffs/<ID>-<YYYYMMDD>-<HHMM>.md` dan isi semua bagian, termasuk perubahan yang belum di-commit dan bukti test terakhir.
2. Di `feature_list.json`: status `handoff`, isi `handoff` dengan path file tersebut.
3. Agent penerima membaca handoff, memverifikasi kondisi repo, lalu mengubah status ke `in-progress` dan `owner` ke namanya. File handoff lama tidak dihapus.

### 4. Jalur kerja

Setiap fitur ditetapkan ke satu jalur saat diklaim, dan jalurnya ditulis di `notes` entri `feature_list.json`. Bila ragu, pakai jalur penuh.

**Jalur cepat**: perubahan tampilan (Blade, CSS, komponen), teks `lang/id`, susunan navigasi admin, data demo, dan dokumen. Syaratnya: tanpa migration, tanpa perubahan uang/tagihan/PSB/hak akses, dan tanpa Action baru yang mengubah data.

1. Sesi pelaksana menulis rencana singkat langsung di `docs/plans/<ID>-plan.md` (maks. ±60 baris: tujuan, file yang diubah, 3–8 kriteria penerimaan). Tidak ada review rencana.
2. Implementasi langsung oleh sesi itu atau satu `green-coder`. Test ditambah hanya untuk perilaku yang berubah (lihat bagian Testing).
3. Jalankan test yang terdampak, Pint, lalu cek tampilan di 360px, 1024px, dan desktop.
4. Laporkan ke user.

**Jalur penuh**: semua yang menyentuh migration atau skema, uang, tagihan, PSB, dokumen pribadi, permission atau cakupan jenjang, serta Action yang mengubah data. Siklusnya dijalankan orkestrator secara berurutan dengan tim subagent (`.claude/agents/`):

1. `product-manager`: pilih fitur, klaim di `feature_list.json`, tulis `docs/plans/<ID>-plan.md` (maks. ±150 baris, 8–15 AC).
2. `plan-reviewer`: verdict `APPROVED` / `CHANGES_REQUESTED`, cukup satu putaran. Temuan blocker atau major direvisi satu kali oleh product-manager. Bila masih ada blocker setelah itu, orkestrator memutuskan atau bertanya ke user; tidak ada putaran ketiga. Temuan minor dicatat dan diselesaikan saat implementasi.
3. `red-tester` mode RED: test Pest dari kriteria penerimaan, semuanya gagal karena alasan yang benar.
4. `green-coder`: implementasi sampai hijau, refactor, Pint. Tidak boleh mengubah test. Fitur besar dipecah menjadi beberapa pemanggilan per kelompok test (mis. migration + Action, lalu Filament, lalu portal), masing-masing dengan laporan.
5. `red-tester` mode VERIFY: cari celah yang berisiko nyata. `FAIL` → kembali ke langkah 4.

Untuk kedua jalur: setelah selesai, orkestrator melaporkan ke user. Commit dan status `done` hanya atas permintaan user (lihat bagian 1 dan 2). Satu sesi pelaksana melapor langsung ke orkestrator; jangan menyisipkan sesi perantara.

## Urutan pengembangan

Rincian per fitur dan statusnya ada di `feature_list.json`.

1. **Fase 1 (MVP):** inti (users, RBAC, settings, units, academic_years), Akademik & Santri, Asrama, PSB, Keuangan, Konten, Dokumen, Prestasi, Dashboard.
2. **Fase 2:** virtual account (Midtrans/Xendit/Tripay/bank syariah), notifikasi WhatsApp (driver bisa diganti), portal wali.
3. **Fase 3:** Tahfidz, Perizinan, absensi, kedisiplinan.
4. **Fase 4:** rapor diniyah, tabungan/uang saku, kas umum yayasan, kepegawaian & honor, kesehatan, alumni & donasi.

## Glosarium

| Istilah | Arti | Di kode |
|---|---|---|
| Santri | Peserta didik | `students` |
| Jenjang | SD / SLTP / SLTA | `units` |
| Angkatan | Kelompok berdasarkan tahun masuk per jenjang | `cohorts` |
| Rombel | Kelas per tahun ajaran (1A, 1B) | `classrooms` |
| Mukim / nonmukim | Tinggal di asrama / pulang-pergi | dari `room_assignments` aktif |
| Musyrif | Pembina asrama | `dormitory_supervisors` |
| Halaqah | Kelompok setoran hafalan | `halaqahs` |
| Ziyadah / murajaah / tasmi' | Hafalan baru / mengulang / memperdengarkan hafalan panjang | `memorization_records.type` |
| Syahadah | Sertifikat kelulusan ujian tahfidz | `tahfidz_exams.certificate_no` |
| Sambangan | Kunjungan wali | `visits` |
| PSB | Penerimaan santri baru | `applicants`, `admission_*` |
| Uang pangkal | Biaya masuk saat diterima | `fee_types` frekuensi `saat_diterima` |

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>
