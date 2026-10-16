# F019 rilis-cpanel — Paket rilis & instalasi shared hosting

Status rencana: APPROVED

## Ringkasan & tujuan

PRD §5 butir 7: aplikasi bisa dipasang dan berjalan di shared hosting cPanel dengan PHP 8.3 dan MySQL, tanpa staf IT khusus (PRD §1 butir 3, §4). F019 menyediakan:

1. **Paket rilis** (zip) yang langsung bisa diunggah ke cPanel: `vendor/` tanpa paket dev (dikunci platform PHP 8.3.15 oleh `composer.json`), `public/build` hasil Vite, aset Filament (`public/{css,js,fonts}/filament`), tanpa `node_modules`, `tests`, `.git`, `.env`, dan berkas kerja agent.
2. **Perintah instalasi** `pondok:install` yang bisa dijalankan dari Terminal cPanel **atau dari cron sekali jalan** (non-interaktif lewat `.env`): cek kebutuhan server, `APP_KEY`, migrasi, seed data sistem, akun super-admin, `storage:link` dengan jalan keluar bila `symlink` dimatikan hosting.
3. **Perintah cek kesehatan** `pondok:doctor`: ekstensi, versi PHP, folder bisa ditulis, aset build, `APP_URL`, disk `private` tidak publik, cron terakhir berjalan (heartbeat), antrean tertunda/gagal, batas unggah PHP.
4. **Perintah pembaruan** `pondok:upgrade`: mode pemeliharaan, migrasi, seed sistem (permission/setting baru), bersihkan cache.
5. **Dukungan `public_html`** bila document root domain tidak bisa diubah: `pondok:publish-public` menyalin isi `public/` ke `public_html` dan menulis `index.php` yang menunjuk ke folder aplikasi.
6. **Dokumentasi** bahasa Indonesia: `docs/instalasi.md` (sekolah/operator) dan `docs/rilis.md` (pengelola rilis), `.env.production.example` berisi nilai produksi (`.env.example` tetap untuk pengembang), README proyek.

Catatan lintas fitur yang dipenuhi (notes F019, F010/F014/F016/F017): paket wajib memuat `public/build`; panduan mencakup `storage:link` (dan alternatifnya), `APP_URL` yang benar (QR surat F014, gambar portal F016/F017), `TRUSTED_PROXIES` opsional (F010), dan satu cron `schedule:run`.

## Ruang lingkup

- Termasuk:
  - Action rilis: `BuildReleaseManifest`, `WriteReleaseArchive`, `PrepareReleaseStaging` + command `pondok:release` (dijalankan pengelola di mesin build, ditolak di `APP_ENV=production`).
  - Action inti: `CheckSystemHealth`, `CreateSuperAdmin`, `LinkPublicStorage`, `PublishPublicDirectory`, `RecordSchedulerHeartbeat` + command `pondok:install`, `pondok:doctor`, `pondok:upgrade`, `pondok:publish-public`.
  - `App\Services\SystemProbe`: pembungkus fungsi runtime PHP (versi, ekstensi, fungsi yang dimatikan, `ini_get`, `is_writable`) agar bisa diganti di test.
  - Seeder: `InstallSeeder` (data awal tanpa super-admin) dan `SystemSeeder` (data sistem yang aman di-seed ulang saat upgrade). `DatabaseSeeder` memanggil `InstallSeeder` + `SuperAdminSeeder`. `SuperAdminSeeder` memakai `CreateSuperAdmin`.
  - Heartbeat scheduler di `routes/console.php` (event pertama, setiap menit).
  - Konfigurasi: `config/pondok.php` (`public_path`), `config/filesystems.php` (root disk `public` dari `PUBLIC_STORAGE_ROOT`, link dari `PONDOK_PUBLIC_PATH`), `AppServiceProvider::register()` memanggil `usePublicPath` bila dikonfigurasi.
  - Stub `resources/stubs/public-index.php.stub`.
  - Action `App\Actions\Core\PreserveCpanelHtaccessBlocks` (mempertahankan blok handler/INI cPanel di `.htaccess`).
  - `.htaccess` penolak di root aplikasi (`/.htaccess`, ikut paket) sebagai pertahanan berlapis bila aplikasi terekstrak ke `public_html`.
  - Helper test bersama `isolateInstallation()` di `tests/Pest.php` (lihat Konvensi test).
  - `.env.production.example` baru (nilai produksi; `.env.example` tidak diubah), `.gitignore` (`/VERSION`), `README.md`, `docs/instalasi.md`, `docs/rilis.md`, teks `pondok.install.*`, `pondok.doctor.*`, `pondok.upgrade.*`, `pondok.release.*`, `pondok.publish_public.*` di `lang/id/pondok.php`.
- Tidak termasuk (eksplisit):
  - Installer berbasis web (wizard di browser) dan halaman status sistem di panel. Diusulkan sebagai entri baru (lihat laporan).
  - Backup database otomatis (mysqldump tidak dijamin ada di cPanel). Panduan memakai fitur Backup cPanel / ekspor phpMyAdmin; `pondok:upgrade` hanya meminta konfirmasi backup.
  - Cache konfigurasi/route otomatis (`php artisan optimize`). Hanya didokumentasikan sebagai langkah opsional (lihat pertanyaan 4).
  - CI/GitHub Actions untuk build rilis, rilis Docker, dukungan multi-tenant.
  - Perubahan dependency (`composer.json`/`package.json`). Tidak ada paket baru; `ZipArchive` (ext-zip) sudah wajib karena `openspout/openspout`.
  - Mengubah perilaku queue/generator di `routes/console.php` selain menambah heartbeat.
  - Memperbaiki `AcademicYearSeeder` yang mengunci "2026/2027" (dicatat di risiko).

## Model data

Tidak ada tabel atau migration baru. Yang dipakai:

- `migrations` (cek migrasi tertunda), `jobs` dan `failed_jobs` (antrean, sudah ada dari `0001_01_01_000002`), `users`, tabel Spatie Permission, `settings`, `units`, `academic_years`, `document_types`, `admission_tracks` (lewat seeder).
- **Heartbeat scheduler** disimpan sebagai berkas `storage/framework/pondok-scheduler-heartbeat` berisi unix timestamp (bukan cache/DB: tidak hilang oleh `cache:clear`/`optimize:clear`, tidak butuh DB, tidak mengotori `settings` yang tampil di panel).
- **Versi terpasang**: berkas `VERSION` di root aplikasi, ditulis oleh `pondok:release`. Tidak ada di repo (`.gitignore`); di development dibaca sebagai `dev`.

## Action class

Semua Action tanpa efek samping di luar yang disebut. Teks keluaran/pesan dari `lang/id/pondok.php`.

### `App\Services\SystemProbe` (bukan Action)

Kelas tidak `final`, di-resolve lewat container (test mengganti dengan `app()->instance(SystemProbe::class, $fake)`). Metode:
- `phpVersion(): string` (`PHP_VERSION`)
- `extensionLoaded(string $name): bool`
- `functionEnabled(string $name): bool` — `function_exists($name)` dan tidak ada di `ini_get('disable_functions')` (dipisah koma, di-trim, case-insensitive).
- `iniGet(string $key): string|false`
- `isWritable(string $path): bool`

### `App\Actions\Core\CheckSystemHealth`

`handle(): list<array{key: string, status: 'ok'|'warning'|'failure'|'skipped', message: string}>` — urutan tetap seperti tabel. `hasFailures(array $checks): bool`. Konstanta `REQUIRED_EXTENSIONS` dan `WRITABLE_PATHS`. Metode statis murni `databaseVersionSupported(string $driver, string $version): bool`.

| key | Aturan | Status |
|---|---|---|
| `php_version` | `version_compare(probe->phpVersion(), '8.3.0', '>=')` | gagal → `failure` (pesan memuat versi saat ini dan "8.3") |
| `extensions` | `REQUIRED_EXTENSIONS` = `ctype, dom, fileinfo, filter, hash, iconv, intl, json, libxml, mbstring, openssl, pcre, session, tokenizer, xmlreader, zip, pdo` + driver PDO dari `config('database.connections.<default>.driver')`: `mysql`/`mariadb` → `pdo_mysql`, `sqlite` → `pdo_sqlite`, `pgsql` → `pdo_pgsql` | ada yang hilang → `failure`, pesan memuat nama yang hilang (urut abjad, dipisah koma) |
| `app_key` | `config('app.key')` tidak kosong | kosong → `failure` |
| `app_env` | `app()->environment('production')` dan `config('app.debug') === false` | env bukan production → `warning`; production + debug true → `warning` |
| `app_url` | `config('app.url')` harus URL absolut (`scheme` http/https + `host`) | tidak valid → `failure`; di production: host `localhost`, `127.0.0.1`, `::1`, berakhiran `.test`/`.local`/`.localhost` → `warning`; skema `http` → `warning` (anjuran https). Di luar production, URL valid → `ok`. Pesan selalu memuat URL yang dipakai. |
| `database` | `DB::connection()->getPdo()` berhasil (exception ditangkap) | gagal → `failure` (pesan tanpa password; memuat nama koneksi/driver) |
| `database_version` | hanya `mysql`/`mariadb`: `databaseVersionSupported` (MySQL ≥ 8.0, MariaDB ≥ 10.6, deteksi MariaDB dari string versi) | tidak didukung → `warning`; driver lain atau DB gagal → `skipped` |
| `migrations` | file migrasi dari `$migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()))` dikurangi `$migrator->getRepository()->getRan()` | ada tertunda → `failure` (pesan memuat jumlah); DB gagal → `skipped` |
| `writable_paths` | `WRITABLE_PATHS` = `storage_path()`, `storage_path('app/private')`, `storage_path('app/public')`, `storage_path('framework/cache')`, `storage_path('framework/sessions')`, `storage_path('framework/views')`, `storage_path('logs')`, `base_path('bootstrap/cache')` lewat `probe->isWritable` | ada yang tidak → `failure`, pesan memuat path relatif terhadap `base_path()` |
| `assets` | `public_path('build/manifest.json')` dan direktori `public_path('js/filament')` ada | tidak ada → `failure`, pesan memuat `public/build` dan/atau `js/filament` |
| `public_storage` | lulus bila (a) `public_path('storage')` adalah symlink yang `realpath`-nya = `realpath(config('filesystems.disks.public.root'))`, atau (b) `realpath(disks.public.root)` berada di dalam `realpath(public_path())` | selain itu → `warning`, pesan menyebut `pondok:install`/`storage:link` dan `PUBLIC_STORAGE_ROOT` |
| `private_disk` | `realpath(config('filesystems.disks.private.root'))` (atau path mentah bila belum ada) **tidak** berada di dalam `realpath(public_path())` | di dalam → `failure` |
| `app_exposure` | metode statis murni `isInsideWebRoot(string $path): bool` atas `realpath(base_path())`: true bila salah satu segmen path adalah `public_html`, `www`, `htdocs`, atau `httpdocs`; tambahan: bila `$_SERVER['DOCUMENT_ROOT']` terisi (konteks web) dan `base_path()` berada di dalamnya → true | true → `warning` (aplikasi berada di folder web; `.env` dan `storage` bisa terunduh bila `.htaccess` root tidak berlaku); CLI tanpa segmen tersebut → `ok` |
| `drivers` | `queue.default === 'database'`, `cache.default` ∈ {`database`, `file`}, `session.driver` ∈ {`database`, `file`} | selain itu → `warning` dengan nama driver |
| `scheduler` | baca heartbeat; umur ≤ 5 menit → ok | berkas tidak ada → `warning` ("cron belum pernah berjalan", memuat `schedule:run`); > 5 menit → `warning` memuat waktu terakhir (format `15 Okt 2026 14:05`) |
| `queue` | `jobs` dengan `reserved_at IS NULL` dan `available_at <= now()->subMinutes(10)->timestamp` (kolom integer unix timestamp; job terjadwal di masa depan tidak dihitung; query builder portabel); jumlah `failed_jobs` | ada job tertunda > 10 menit → `warning`; `failed_jobs` > 0 → `warning` memuat jumlah; DB gagal → `skipped` |
| `php_functions` | `probe->functionEnabled('proc_open')` (dipakai `schedule:run`), `symlink` | `proc_open` mati → `warning` (rujuk bagian cron alternatif di panduan); `symlink` mati → `warning` (rujuk `PUBLIC_STORAGE_ROOT`) |
| `php_limits` | `upload_max_filesize` ≥ 5M (dokumen F013), `post_max_size` ≥ `upload_max_filesize`, `memory_limit` ≥ 128M atau `-1`; konversi K/M/G | di bawah batas → `warning` memuat nilai saat ini dan batas; catatan bahwa `php.ini` CLI bisa berbeda dari web |
| `mail` | di production `config('mail.default')` ∉ {`log`, `array`} | → `warning` (email tidak terkirim) |
| `version` | isi `base_path('VERSION')` (trim) atau `dev` | selalu `ok`, pesan memuat versi + `PHP_BINARY` |

### `App\Actions\Core\RecordSchedulerHeartbeat`

`handle(): void` — tulis `now()->timestamp` ke `storage_path('framework/pondok-scheduler-heartbeat')`. Konstanta `PATH` dipakai juga oleh `CheckSystemHealth`. Didaftarkan di `routes/console.php` sebagai **event pertama**: `Schedule::call(fn () => app(RecordSchedulerHeartbeat::class)->handle())->everyMinute()->name('pondok:scheduler-heartbeat')` (closure, bukan callable array berisi nama kelas).

### `App\Actions\Core\CreateSuperAdmin`

`handle(string $name, string $email, string $password): array{user: User, created: bool}`
- Validasi (`Validator`, pesan bahasa Indonesia): `name` wajib maks 255, `email` wajib format email maks 255, `password` wajib min 8. Gagal → `ValidationException`.
- Email sudah ada: tidak mengubah nama/password; pastikan peran `super-admin`; `created = false`.
- Email baru: buat `User` (`is_active = true`, `email_verified_at = now()`, password di-hash lewat cast model yang ada), `assignRole('super-admin')`; `created = true`.
- Peran `super-admin` harus sudah ada (seed lebih dulu); tidak ada → `RuntimeException`.

`SuperAdminSeeder` tetap melempar `RuntimeException` bila `SUPER_ADMIN_EMAIL`/`SUPER_ADMIN_PASSWORD` kosong (perilaku lama), lalu memanggil `CreateSuperAdmin`.

### `App\Actions\Core\LinkPublicStorage`

`handle(): array{status: 'linked'|'already_linked'|'direct'|'symlink_unavailable'|'conflict'|'failed', link: string, target: string, suggestion: ?string}`
- `target` = `config('filesystems.disks.public.root')`, `link` = kunci pertama `config('filesystems.links')` yang nilainya = target (bawaan `public_path('storage')`).
- Bila `realpath(target)` (setelah `mkdir` rekursif bila belum ada) berada di dalam `realpath(public_path())` → `direct`, tanpa symlink.
- `link` sudah symlink ke target → `already_linked`. `link` ada tetapi bukan symlink ke target → `conflict` (tidak ditimpa).
- `probe->functionEnabled('symlink') === false` → `symlink_unavailable`, `suggestion` = `PUBLIC_STORAGE_ROOT=<public_path()>/storage` (path absolut). Tidak memanggil `symlink`.
- Selain itu buat symlink (`File::link`), tangkap `Throwable` → `failed` + `suggestion` sama. Sukses → `linked`.

### `App\Actions\Core\PublishPublicDirectory`

`handle(string $source, string $target, string $basePath, bool $force = false): array{copied: int}`
- Praperiksa **sebelum menghapus atau menulis apa pun**; gagal → `RuntimeException` (pesan lang):
  - `source/build/manifest.json` tidak ada (paket tanpa build; aset situs hidup tidak boleh dihapus);
  - `target` tidak ada/tidak bisa ditulis;
  - `realpath(target) === realpath(source)`;
  - `realpath(target)` sama dengan `realpath(basePath)` atau merupakan induk dari `basePath` (mis. `/home/u` untuk `/home/u/pondok`);
  - `target/index.php` ada dan **tidak** memuat penanda `pondok:public-index` kecuali `$force`.
- Salin seluruh isi `source` (termasuk dotfile) ke `target`, **kecuali** `index.php`, `.htaccess`, `storage`, dan `hot`. Direktori milik Pondok (`build`, `css/filament`, `js/filament`, `fonts/filament`) di target dihapus lebih dulu agar aset lama berhash tidak menumpuk. Berkas/direktori lain di target (mis. `cgi-bin`, `.well-known`) tidak disentuh.
- `.htaccess`: isi baru = `source/.htaccess` digabung dengan blok cPanel dari `target/.htaccess` lama lewat `PreserveCpanelHtaccessBlocks::merge` (lihat di bawah); bila target belum punya `.htaccess`, hasil identik dengan sumber.
- Tulis `target/index.php` dari `resources/stubs/public-index.php.stub`: komentar penanda `pondok:public-index`, `$basePath` absolut (di-`var_export`), cek `storage/framework/maintenance.php`, `require $basePath.'/vendor/autoload.php'`, `$app = require_once $basePath.'/bootstrap/app.php'`, `$app->usePublicPath(__DIR__)`, `$app->handleRequest(Request::capture())`.

### `App\Actions\Core\PreserveCpanelHtaccessBlocks`

MultiPHP Manager / MultiPHP INI Editor cPanel menulis blok di `.htaccess` document root, misalnya:

```
# php -- BEGIN cPanel-generated handler, do not edit
<IfModule mime_module>
  AddHandler application/x-httpd-ea-php83 .php .php8 .phtml
</IfModule>
# php -- END cPanel-generated handler, do not edit
```

dan `# BEGIN cPanel-generated php ini directives, do not edit` … `# END cPanel-generated php ini directives, do not edit`. Menimpanya membuat situs jatuh ke PHP bawaan server (error 500).

- `extract(string $htaccess): list<string>` — semua blok utuh dari baris `^#.*BEGIN cPanel-generated` sampai baris `^#.*END cPanel-generated` berikutnya (termasuk kedua baris penanda). Blok tanpa penutup diabaikan.
- `merge(string $newContent, list<string> $blocks): string` — tambahkan setiap blok yang belum ada (perbandingan string blok utuh) di akhir `$newContent`, dipisah satu baris kosong; idempotent (merge dua kali tidak menggandakan).
- `remember(string $htaccessPath): int` — simpan blok dari berkas ke `storage_path('framework/pondok-cpanel-htaccess')` (ditimpa bila ada blok; tidak diubah bila berkas tidak punya blok); mengembalikan jumlah blok.
- `restore(string $htaccessPath): int` — bila berkas ada dan blok yang tersimpan belum ada di dalamnya, gabungkan (`merge`) dan tulis ulang; mengembalikan jumlah blok yang ditambahkan.
- Dipakai: `PublishPublicDirectory` (cara B, `merge` dari target lama); `pondok:install` (`remember(public_path('.htaccess'))` setelah langkah 8); `pondok:upgrade` (`restore` lalu `remember` atas `public_path('.htaccess')`, untuk cara A setelah zip baru menimpa `public/.htaccess`).

### `App\Actions\Release\BuildReleaseManifest`

`handle(string $root): list<string>` — daftar path file relatif (pemisah `/`, urut), menelusuri `root` **tanpa mengikuti symlink** dan tanpa memasukkan entri symlink. Aturan pengecualian (konstanta `EXCLUDED_PREFIXES`, `EXCLUDED_FILES`, `EXCLUDED_PATTERNS`):
- Prefiks direktori: `.git/`, `.github/`, `.claude/`, `.ai/`, `.codex/`, `.cursor/`, `.idea/`, `.vscode/`, `.zed/`, `.nova/`, `.phpunit.cache/`, `node_modules/`, `tests/`, `docs/plans/`, `docs/handoffs/`, `storage/app/releases/`, `storage/pail/`, `public/storage/`.
- Berkas tepat: `.env`, `.phpunit.result.cache`, `.phpactor.json`, `phpunit.xml`, `auth.json`, `CLAUDE.md`, `AGENTS.md`, `boost.json`, `feature_list.json`, `database/database.sqlite`, `public/hot`, `public/fonts-manifest.dev.json`, `_ide_helper.php`.
- Pola: basename `.DS_Store`/`Thumbs.db`; berakhiran `.log`; isi `storage/logs/`, `storage/framework/{cache,sessions,views,testing}/`, `storage/app/private/`, `storage/app/public/`, dan `bootstrap/cache/` **kecuali** berkas `.gitignore` di direktori tersebut (agar kerangka direktori ikut).
- Pola env: basename `.env` atau diawali `.env.` **dan tidak** berakhiran `.example` dikecualikan di direktori mana pun (`.env.production`, `.env.backup`, `.env.testing`, `.env.staging`, `.env.local`, …). `.env.example` dan `.env.production.example` tetap masuk.
- `.htaccess` di root tetap masuk (lihat wajib).

`missingRequirements(array $manifest): list<string>` — mengembalikan path wajib yang tidak ada dan path terlarang yang ada (diberi awalan teks dari lang):
- Wajib: `artisan`, `composer.json`, `composer.lock`, `.env.example`, `.env.production.example`, `.htaccess` (root, penolak akses), `VERSION`, `public/index.php`, `public/.htaccess`, `public/build/manifest.json`, `vendor/autoload.php`, minimal satu berkas di bawah `public/js/filament/`, `storage/framework/views/.gitignore`, `storage/framework/sessions/.gitignore`, `storage/framework/cache/.gitignore`, `storage/logs/.gitignore`, `bootstrap/cache/.gitignore`, `docs/instalasi.md`.
- Terlarang: prefiks `vendor/pestphp/`, `vendor/laravel/boost/`, `vendor/mockery/`, `vendor/fakerphp/`, `vendor/laravel/pint/` (bukti `--no-dev` gagal); berkas env rahasia menurut pola env di atas.

**Berkas `/.htaccess` root** (baru, di repo dan paket): `<IfModule mod_authz_core.c> Require all denied </IfModule>` + `<IfModule !mod_authz_core.c> Order deny,allow` / `Deny from all </IfModule>`, dengan komentar bahasa Indonesia bahwa document root harus `public/`. Karena berlaku turun ke subfolder, aplikasi yang salah terekstrak ke `public_html` gagal tertutup (403 di semua halaman) alih-alih membocorkan `.env`. Tidak berpengaruh di cara A/B (document root `public/` atau `public_html` terpisah) maupun di Herd/nginx.

### `App\Actions\Release\WriteReleaseArchive`

`handle(string $root, array $manifest, string $zipPath, string $prefix = 'pondok/'): array{path: string, sha256: string, files: int}`
- Butuh `ZipArchive`; tidak ada → `RuntimeException` (pesan lang).
- Hapus zip lama di path yang sama, tulis setiap entri manifest sebagai `<prefix><path>` (isi byte identik), tutup, hitung `hash_file('sha256')`, tulis `<zipPath>.sha256` berisi `<hash>  <basename zip>\n`.

### `App\Actions\Release\PrepareReleaseStaging`

`handle(string $repository, string $staging, string $version): void` — semua proses lewat facade `Process` (bisa di-fake), timeout 900 detik per langkah, berhenti pada kegagalan pertama dengan `RuntimeException` yang memuat perintah dan `errorOutput()`:
1. `git archive --format=zip --output=<staging>.src.zip HEAD` (cwd `repository`), ekstrak ke `staging` dengan `ZipArchive`, hapus zip sumber.
2. `composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress` (cwd `staging`; skrip composer tetap jalan sehingga `filament:upgrade` menerbitkan aset Filament).
3. `npm ci --no-audit --no-fund` (cwd `staging`).
4. `npm run build` (cwd `staging`).
5. Tulis `staging/VERSION` = `$version` + newline.

### Command

| Signature | Isi |
|---|---|
| `pondok:release {--release-version=} {--output=} {--allow-dirty} {--keep-staging}` | Tolak bila `app()->environment('production')`. Cek `git status --porcelain` (Process) kosong kecuali `--allow-dirty` (dengan peringatan bahwa hanya commit `HEAD` yang dipaketkan). Versi dari opsi atau `trim(git describe --tags --always)`; harus cocok `/^[0-9A-Za-z][0-9A-Za-z.+-]*$/`. Staging di `sys_get_temp_dir()/pondok-release-<random>`. Urutan: `PrepareReleaseStaging` → `BuildReleaseManifest` → `missingRequirements` (tidak kosong → exit 1, tanpa zip, cetak daftar) → `WriteReleaseArchive` ke `<output>/pondok-<version>.zip` (bawaan `storage_path('app/releases')`, dibuat bila belum ada). Hapus staging kecuali `--keep-staging`. Cetak path zip, jumlah berkas, ukuran, sha256. |
| `pondok:install {--admin-name=} {--admin-email=} {--admin-password=}` | Lihat alur di bawah. |
| `pondok:doctor` | Jalankan `CheckSystemHealth`, tampilkan tabel (Pemeriksaan, Status, Keterangan) dengan label status dari lang ("OK", "Peringatan", "Gagal", "Dilewati"). Exit 1 bila ada `failure`, selain itu 0. |
| `pondok:upgrade {--force}` | Lihat alur di bawah. |
| `pondok:publish-public {--path=} {--force}` | Target dari opsi atau `config('pondok.public_path')`; kosong → exit 1 dengan pesan cara mengisi `PONDOK_PUBLIC_PATH`. Memanggil `PublishPublicDirectory` dengan `source = base_path('public')`, `basePath = base_path()`. Exception → pesan, exit 1. |

**Alur `pondok:install`** (berhenti di langkah pertama yang gagal, exit 1, tanpa stack trace):
1. Cek awal dari `CheckSystemHealth` hanya `php_version`, `extensions`, `writable_paths`; ada `failure` → cetak, exit 1.
2. `.env` (`app()->environmentFilePath()`) tidak ada → salin `.env.production.example` ke sana, cetak instruksi mengisi `DB_*`, `APP_URL`, `SUPER_ADMIN_*`, exit 1.
3. Tentukan kredensial super-admin **sebelum mengubah database**: opsi > `config('pondok.super_admin')` (env) > prompt interaktif (nama, email, password tersembunyi + konfirmasi) bila input interaktif. Bila tidak ada kredensial dan belum ada user ber-peran `super-admin` (cek hanya bila tabel `roles` ada) → exit 1 dengan pesan. Bila super-admin sudah ada dan tidak ada kredensial → langkah 7 dilewati. Kredensial divalidasi di sini dengan aturan `CreateSuperAdmin` (gagal → exit 1).
4. `config('app.key')` kosong → `key:generate --force`; gagal menulis `.env` → exit 1.
5. Cek `database`; gagal → exit 1 dengan pesan memeriksa `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
6. `migrate --force`, lalu `db:seed --class=Database\\Seeders\\InstallSeeder --force`.
7. `CreateSuperAdmin` (bila ada kredensial). Email sudah ada → peringatan bahwa password tidak diubah.
8. Bila `config('pondok.public_path')` terisi → `PublishPublicDirectory`. Lalu `PreserveCpanelHtaccessBlocks::remember(public_path('.htaccess'))`. Lalu `LinkPublicStorage`; status `symlink_unavailable`/`failed`/`conflict` → peringatan + `suggestion` (instalasi tetap sukses).
9. `optimize:clear`.
10. Ringkasan: URL login `rtrim(config('app.url'), '/').'/admin'`, baris cron `* * * * * <PHP_BINARY> <base_path('artisan')> schedule:run >> /dev/null 2>&1`, anjuran menghapus `SUPER_ADMIN_PASSWORD` dari `.env`, anjuran menjalankan `pondok:doctor`. Exit 0.

Aman dijalankan ulang (idempotent): seeder `firstOrCreate`, permission peran bawaan disinkron, super-admin tidak diubah.

**Alur `pondok:upgrade`**:
1. Tanpa `--force`: interaktif → konfirmasi "Sudah membuat backup database dan folder storage?" (tidak → exit 1); non-interaktif → exit 1 dengan pesan memakai `--force` setelah backup. Belum ada perubahan apa pun.
2. Cek awal sama dengan install langkah 1 + `app_key` + `database`; gagal → exit 1 tanpa `down`.
3. Catat `wasDown = app()->isDownForMaintenance()`; bila belum → `down --retry=60`.
4. `migrate --force`. Gagal (exception/exit ≠ 0) → **tetap dalam mode pemeliharaan**, cetak error dan petunjuk memulihkan backup (`php artisan up` setelah selesai), exit 1.
5. `db:seed --class=Database\\Seeders\\SystemSeeder --force` (permission dan setting baru).
6. `PreserveCpanelHtaccessBlocks::restore(public_path('.htaccess'))` lalu `remember(...)`. Bila `config('pondok.public_path')` terisi → `PublishPublicDirectory` (`force = false`).
7. `optimize:clear`.
8. Bila `!wasDown` → `up`. Cetak ringkasan + anjuran `pondok:doctor`. Exit 0.

Kegagalan langkah 5, 6, atau 7 (exception/exit ≠ 0): **tetap dalam mode pemeliharaan**, cetak error dan petunjuk "perbaiki penyebabnya lalu jalankan ulang `php artisan pondok:upgrade --force` (aman diulang)", exit 1. Langkah berikutnya tidak dijalankan.

### Seeder

- `InstallSeeder`: `UnitSeeder`, `AcademicYearSeeder`, `SettingSeeder`, `RolePermissionSeeder`, `DocumentTypeSeeder`, `AdmissionTrackSeeder` (urutan sama dengan `DatabaseSeeder` saat ini, tanpa `SuperAdminSeeder`). Seeder data awal dari fitur lain (F010–F018) yang sudah ditambahkan ke `DatabaseSeeder` dipindahkan ke sini saat implementasi.
- `SystemSeeder`: `SettingSeeder`, `RolePermissionSeeder`.
- `DatabaseSeeder`: `InstallSeeder` lalu `SuperAdminSeeder`.

### Konfigurasi

- `config/pondok.php`: `'public_path' => env('PONDOK_PUBLIC_PATH')`. Bila F010 sudah menambah `trusted_proxies`, dibiarkan.
- `AppServiceProvider::register()`: bila `config('pondok.public_path')` terisi → `$this->app->usePublicPath(rtrim(..., '/'))` (berlaku untuk web dan CLI, sehingga `@vite` dan `storage:link` memakai `public_html`).
- `config/filesystems.php`: disk `public` `root` = `env('PUBLIC_STORAGE_ROOT', storage_path('app/public'))`; `links` = `[rtrim(env('PONDOK_PUBLIC_PATH') ?: public_path(), '/').'/storage' => storage_path('app/public')]` (config dimuat sebelum provider, jadi tidak bisa mengandalkan `public_path()` yang sudah diganti). Disk `private` tidak berubah.

## UI (Filament / Blade)

Tidak ada layar Filament/Blade baru. Antarmuka berupa keluaran konsol dan dokumen:
- Semua teks keluaran command lewat `__('pondok.<grup>.*')` di `lang/id/pondok.php`; sapaan "Anda", kalimat pendek, tanpa jargon yang tidak dijelaskan (design-guide §9). Tanggal di pesan `scheduler` memakai format §5 (`15 Okt 2026 14:05`).
- `docs/instalasi.md` (bahasa Indonesia, untuk operator sekolah non-IT), bagian:
  1. Kebutuhan server: PHP 8.3+ (MultiPHP Manager `ea-php83`), daftar ekstensi (sama dengan `REQUIRED_EXTENSIONS` + `pdo_mysql`), MySQL ≥ 8.0 atau MariaDB ≥ 10.6, `upload_max_filesize` ≥ 5M, `memory_limit` ≥ 128M, akses Terminal (atau Cron Jobs bila tidak ada Terminal).
  2. Membuat database & user MySQL di cPanel.
  3. Mengunggah dan mengekstrak `pondok-<versi>.zip` ke `/home/<user>/pondok` (**di luar** `public_html`). Penjelasan `.htaccess` root: bila semua halaman 403, aplikasi terekstrak di folder web.
  4. Document root: **cara A** (disarankan) arahkan domain/subdomain ke `/home/<user>/pondok/public`; **cara B** (document root tidak bisa diubah) isi `PONDOK_PUBLIC_PATH=/home/<user>/public_html` lalu `pondok:publish-public`; peringatan jangan mengekstrak seluruh aplikasi ke `public_html`.
  5. Mengisi `.env` (APP_URL harus sama persis dengan alamat situs termasuk `https://`; dipakai QR surat dan gambar portal; `TRUSTED_PROXIES` bila memakai Cloudflare/proxy; `SUPER_ADMIN_*`; mail SMTP).
  6. Menjalankan `pondok:install` (Terminal) atau lewat cron sekali jalan (`... artisan pondok:install --no-interaction >> storage/logs/install.log 2>&1`, lalu hapus cron dan hapus `SUPER_ADMIN_PASSWORD`).
  7. `storage:link` dan alternatif bila hosting mematikan `symlink` (`PUBLIC_STORAGE_ROOT`).
  8. Cron satu baris `schedule:run` setiap menit dengan path PHP 8.3 cPanel (`/opt/cpanel/ea-php83/root/usr/bin/php` atau `/usr/local/bin/ea-php83`), dan cron alternatif bila `proc_open` dimatikan.
  9. HTTPS (AutoSSL) dan pemeriksaan dengan `pondok:doctor`.
  10. Pembaruan versi: backup (cPanel Backup / phpMyAdmin + folder `storage` + `.env` + `public/.htaccess`), hapus folder kode lama (`app`, `bootstrap/cache/*.php`, `config`, `database`, `lang`, `public/build`, `resources`, `routes`, `vendor`), ekstrak zip baru, **segera** `pondok:upgrade` (mengembalikan blok handler PHP cPanel ke `public/.htaccess` yang tertimpa; bila situs masih error 500, pilih ulang PHP 8.3 di MultiPHP Manager). Catatan: upgrade mengembalikan permission peran bawaan ke definisi aplikasi; peran buatan sendiri tidak berubah.
  11. Masalah umum: error 500 `Vite manifest not found` (paket tanpa `public/build`), gambar tidak tampil (storage link / APP_URL), "Your Composer dependencies require a PHP version >= 8.3" (PHP CLI cron berbeda versi), unggahan gagal (batas PHP), tampilan masih lama setelah ubah `.env` (cache config, `optimize:clear`), error 500 setelah upgrade karena `.htaccess` kehilangan blok `cPanel-generated handler` (MultiPHP Manager), 403 di semua halaman (aplikasi terekstrak ke `public_html`).
  12. Opsional: `php artisan optimize` dan konsekuensinya.
- Panduan menyebut `.env.production.example` sebagai templat (`pondok:install` menyalinnya otomatis bila `.env` belum ada).
- `docs/rilis.md` (pengelola): prasyarat mesin build (PHP 8.3+, Composer, Node/npm, git, ext-zip), `pondok:release`, isi/tidak-isi paket, memeriksa sha256, menandai tag versi.
- `README.md`: ganti boilerplate Laravel dengan ringkasan Pondok, tautan `docs/instalasi.md`, `docs/rilis.md`, `docs/prd.md`, lisensi MIT.

## Permission & cakupan

- Tidak ada permission baru: semua fitur berupa command CLI yang hanya bisa dijalankan pemilik akun hosting.
- Tidak ada saklar modul (bagian inti). Tidak ada global scope `unit_user` yang terlibat (data sistem).
- `CreateSuperAdmin` hanya memberi peran `super-admin` (sudah ada di `RolePermissionSeeder`).

## Kriteria penerimaan (bisa dites)

Konvensi test:
- Direktori sementara unik per test (`sys_get_temp_dir()` + random), dihapus di `afterEach`. `SystemProbe` diganti fake yang semua nilainya "sehat" kecuali yang diuji.
- **Hook isolasi wajib** `isolateInstallation(): object` di `tests/Pest.php`, dipanggil di `beforeEach` **setiap** file test yang menjalankan `pondok:install`, `pondok:upgrade`, `pondok:doctor`, `pondok:publish-public`, `LinkPublicStorage`, `PreserveCpanelHtaccessBlocks`, atau `RecordSchedulerHeartbeat`. Hook:
  1. membuat `$tmp` dengan `.env` (`APP_KEY=base64:…` acak), `web/` (berisi `build/manifest.json`, `js/filament/app.js`), `storage/{app/private,app/public,framework/cache,framework/sessions,framework/views,logs}`, `bootstrap/cache`;
  2. `app()->useEnvironmentPath($tmp)`, `usePublicPath($tmp.'/web')`, `useStoragePath($tmp.'/storage')`, `useBootstrapPath($tmp.'/bootstrap')`; `config()` untuk `filesystems.disks.{local,private}.root` → `$tmp/storage/app/private`, `filesystems.disks.public.root` → `$tmp/storage/app/public`, `filesystems.links` → `[$tmp.'/web/storage' => $tmp.'/storage/app/public']`, `view.compiled` → `$tmp/storage/framework/views`;
  3. mengambil snapshot repo: ada/tidaknya dan hash `base_path('.env')`, `is_link`/`file_exists` `base_path('public/storage')`, `base_path('public/.htaccess')` hash, ada/tidaknya `base_path('storage/framework/down')` dan `base_path('storage/framework/maintenance.php')`, daftar + mtime `base_path('bootstrap/cache/*.php')`;
  4. `afterEach`: lapis kedua `Artisan::call('up')`, lalu **assert** snapshot repo tidak berubah, lalu hapus `$tmp`.
  Dengan demikian `down`, heartbeat, `optimize:clear`, `key:generate`, salinan `.env`, dan symlink semuanya terjadi di `$tmp`, juga aman untuk `pest --parallel`. Angka bawaan dibandingkan dengan konstanta seeder (`RolePermissionSeeder::ROLES`, `SettingSeeder::MODULES`, `UnitSeeder::UNITS`), bukan angka keras.

### Paket rilis

- **AC-1 (manifest mengecualikan)**: fixture root berisi `node_modules/a/index.js`, `tests/Feature/XTest.php`, `.git/HEAD`, `.github/workflows/ci.yml`, `.claude/agents/x.md`, `.ai/rules/x.md`, `.env`, `.env.production`, `.env.backup`, `.env.staging`, `.env.local`, `config/.env.testing`, `public/hot`, `public/storage/img.jpg`, `storage/logs/laravel.log`, `storage/framework/views/abc.php`, `storage/framework/sessions/s1`, `storage/framework/cache/data/x`, `storage/app/private/doc.pdf`, `storage/app/public/img.jpg`, `storage/app/releases/old.zip`, `bootstrap/cache/config.php`, `bootstrap/cache/packages.php`, `database/database.sqlite`, `docs/plans/F001-plan.md`, `docs/handoffs/x.md`, `CLAUDE.md`, `AGENTS.md`, `boost.json`, `feature_list.json`, `phpunit.xml`, `.phpunit.result.cache`, `auth.json`, `app/.DS_Store`, `error.log` → `BuildReleaseManifest::handle($root)` tidak memuat satu pun path tersebut.
- **AC-2 (manifest memuat)**: fixture yang sama juga berisi `.htaccess` (root), `.env.example`, `.env.production.example`, `artisan`, `composer.json`, `composer.lock`, `VERSION`, `app/Models/User.php`, `lang/id/pondok.php`, `public/index.php`, `public/.htaccess`, `public/build/manifest.json`, `public/build/assets/app-abc.css`, `public/js/filament/app.js`, `vendor/autoload.php`, `storage/framework/views/.gitignore`, `storage/framework/sessions/.gitignore`, `storage/framework/cache/.gitignore`, `storage/logs/.gitignore`, `storage/app/private/.gitignore`, `storage/app/public/.gitignore`, `bootstrap/cache/.gitignore`, `docs/instalasi.md`, `docs/prd.md` → semua ada di manifest; manifest terurut (`sort` SORT_STRING), memakai `/`, path relatif tanpa awalan `./`.
- **AC-3 (symlink tidak diikuti)**: fixture berisi symlink `public/storage` → direktori di luar root dan symlink `app/link.php` → berkas di luar root → tidak ada entri yang diawali `public/storage` atau sama dengan `app/link.php`, dan berkas di luar root tidak muncul.
- **AC-4 (kelengkapan)**: manifest AC-2 → `missingRequirements` = `[]`. Tanpa `public/build/manifest.json` → hasil memuat `public/build/manifest.json`. Tanpa `vendor/autoload.php` → memuat `vendor/autoload.php`. Tanpa berkas di `public/js/filament/` → memuat `public/js/filament`. Dengan tambahan `vendor/pestphp/pest/bin/pest` → memuat `vendor/pestphp/`. Tanpa `.htaccess` root → memuat `.htaccess`. Manifest buatan tangan yang memuat `.env.staging` → hasil memuat `.env.staging`. Berkas `/.htaccess` di repo ada dan memuat `Require all denied` serta `Deny from all`.
- **AC-5 (arsip)**: `WriteReleaseArchive::handle($root, $manifest, $tmp.'/pondok-1.2.0.zip')` → zip berisi tepat `count($manifest)` entri berkas, semuanya diawali `pondok/`, isi `pondok/public/build/manifest.json` identik byte dengan sumber; `sha256` = `hash_file('sha256', zip)`; berkas `pondok-1.2.0.zip.sha256` berisi `<sha256>  pondok-1.2.0.zip`. Dipanggil dua kali dengan manifest lebih kecil → zip kedua hanya berisi manifest kedua (tidak menumpuk). `files` = jumlah entri.
- **AC-6 (staging berurutan)**: `Process::fake` dengan closure yang mencatat setiap perintah + `path` (cwd); fake `git archive` membuat zip kecil di path `--output` berisi `artisan` dan `composer.json` → `PrepareReleaseStaging::handle($repo, $staging, '1.2.0')` menjalankan tepat urutan: `git archive` (cwd `$repo`, argumen memuat `HEAD`), `composer install` dengan `--no-dev`, `--optimize-autoloader`, `--no-interaction` (cwd `$staging`), `npm ci` (cwd `$staging`), `npm run build` (cwd `$staging`); `$staging/artisan` ada; `$staging/VERSION` berisi `1.2.0`; zip sumber sementara sudah dihapus.
- **AC-7 (staging gagal)**: fake `npm run build` exit 1 dengan error output "vite error" → `RuntimeException` yang pesannya memuat `npm run build` dan `vite error`; `VERSION` tidak ditulis. Fake `composer install` exit 1 → `npm ci` tidak pernah dijalankan.
- **AC-8 (command rilis end-to-end)**: `Process::fake` (`git status --porcelain` output kosong; `git archive` menulis zip fixture lengkap seperti AC-2 tanpa berkas terlarang; composer/npm sukses) → `pondok:release --release-version=1.2.0 --output=<tmp>` exit 0; `<tmp>/pondok-1.2.0.zip` dan `.sha256` ada; zip memuat `pondok/public/build/manifest.json`, `pondok/vendor/autoload.php`, `pondok/VERSION`, `pondok/.env.example`, `pondok/.env.production.example` dan tidak memuat entri yang diawali `pondok/node_modules/`, `pondok/tests/`, `pondok/.git/`, maupun `pondok/.env` tepat; keluaran memuat path zip dan tidak memuat `pondok.release.`; direktori staging sementara sudah dihapus; `git describe` tidak dijalankan.
- **AC-9 (versi bawaan)**: tanpa `--release-version`, fake `git describe --tags --always` output `"v1.3.0\n"` → zip bernama `pondok-v1.3.0.zip` dan `VERSION` di zip berisi `v1.3.0`.
- **AC-10 (penjaga command rilis)**: (a) `app()['env'] = 'production'` → exit 1, tidak ada proses yang dijalankan (`Process::assertNothingRan`). (b) `git status --porcelain` output `" M app/x.php"` tanpa `--allow-dirty` → exit 1, `git archive` tidak dijalankan; dengan `--allow-dirty` → lanjut dan keluaran memuat peringatan. (c) `--release-version=../x` → exit 1, tidak ada `git archive`. (d) fixture `git archive` tanpa `public/build/manifest.json` → exit 1, keluaran memuat `public/build/manifest.json`, tidak ada berkas `.zip` di `<tmp>`.

### Instalasi

- **AC-11 (cek awal)**: fake probe `phpVersion = '8.2.20'` → `pondok:install --admin-...` exit 1, keluaran memuat `8.2.20` dan `8.3`, `Role::count() === 0`, `Setting::count() === 0`. Fake ekstensi `intl` tidak dimuat → exit 1, keluaran memuat `intl`, tidak ada seed.
- **AC-12 (.env belum ada)**: `app()->useEnvironmentPath($tmp)` (tanpa `.env`) → exit 1; `$tmp/.env` kini ada dan isinya identik dengan `.env.production.example` (bukan `.env.example`); keluaran memuat `DB_DATABASE`; `Role::count() === 0`.
- **AC-13 (APP_KEY)**: `$tmp/.env` berisi `APP_KEY=` dan `config(['app.key' => ''])` → install dengan opsi admin exit 0 dan `$tmp/.env` memuat baris yang cocok `/^APP_KEY=base64:.+$/m`.
- **AC-14 (jalur sukses)**: env path sementara dengan `.env` ber-APP_KEY, `config('app.url') = 'https://pondok.sch.id'`, links/public root diarahkan ke direktori sementara → `pondok:install --admin-name="Admin Pondok" --admin-email=admin@pondok.sch.id --admin-password=rahasia123 --no-interaction` exit 0; semua nama di `RolePermissionSeeder::ROLES` ada sebagai peran; setiap `modules.<m>.enabled` untuk `SettingSeeder::MODULES` ada; setiap `code` di `UnitSeeder::UNITS` ada; tahun ajaran aktif ada; user `admin@pondok.sch.id` ber-peran `super-admin`, `is_active` true, `email_verified_at` terisi, `Hash::check('rahasia123', password)` true; keluaran memuat `https://pondok.sch.id/admin`, `schedule:run`, `base_path('artisan')`, `pondok:doctor`, `SUPER_ADMIN_PASSWORD`, dan tidak memuat `pondok.install.`.
- **AC-15 (kredensial dari env & prioritas)**: tanpa opsi, `config(['pondok.super_admin' => ['name' => 'Env Admin', 'email' => 'env@pondok.sch.id', 'password' => 'rahasia123']])` → user `env@pondok.sch.id` dibuat. Dengan opsi `--admin-email=opsi@pondok.sch.id --admin-password=rahasia456 --admin-name=Opsi` dan env terisi → hanya `opsi@pondok.sch.id` yang dibuat (tidak ada user `env@...`).
- **AC-16 (prompt interaktif)**: tanpa opsi dan env kosong → test memakai `expectsQuestion` untuk nama, email, password, konfirmasi → user dibuat dan exit 0. Konfirmasi password berbeda → exit 1, tidak ada user, `Role::count() === 0`.
- **AC-17 (gagal sebelum mengubah DB)**: tanpa opsi, env kosong, `--no-interaction`, belum ada super-admin → exit 1, keluaran memuat `SUPER_ADMIN_EMAIL`, `Role::count() === 0`, `User::count() === 0`. Email `bukan-email` atau password `1234567` (7 karakter) → exit 1, tidak ada user dan tidak ada seed.
- **AC-18 (idempotent)**: install dua kali dengan opsi yang sama; di antara keduanya ubah setting `modules.konten.enabled` menjadi `true` dan ubah password user lewat model → setelah run kedua: jumlah baris `roles`, `permissions`, `settings`, `units`, `academic_years`, `document_types`, `admission_tracks`, `users` sama dengan setelah run pertama; `modules.konten.enabled` tetap `true`; password tetap password yang diubah. Run ketiga tanpa opsi/env dan `--no-interaction` → exit 0 (super-admin sudah ada), keluaran memuat pesan langkah super-admin dilewati.
- **AC-19 (email sudah ada)**: user `guru@pondok.sch.id` (peran `admin-sekolah`, password `lamaaaaa1`) sudah ada → install dengan `--admin-email=guru@pondok.sch.id --admin-password=baru12345` exit 0; user punya peran `super-admin` (peran lama tetap), password tetap `lamaaaaa1`; keluaran memuat peringatan password tidak diubah. `CreateSuperAdmin::handle` mengembalikan `created = false` untuk kasus ini dan `true` untuk email baru.
- **AC-20 (DB tidak terjangkau)**: `config(['database.default' => 'broken', 'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent-dir/x.sqlite', 'prefix' => '']])` → install exit 1, keluaran memuat `DB_DATABASE`, tidak memuat `PDOException` maupun stack trace (`#0 `).
- **AC-21 (seeder)**: `seed(InstallSeeder)` → `User::count() === 0`; peran = `RolePermissionSeeder::ROLES`, unit = `UnitSeeder::UNITS`, setting modul = `SettingSeeder::MODULES`, dan jumlah `permissions`/`academic_years`/`document_types`/`admission_tracks` > 0; database baru lain dengan `seed(DatabaseSeeder)` (env super-admin terisi) → jumlah tabel-tabel itu sama dengan hasil `InstallSeeder` dan ada tepat 1 user super-admin. `SuperAdminSeeder` dengan env kosong tetap melempar `RuntimeException` (test lama tetap hijau). `SystemSeeder` setelah satu permission (`view-dashboard`) dan setting `modules.tahfidz.enabled` dihapus → keduanya ada lagi; setting lain yang diubah tidak ditimpa.

### Storage link & public_html

- **AC-22 (symlink)**: `config(['filesystems.disks.public.root' => $tmp.'/app-public', 'filesystems.links' => [$tmp.'/web/storage' => $tmp.'/app-public']])`, `app()->usePublicPath($tmp.'/web')` → `LinkPublicStorage::handle()` status `linked`, `is_link($tmp.'/web/storage')` dan `realpath` = `realpath($tmp.'/app-public')`; panggilan kedua → `already_linked`.
- **AC-23 (symlink dimatikan)**: kondisi AC-22, fake `functionEnabled('symlink') = false` → status `symlink_unavailable`, `$tmp.'/web/storage'` tidak ada, `suggestion === 'PUBLIC_STORAGE_ROOT='.$tmp.'/web/storage'`. `pondok:install` dalam kondisi ini exit 0 dan keluaran memuat suggestion tersebut.
- **AC-24 (root langsung di public)**: `filesystems.disks.public.root` = `$tmp.'/web/storage'` (belum ada) → status `direct`, direktori dibuat, tidak ada symlink. Path link sudah berupa berkas biasa dan root bukan di public → `conflict`, berkas tidak berubah.
- **AC-25 (salin public)**: fixture source berisi `index.php`, `.htaccess`, `favicon.ico`, `robots.txt`, `hot`, `storage/x.jpg`, `build/manifest.json`, `build/assets/app-new.js`, `js/filament/app.js`; target berisi `cgi-bin/keep.sh`, `.well-known/acme`, `build/assets/app-old.js` dan `index.php` ber-penanda → `PublishPublicDirectory::handle($src, $target, '/home/u/pondok')`: `favicon.ico`, `robots.txt`, `build/manifest.json`, `build/assets/app-new.js`, `js/filament/app.js` tersalin identik; target tanpa `.htaccess` sebelumnya → `.htaccess` identik dengan sumber; `build/assets/app-old.js` hilang; `cgi-bin/keep.sh` dan `.well-known/acme` tetap; `hot` dan `storage/` tidak tersalin; `index.php` target memuat `pondok:public-index`, `'/home/u/pondok'`, `usePublicPath(__DIR__)`, `/vendor/autoload.php`, `/bootstrap/app.php`; `Process::run([PHP_BINARY, '-l', $target.'/index.php'])` sukses (Process asli, tidak di-fake).
- **AC-25a (blok cPanel dipertahankan)**: target `.htaccess` lama berisi aturan lama + blok `# php -- BEGIN cPanel-generated handler, do not edit` … `AddHandler application/x-httpd-ea-php83 .php .php8 .phtml` … `# php -- END cPanel-generated handler, do not edit` dan blok `# BEGIN cPanel-generated php ini directives, do not edit` … `# END …` → setelah publish, `.htaccess` target memuat seluruh isi `.htaccess` sumber (mis. `RewriteRule ^ index.php [L]`), kedua blok utuh tepat satu kali, dan tidak memuat aturan lama di luar blok. Publish kedua kali → setiap blok tetap tepat satu kali.
- **AC-25b (simpan & pulihkan blok, cara A)**: `$tmp/web/.htaccess` berisi blok handler → `remember()` mengembalikan 1 dan `storage_path('framework/pondok-cpanel-htaccess')` (di `$tmp`) memuat blok; `.htaccess` ditimpa dengan isi Laravel polos → `restore()` mengembalikan 1 dan berkas memuat blok tepat satu kali; `restore()` kedua → 0, isi tidak berubah. `.htaccess` tanpa blok → `remember()` 0 dan simpanan lama tidak terhapus. `extract()` dengan blok BEGIN tanpa END → `[]`.
- **AC-26 (penolakan salin public)**: setiap kasus berikut melempar `RuntimeException` dan target tidak berubah sama sekali (daftar berkas + isi sama seperti sebelum dipanggil, termasuk `build/assets/app-old.js`): target berisi `index.php` tanpa penanda (dengan `force: true` → berhasil); target tidak ada; target = source; source tanpa `build/manifest.json`; target = `basePath`; target = induk `basePath` (`$tmp` untuk `basePath` `$tmp.'/pondok'`).
- **AC-27 (public path aplikasi)**: `config(['pondok.public_path' => $tmp.'/web/'])` lalu `(new AppServiceProvider(app()))->register()` → `public_path() === $tmp.'/web'` dan `public_path('build/manifest.json') === $tmp.'/web/build/manifest.json'`. Tanpa konfigurasi → `public_path()` tetap `base_path('public')`.
- **AC-28 (command publish-public)**: `config(['pondok.public_path' => null])` tanpa `--path` → exit 1, keluaran memuat `PONDOK_PUBLIC_PATH`. `--path=<tmp tanpa folder>` → exit 1 dengan pesan, tanpa exception.

### Cek kesehatan

- **AC-29 (semua sehat)**: fake probe sehat (PHP `8.3.15`, semua ekstensi, semua writable, fungsi aktif, `upload_max_filesize=8M`, `post_max_size=16M`, `memory_limit=256M`), `app()->usePublicPath($tmp.'/web')` berisi `build/manifest.json` dan `js/filament/app.js`, storage link valid ke disk public sementara, `app()['env']='production'`, `app.debug=false`, `app.url='https://pondok.sch.id'`, `mail.default='smtp'`, heartbeat 1 menit lalu, tidak ada job → `CheckSystemHealth::handle()` semua `ok` kecuali `database_version` `skipped` (SQLite); key mengikuti urutan tabel; `pondok:doctor` exit 0, keluaran memuat label "OK" dari lang dan tidak memuat `pondok.doctor.`.
- **AC-30 (PHP & ekstensi)**: PHP `8.2.20` → `php_version` `failure`, `pondok:doctor` exit 1. Ekstensi `intl` dan `zip` hilang → `extensions` `failure` dengan pesan memuat `intl, zip`.
- **AC-31 (daftar ekstensi lengkap)**: setiap kunci `ext-*` pada `require` paket di `composer.lock` bagian `packages` (bukan `packages-dev`) tanpa awalan `ext-` ada di `CheckSystemHealth::REQUIRED_EXTENSIONS`. Driver `mysql` → `pdo_mysql` diperiksa; `sqlite` → `pdo_sqlite`; `pgsql` → `pdo_pgsql` (diuji dengan fake yang hanya menolak driver tersebut).
- **AC-32 (konfigurasi aplikasi)**: `app.key=''` → `app_key` `failure`. Production + `app.debug=true` → `app_env` `warning`; env `local` → `app_env` `warning`. `app_url`: `not a url` → `failure`; production `http://localhost` → `warning`; production `https://pondok.test` → `warning`; production `http://pondok.sch.id` → `warning`; production `https://pondok.sch.id` → `ok`; env `local` dengan `http://localhost` → `ok`.
- **AC-33 (database & migrasi)**: hapus satu baris terakhir tabel `migrations` → `migrations` `failure` dengan pesan memuat `1`. Koneksi rusak (seperti AC-20) → `database` `failure`, `migrations` dan `queue` `skipped`, `database_version` `skipped`, doctor exit 1 tanpa stack trace.
- **AC-34 (versi DB)**: `databaseVersionSupported('mysql', '8.0.36')` true; `('mysql', '5.7.44-log')` false; `('mysql', '10.11.6-MariaDB-log')` true; `('mariadb', '10.4.32-MariaDB')` false; `('mysql', '10.6.0-MariaDB')` true.
- **AC-35 (folder & aset)**: fake `isWritable(storage_path('framework/sessions')) = false` → `writable_paths` `failure`, pesan memuat `storage/framework/sessions`. Tanpa `build/manifest.json` → `assets` `failure` memuat `public/build`; tanpa `js/filament` → `assets` `failure` memuat `js/filament`.
- **AC-36 (disk)**: tanpa symlink dan root disk public di luar public path → `public_storage` `warning` memuat `PUBLIC_STORAGE_ROOT`. `filesystems.disks.private.root = $tmp.'/web/private'` → `private_disk` `failure`.
- **AC-36a (paparan aplikasi)**: `CheckSystemHealth::isInsideWebRoot('/home/u/public_html/pondok')` true; `('/home/u/www/pondok')` true; `('/home/u/pondok')` false; `('/home/public_htmlx/pondok')` false. Di test (base path repo tanpa segmen tersebut, tanpa `DOCUMENT_ROOT`) → `app_exposure` `ok`.
- **AC-37 (driver)**: `queue.default='redis'` → `drivers` `warning` memuat `redis`; `cache.default='file'`, `session.driver='database'`, queue `database` → `ok`.
- **AC-38 (scheduler)**: heartbeat tidak ada → `scheduler` `warning` memuat `schedule:run`; `travelTo` sehingga heartbeat berumur 10 menit → `warning`; 2 menit → `ok`. `RecordSchedulerHeartbeat::handle()` menulis `now()->timestamp`. `Schedule` (dari `routes/console.php`): event **pertama** bernama/deskripsi `pondok:scheduler-heartbeat`, ekspresi `* * * * *`; event `queue:work --stop-when-empty` dan `keuangan:generate-invoices` tetap terdaftar.
- **AC-39 (antrean)**: sisipkan baris `jobs` dengan integer `available_at` = `created_at` = `now()->subMinutes(15)->timestamp`, `reserved_at` NULL → `queue` `warning`; job dengan `available_at` = `now()->addHour()->timestamp` (terjadwal) dan `created_at` 15 menit lalu → tidak memicu (`ok`); job ber-`reserved_at` terisi 15 menit lalu → tidak memicu; 2 baris `failed_jobs` → `warning` memuat `2`; job 1 menit lalu dan tanpa failed → `ok`.
- **AC-40 (fungsi & batas PHP)**: fake `proc_open` dimatikan → `php_functions` `warning` memuat `proc_open`; `symlink` dimatikan → `warning` memuat `symlink`. `SystemProbe::functionEnabled` (asli) mengembalikan false bila nama ada di `disable_functions` (diuji dengan subclass yang mengganti sumber nilai ini, atau dengan `ini_get` asli bila kosong → `functionEnabled('strlen') === true`). `upload_max_filesize=2M` → `php_limits` `warning` memuat `2M` dan `5M`; `memory_limit=-1` → tidak memicu; `memory_limit=64M` → `warning`; `post_max_size=4M` dengan upload `8M` → `warning`.
- **AC-41 (mail & versi)**: production + `mail.default='log'` → `mail` `warning`. Tanpa berkas `VERSION` → `version` `ok`, pesan memuat `dev`.

### Pembaruan

Semua test upgrade memakai hook `isolateInstallation()` (storage path di `$tmp`, sehingga berkas `down`/`maintenance.php` tidak menyentuh repo); `afterEach` `up` hanya lapis kedua.

- **AC-42 (konfirmasi backup)**: `pondok:upgrade --no-interaction` tanpa `--force` → exit 1, keluaran memuat `--force`, `app()->isDownForMaintenance()` false, `SystemSeeder` tidak berjalan (permission yang dihapus sebelumnya tetap tidak ada). Interaktif, jawab "tidak" pada konfirmasi → exit 1, tanpa perubahan.
- **AC-43 (jalur sukses)**: setelah `seed(InstallSeeder)`, hapus permission `view-dashboard` dan setting `modules.tahfidz.enabled`, ubah `modules.konten.enabled` → `true` → `pondok:upgrade --force` exit 0; permission dan setting yang dihapus ada lagi, peran `super-admin` punya `view-dashboard`, `modules.konten.enabled` tetap `true`; `app()->isDownForMaintenance()` false; keluaran memuat `pondok:doctor`, tidak memuat `pondok.upgrade.`.
- **AC-44 (sudah down)**: `Artisan::call('down')` sebelum upgrade → setelah `pondok:upgrade --force` sukses, aplikasi **tetap** down.
- **AC-45 (migrasi gagal)**: listener `Illuminate\Database\Events\MigrationsStarted` dan `NoPendingMigrations` melempar `RuntimeException('boom')` → exit 1, aplikasi dalam mode pemeliharaan, keluaran memuat `boom` dan `php artisan up`, `SystemSeeder` tidak berjalan.
- **AC-46 (public_html saat upgrade)**: `config(['pondok.public_path' => $tmp])` dan `PublishPublicDirectory` di-mock → `handle` dipanggil sekali dengan target `$tmp` dan `force = false`. Tanpa konfigurasi → tidak dipanggil. (Juga berlaku untuk `pondok:install`: dipanggil sekali bila terkonfigurasi.)
- **AC-47 (cek awal upgrade)**: fake PHP `8.2.20` → exit 1 dan aplikasi tidak pernah down.
- **AC-47a (gagal setelah migrasi)**: `SystemSeeder` dibuat gagal (mis. listener/mock yang melempar `RuntimeException('seed gagal')` saat seeder dipanggil, atau `PublishPublicDirectory` di-mock melempar dengan `pondok.public_path` terisi) → exit 1, aplikasi tetap down, keluaran memuat `seed gagal` dan `pondok:upgrade --force`; `optimize:clear` tidak dijalankan (berkas penanda di `$tmp/bootstrap/cache` tetap ada).
- **AC-47b (blok cPanel saat upgrade)**: simpanan blok handler sudah ada (dari `remember`) dan `$tmp/web/.htaccess` berisi aturan Laravel polos (zip baru menimpa) → setelah `pondok:upgrade --force`, `.htaccess` memuat blok handler tepat satu kali.

### Konfigurasi & dokumen

- **AC-48 (.env.production.example)**: `Dotenv\Dotenv::parse(file_get_contents(base_path('.env.production.example')))` → `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY=''`, `APP_URL` diawali `https://`, `APP_LOCALE=id`, `LOG_LEVEL=error`, `DB_CONNECTION=mysql`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`, `FILESYSTEM_DISK=local`, `SUPER_ADMIN_PASSWORD=''`; teks berkas memuat `TRUSTED_PROXIES`, `PONDOK_PUBLIC_PATH`, `PUBLIC_STORAGE_ROOT` (boleh sebagai komentar); tidak ada kunci yang diawali `REDIS_`. Setiap kunci di `.env.example` juga ada di `.env.production.example` (tidak ada yang tertinggal). `.env.example` tidak berubah nilainya: `APP_ENV=local`, `APP_DEBUG=true`. `.gitignore` tidak mengabaikan `.env.production.example` (`git check-ignore` lewat Process asli exit ≠ 0, atau baris `.gitignore` tidak mencocokkan nama itu).
- **AC-49 (dokumen)**: `docs/instalasi.md` ada dan memuat: `pondok:install`, `pondok:doctor`, `pondok:upgrade`, `pondok:publish-public`, `schedule:run`, `storage:link`, `PUBLIC_STORAGE_ROOT`, `PONDOK_PUBLIC_PATH`, `APP_URL`, `TRUSTED_PROXIES`, `public/build`, `ea-php83`, `8.3`, `MySQL`, `MariaDB`, `public_html`, `backup` (case-insensitive), `proc_open`, `MultiPHP`, `cPanel-generated handler`, `.htaccess`, `403`, `peran bawaan`. Setiap nama di `CheckSystemHealth::REQUIRED_EXTENSIONS` muncul di `docs/instalasi.md`. `docs/rilis.md` memuat `pondok:release` dan `--no-dev`. `README.md` memuat tautan `docs/instalasi.md` dan tidak memuat `About Laravel`. `.gitignore` memuat baris `/VERSION`.

### Verifikasi manual (bukan Pest; wajib sebelum `done`)

- **AC-50 (asap paket nyata, PHP 8.3 + MySQL)**: dijalankan orkestrator di mesin dev, **tanpa Docker**; MySQL memakai layanan yang disediakan Herd (sudah berjalan, tidak menyalakan layanan baru); cek RAM dan port sebelum menyalakan server sesuai aturan global.
  1. `php artisan pondok:release --release-version=0.0.0-smoke` → zip dan `.sha256` ada; `unzip -l` **tidak** memuat `pondok/.env` (tepat), `pondok/.git/`, `pondok/tests/`, `pondok/node_modules/`, `pondok/vendor/pestphp/`, `pondok/vendor/laravel/boost/`; **memuat** `pondok/.htaccess`, `pondok/.env.production.example`, `pondok/public/build/manifest.json`, `pondok/public/js/filament/`, `pondok/vendor/autoload.php`.
  2. Ekstrak ke direktori sementara **di luar repo** (mis. scratchpad `smoke/`), buat database MySQL kosong `pondok_smoke`.
  3. `php83 artisan pondok:install --no-interaction` tanpa `.env` → exit 1, `.env` tersalin identik dari `.env.production.example`.
  4. Isi `DB_*`, `APP_URL=http://127.0.0.1:<port>`, `SUPER_ADMIN_*` (biarkan `APP_ENV=production`, `APP_DEBUG=false`), ulangi install → exit 0.
  5. Install kedua → exit 0, jumlah peran/setting/user tidak bertambah.
  6. `php83 artisan pondok:upgrade --force` → exit 0, aplikasi tidak down.
  7. `php83 artisan schedule:run` lalu `php83 artisan pondok:doctor` → tanpa `failure` (boleh `warning` untuk `app_url` http/localhost dan `mail`), `scheduler` `ok`.
  8. Cara A: `php83 -S 127.0.0.1:<port> -t public` → `GET /admin/login` 200, satu aset `/build/assets/*` 200, `GET /.env` bukan 200; matikan server (catat PID).
  9. Cara B: isi `PONDOK_PUBLIC_PATH=<smoke>/public_html` (folder baru berisi `.htaccess` dengan blok handler tiruan), `php83 artisan pondok:publish-public` → exit 0, blok tetap ada; `php83 -S 127.0.0.1:<port> -t <smoke>/public_html` → `/admin/login` 200 dan aset 200; matikan server.
  10. Bersihkan: hapus database `pondok_smoke` dan folder smoke; pastikan tidak ada server PHP yang tertinggal (`lsof -i :<port>`). Layanan MySQL Herd dibiarkan dalam keadaan semula.
  Hasil (perintah, exit code, status HTTP) dicatat di `evidence`. Uji di cPanel sungguhan oleh user (pertanyaan 8).

## Rencana test

| AC | File | Nama test (usulan) |
|---|---|---|
| AC-1–AC-4 | `tests/Feature/Actions/Release/BuildReleaseManifestTest.php` | `it excludes development and runtime files`, `it includes runtime files and storage skeleton`, `it does not follow symlinks`, `it reports missing and forbidden paths` |
| AC-5 | `tests/Feature/Actions/Release/WriteReleaseArchiveTest.php` | `it writes a prefixed zip with checksum`, `it replaces an existing archive` |
| AC-6, AC-7 | `tests/Feature/Actions/Release/PrepareReleaseStagingTest.php` | `it runs build steps in order`, `it stops at the first failing step` |
| AC-8–AC-10 | `tests/Feature/Console/ReleaseCommandTest.php` | `it builds a release zip end to end`, `it uses git describe for the default version`, `it refuses in production`, `it refuses a dirty tree`, `it rejects an unsafe version`, `it refuses an incomplete package` |
| AC-11–AC-20, AC-23 (command), AC-46 (install) | `tests/Feature/Console/InstallCommandTest.php` | `it stops when php is too old`, `it stops when an extension is missing`, `it creates env from the example`, `it generates the app key`, `it installs and creates the super admin`, `it reads credentials from env`, `it prompts for credentials`, `it fails before touching the database without credentials`, `it rejects invalid credentials`, `it is idempotent`, `it promotes an existing user without changing the password`, `it reports an unreachable database`, `it warns when symlink is disabled`, `it publishes public html when configured` |
| AC-19 (Action), AC-21 | `tests/Feature/Actions/Core/CreateSuperAdminTest.php`, `tests/Feature/Database/Seeders/InstallSeederTest.php` | `it creates or promotes a super admin`, `install seeder matches database seeder without users`, `system seeder restores permissions and settings` |
| AC-22–AC-24 | `tests/Feature/Actions/Core/LinkPublicStorageTest.php` | `it links public storage`, `it reports disabled symlink`, `it supports a direct public root`, `it does not overwrite a conflicting path` |
| AC-25, AC-25a, AC-26 | `tests/Feature/Actions/Core/PublishPublicDirectoryTest.php` | `it copies public assets and writes index`, `it keeps cpanel htaccess blocks`, `it refuses unsafe targets without touching them` |
| AC-25b | `tests/Feature/Actions/Core/PreserveCpanelHtaccessBlocksTest.php` | `it remembers and restores cpanel blocks`, `it ignores unterminated blocks` |
| AC-27 | `tests/Feature/Providers/PublicPathTest.php` | `it uses the configured public path` |
| AC-28 | `tests/Feature/Console/PublishPublicCommandTest.php` | `it requires a target path`, `it reports a missing target` |
| AC-29–AC-41 | `tests/Feature/Actions/Core/CheckSystemHealthTest.php`, `tests/Feature/Console/DoctorCommandTest.php`, `tests/Feature/Console/SchedulerHeartbeatTest.php` | satu `it` per baris AC (mis. `it passes a healthy server`, `it fails on old php`, `it requires every composer extension`, `it validates app url`, `it detects pending migrations`, `it compares database versions`, `it checks writable paths`, `it checks built assets`, `it checks storage disks`, `it detects an app inside the web root`, `it checks drivers`, `it checks the scheduler heartbeat`, `it checks the queue`, `it checks php functions and limits`, `it checks mail and version`) |
| AC-42–AC-47b | `tests/Feature/Console/UpgradeCommandTest.php` | `it requires a backup confirmation`, `it upgrades and restores system data`, `it keeps maintenance mode when already down`, `it stays down when migrations fail`, `it republishes public html`, `it checks requirements before going down`, `it stays down when a later step fails`, `it restores the cpanel php handler` |
| AC-48, AC-49 | `tests/Feature/ReleaseDocumentationTest.php` | `production env example is complete`, `installation guide covers cpanel`, `readme and gitignore are updated` |

Fake bersama: `tests/Fixtures/FakeSystemProbe.php` (subclass `SystemProbe` dengan properti publik yang bisa diatur). Helper fixture direktori rilis boleh ditambahkan di `tests/Pest.php` (`releaseFixture(string $root, array $paths)`). Hook `isolateInstallation()` wajib (lihat Konvensi test) dan ditulis red-tester di langkah RED pertama.

## Urutan implementasi

1. `SystemProbe` + `CheckSystemHealth` (semua cek) + teks `pondok.doctor.*` (AC-29–AC-37, AC-39–AC-41).
2. `RecordSchedulerHeartbeat` + event pertama di `routes/console.php` + cek `scheduler` (AC-38).
3. Command `pondok:doctor` (AC-29, AC-30, AC-33).
4. `CreateSuperAdmin`, refactor `SuperAdminSeeder`, `InstallSeeder`, `SystemSeeder`, `DatabaseSeeder` (AC-19, AC-21).
5. Konfigurasi public path: `config/pondok.php`, `config/filesystems.php`, `AppServiceProvider` (AC-27).
6. `LinkPublicStorage` (AC-22–AC-24).
7. `PreserveCpanelHtaccessBlocks`, stub + `PublishPublicDirectory` + command `pondok:publish-public` (AC-25–AC-26, AC-28).
8. Command `pondok:install` + teks `pondok.install.*` (AC-11–AC-20, AC-23, AC-46).
9. Command `pondok:upgrade` + teks `pondok.upgrade.*` (AC-42–AC-47b).
10. `BuildReleaseManifest` (AC-1–AC-4).
11. `WriteReleaseArchive` (AC-5).
12. `PrepareReleaseStaging` (AC-6, AC-7).
13. Command `pondok:release` + teks `pondok.release.*` + `.gitignore` `/VERSION` + `/.htaccess` root (AC-4, AC-8–AC-10).
14. `.env.production.example` (AC-48); `.env.example` tidak diubah.
15. `docs/instalasi.md`, `docs/rilis.md`, `README.md` (AC-49).
16. Pint, seluruh test terkait, verifikasi manual AC-50.

## Risiko & pertanyaan terbuka

Risiko:
1. **Dependensi belum `done`.** F012, F014, F015, F017 masih `todo`, F018 `in-progress`. Kode F019 sebagian besar independen, tetapi isi paket, daftar seeder awal, dan panduan (QR, unggahan, portal) baru final setelah fitur-fitur itu selesai. RED/GREEN F019 sebaiknya dimulai setelah semua `depends_on` `done`; bila dikerjakan lebih dulu, green-coder harus memindahkan seeder baru dari `DatabaseSeeder` ke `InstallSeeder` dan memperbarui panduan.
2. **`composer install` di staging menjalankan skrip** (`package:discover`, `filament:upgrade`) tanpa `.env`. Bila suatu saat butuh DB/APP_KEY, rilis gagal; tertangkap oleh AC-7 (pesan jelas) dan AC-50.
3. **`RolePermissionSeeder::syncPermissions` saat upgrade** mengembalikan permission peran bawaan ke definisi kode. Aman karena belum ada UI pengelola peran; peran kustom tidak tersentuh. Bila kelak ada UI peran (Shield), perlu ditinjau. Panduan menyebut bahwa upgrade mengembalikan permission peran bawaan.
4. **`AcademicYearSeeder` mengunci "2026/2027"**: instalasi di tahun berikutnya mendapat tahun ajaran lama yang aktif. Di luar lingkup; operator mengubah lewat panel (dicatat di panduan).
5. **PHP CLI vs web berbeda versi/`php.ini`** di cPanel: doctor membaca nilai CLI. Panduan menjelaskan path `ea-php83` dan bahwa batas unggah web diatur di MultiPHP INI Editor.
6. **`proc_open` dimatikan** membuat `schedule:run` gagal menjalankan command. Doctor memberi peringatan; panduan memberi cron alternatif per command (`queue:work --stop-when-empty --max-time=50` setiap menit, `keuangan:generate-invoices` tanggal 1).
7. **Test mode pemeliharaan**: `down` menulis `storage/framework/down` dan `storage/framework/maintenance.php`. Mitigasi utama hook `isolateInstallation()` (`useStoragePath($tmp)`), lapis kedua `afterEach` `up`, plus assert snapshot repo.
8. **Ukuran zip** (vendor ribuan berkas) bisa melewati batas unggah File Manager sebagian host; panduan menyarankan unggah lewat File Manager (biasanya tanpa batas ketat) atau FTP.
9. **Blok `.htaccess` cPanel di cara A** baru dipulihkan saat `pondok:upgrade` dijalankan; antara ekstrak dan upgrade situs bisa error 500 (sudah dalam jendela pemeliharaan operator). Panduan meminta upgrade segera atau pilih ulang PHP di MultiPHP Manager. Blok baru hanya dikenali bila `pondok:install`/`upgrade` pernah melihatnya (`remember`).
10. **`app_exposure` berbasis nama folder** (heuristik); tidak mendeteksi document root dengan nama lain di CLI. Pertahanan utama tetap `.htaccess` root.

Pertanyaan terbuka (dengan default):
1. **Mulai implementasi sebelum dependensi `done`?** *Default:* tidak; rencana disiapkan sekarang, RED dimulai setelah F012, F014, F015, F017, F018 `done`.
2. **Berkas env produksi.** *Diputuskan orkestrator (revisi 2):* `.env.example` tetap untuk pengembang; `.env.production.example` baru berisi nilai produksi, disalin `pondok:install` bila `.env` belum ada dan ikut paket rilis.
3. **Command rilis di dalam aplikasi** (`pondok:release`, ikut terpaket tetapi ditolak di production) vs skrip `bin/release` (folder baru, butuh persetujuan). *Default:* artisan command.
4. **Cache konfigurasi/route otomatis** saat install/upgrade. *Default:* tidak; hanya `optimize:clear`. `php artisan optimize` didokumentasikan opsional karena `.env` yang diubah lewat File Manager tidak terbaca selama config di-cache.
5. **Backup otomatis saat upgrade.** *Default:* tidak; konfirmasi + panduan backup cPanel/phpMyAdmin. Usulan entri baru bila diinginkan.
6. **Struktur zip** berakar `pondok/`. *Default:* ya (ekstrak di home menghasilkan `/home/<user>/pondok`, upgrade mengekstrak di tempat yang sama).
7. **Versi DB minimum** MySQL 8.0 / MariaDB 10.6 hanya peringatan, bukan penolakan. *Default:* ya.
8. **Bukti "terpasang di cPanel"** untuk menandai `done`. *Default:* AC-50 (asap lokal PHP 8.3 + MySQL dari zip) wajib; uji di hosting cPanel sungguhan dilakukan user dan dicatat di `evidence` bila tersedia. Bila user tidak punya akses cPanel, `done` cukup dengan AC-50 atas persetujuan user.
9. **`public_html` lewat env `PONDOK_PUBLIC_PATH`** (bukan berkas penanda di `bootstrap/`). *Default:* env, agar terlihat di `.env` dan tidak perlu menulis ke folder kode saat test.
10. **Ekstensi `gd`/`curl`** tidak diwajibkan (QR memakai SVG, resize gambar di browser, gateway pembayaran Fase 2). *Default:* tidak dicek.

## Riwayat revisi

- 2026-10-16 — DRAFT 1 (product-manager): rencana awal dari entri F019, catatan F010/F014/F016/F017, PRD §1.3, §4, §5.7, dan CLAUDE.md "Batasan deployment".
- 2026-10-16 — DRAFT 2 (product-manager, keputusan orkestrator): Q2 diubah — `.env.example` tidak diubah (tetap untuk pengembang); tambah `.env.production.example` (nilai produksi) yang disalin `pondok:install` saat `.env` belum ada, wajib ada di manifest/paket rilis. Diperbarui: ringkasan, ruang lingkup, `BuildReleaseManifest` (wajib), alur install langkah 2, AC-2, AC-8, AC-12, AC-48, panduan, urutan langkah 14, pertanyaan 2. Default pertanyaan lain (1, 3–10) diterima; usulan entri baru tidak ditambahkan.
- 2026-10-16 — DRAFT 3 (product-manager, Review #1): #1 Action `PreserveCpanelHtaccessBlocks` (extract/merge/remember/restore blok `cPanel-generated`), `PublishPublicDirectory` menggabungkan `.htaccess`, install `remember`, upgrade `restore`; AC-25 diubah, AC-25a, AC-25b, AC-47b baru; panduan langkah 10 dan masalah umum. #2 hook wajib `isolateInstallation()` (env/public/storage/bootstrap path + config disk/links/view ke `$tmp`, snapshot repo di-assert di `afterEach`, `up` lapis kedua). #3 `/.htaccess` root penolak (wajib di `missingRequirements`, AC-2/AC-4), cek doctor `app_exposure` + AC-36a. #4 AC-50 ditulis ulang (zip nyata, alur `.env.production.example`, install kedua, upgrade, cara B, `APP_ENV=production`, MySQL Herd tanpa Docker, bersih-bersih server). Minor: #5 closure `Schedule::call`; #6 cek antrean pakai `available_at` integer + AC-39 diperluas; #7 kegagalan langkah 5–7 upgrade tetap down + AC-47a; #8 praperiksa sumber `build/manifest.json` dan penjaga target = basePath/induknya (AC-26); #9 pola `.env.*` kecuali `.example` (AC-1/AC-4); #10 AC-14/AC-21 memakai konstanta seeder; #11 `$migrator->paths()`, risiko 7 dikoreksi (`down` + `maintenance.php`); #12 catatan `syncPermissions` di panduan dan risiko 3.

## Review #1 — 2026-10-16

Verdict: CHANGES_REQUESTED

Ringkasan: kerangka sudah kuat (manifest allow/deny, symlink tidak diikuti, idempotensi seeder `firstOrCreate`, tanpa Redis/Node, queue lewat scheduler, `Process::fake`). Keputusan orkestrator (RED menunggu dependensi, artisan command, `.env.production.example`, tanpa optimize/backup otomatis) diterima. Dependensi F012, F014, F015, F017 `todo` dan F018 `in-progress`: sesuai keputusan, RED belum boleh mulai. Ada 4 temuan major dan 8 minor.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | `PublishPublicDirectory` (AC-25/26), panduan langkah 10, CLAUDE.md "Batasan deployment" (target cPanel) | `.htaccess` dari `public/` disalin menimpa `public_html/.htaccess`. Di cPanel, MultiPHP Manager menulis blok handler PHP (`# php -- BEGIN cPanel-generated handler ... END`) di `.htaccess` document root. Menimpanya membuat situs jatuh ke versi PHP bawaan (bukan 8.3) lalu error 500 setelah `pondok:install` atau `pondok:upgrade`. Hal yang sama terjadi di cara A: mengekstrak zip baru menimpa `public/.htaccess` saat upgrade, dan panduan tidak menyinggungnya. | Bila `target/.htaccess` ada: pertahankan blok handler cPanel (di antara penanda `BEGIN`/`END` cPanel) dengan menggabungkannya ke `.htaccess` baru, jangan menimpa mentah. Tambah AC (fixture `.htaccess` target berisi blok handler lalu publish, blok masih ada dan aturan Laravel ikut). Panduan langkah 10 dan bagian masalah umum: cadangkan `.htaccess` sebelum ekstrak atau pilih ulang PHP 8.3 di MultiPHP Manager setelah upgrade. Tambahkan kata kunci ke AC-49. |
| 2 | major | Konvensi test, AC-11 s/d AC-20, AC-23, AC-42 s/d AC-47 | Isolasi hanya dijamin untuk sebagian AC (AC-13/14 menyebut `useEnvironmentPath`, links, public root). AC lain memanggil `pondok:install`/`upgrade` tanpa mewajibkannya. Akibatnya: (a) langkah 2 dapat menyalin `.env.production.example` menjadi `.env` di root repo bila `.env` tidak ada (CI), lalu mencemari test lain; (b) langkah 8 `LinkPublicStorage` membuat symlink `public/storage` di repo dev, melanggar konvensi plan sendiri; (c) langkah 9 `optimize:clear` menghapus `bootstrap/cache` dan view terkompilasi repo dev; (d) `down` menulis `storage/framework/maintenance.php` repo dev, sehingga run paralel (`pest --parallel`) membuat test HTTP proses lain mendapat 503, dan `afterEach` tidak melindungi bila proses mati. | Wajibkan hook bersama (`beforeEach` di `tests/Pest.php` atau trait khusus, dipakai semua test command install/upgrade/doctor/publish): `useEnvironmentPath($tmp)` dengan `.env` ber-APP_KEY, `usePublicPath($tmp/web)`, `filesystems.links` dan `disks.public.root` ke `$tmp`, `useStoragePath($tmp/storage)` (maintenance dan heartbeat jadi terisolasi, menggantikan ketergantungan pada `afterEach up`), `useBootstrapPath($tmp/bootstrap)`. Tambahkan assert di akhir tiap test bahwa `base_path('.env')` dan `public_path('storage')` repo tidak berubah. Pertahankan `afterEach up` sebagai lapis kedua. |
| 3 | major | Paket rilis, `missingRequirements`, panduan langkah 3 dan 4, CLAUDE.md "Batasan deployment" | Kesalahan paling umum operator non-IT adalah mengekstrak seluruh aplikasi ke `public_html` (plan hanya memberi peringatan teks). Maka `/.env` (kredensial DB, `APP_KEY`), `storage/`, dan `vendor/` terunduh lewat HTTP. Disk `private` di luar `public` hanya benar bila struktur folder benar; cek `private_disk` hanya membandingkan dengan `public_path()` sehingga tidak mendeteksi kasus ini (public_path = `public_html/pondok/public`). | Sertakan `.htaccess` di root paket (`pondok/.htaccess`, `Require all denied` / `Deny from all`) sebagai pertahanan berlapis: wajib di `missingRequirements`, tercakup di AC-2/AC-4, disebut di panduan. Opsional: cek doctor `app_exposure` (mis. `realpath(base_path())` berada di dalam `$_SERVER['DOCUMENT_ROOT']` bila tersedia, `skipped` di CLI). |
| 4 | major | AC-50, pertanyaan 8 | AC-50 satu-satunya bukti "terpasang di cPanel" untuk `done` (acceptance F019), namun: mengisi `.env` dari `.env.example` (APP_ENV=local, bukan alur produksi dan bertentangan dengan keputusan revisi 2 serta AC-12/AC-48); tidak menguji `.env` belum ada, cara B (`publish-public` ke direktori tiruan `public_html`), install ulang, `pondok:upgrade --force`, maupun isi zip nyata (tanpa `.env`/`tests`/`vendor/pestphp`). Tidak menyebut bahwa ekstrak harus di luar repo dan MySQL/dev server harus dimatikan setelahnya (aturan RAM/dev server di CLAUDE.md global). | Ubah AC-50: ekstrak zip, `unzip -l` tidak memuat `.env`, `.git/`, `tests/`, `node_modules/`, `vendor/pestphp/`; jalankan `pondok:install` tanpa `.env` (harus menyalin `.env.production.example`, exit 1), isi `DB_*`, `APP_URL`, `SUPER_ADMIN_*`, ulangi sampai exit 0 dengan `APP_ENV=production`; jalankan install kedua (idempotent); `pondok:upgrade --force`; doctor tanpa `failure`; serve dengan `php83 -S` dan cek `/admin/login` 200 serta aset `/build/*` 200; ulangi serve dengan cara B (`PONDOK_PUBLIC_PATH` ke folder sementara). Catat PID/matikan server dan MySQL setelahnya. |
| 5 | minor | `RecordSchedulerHeartbeat`, `routes/console.php` | `Schedule::call([Kelas::class, 'handle'])` dengan method non-static tidak aman (callable array berisi nama kelas dipanggil statis). | Tetapkan `Schedule::call(fn () => app(RecordSchedulerHeartbeat::class)->handle())->everyMinute()->name(...)`, bukan "atau". |
| 6 | minor | `CheckSystemHealth` cek `queue` | Kolom `jobs.created_at`/`available_at`/`reserved_at` adalah integer unix timestamp (migrasi `0001_01_01_000002`), bukan datetime. Job terjadwal (`available_at` di masa depan) bisa salah dianggap tertunda bila memakai `created_at`. | Pakai `available_at <= now` dan `reserved_at IS NULL` dengan `now()->subMinutes(10)->timestamp`; AC-39 menyisipkan integer. Pertahankan query portabel. |
| 7 | minor | Alur `pondok:upgrade` langkah 5 s/d 8 | Hanya kegagalan `migrate` yang terdefinisi. Bila `SystemSeeder` atau `PublishPublicDirectory` gagal, status `down` dan exit code tidak ditentukan. | Tetapkan: gagal di langkah 5/6 tetap down, exit 1, pesan tindakan (jalankan ulang `pondok:upgrade --force`, aman karena idempotent); tambah satu AC. |
| 8 | minor | `PublishPublicDirectory` | Direktori `build`, `css/filament`, dll. di target dihapus sebelum salin tanpa memeriksa sumber. Sumber tanpa `build/manifest.json` membuat situs hidup kehilangan aset. Tidak ada penjagaan target = `base_path()` atau induknya. | Praperiksa `source/build/manifest.json` ada (RuntimeException sebelum menghapus apa pun); tolak target yang sama dengan atau induk dari `basePath`. Tambah ke AC-26. |
| 9 | minor | `BuildReleaseManifest` | Pengecualian `.env` memakai nama tepat. Varian `.env.staging`, `.env.local`, dsb. tidak terblokir (staging dari `git archive` aman, tetapi manifest mestinya independen). | Tambah pola: basename `.env.*` selain yang berakhiran `.example` dikecualikan, dan masuk daftar terlarang di `missingRequirements`; tambahkan ke AC-1/AC-4. |
| 10 | minor | AC-14, AC-21 | Angka keras (10 peran, 9 setting modul, 3 unit) rapuh saat F012/F014/F015/F017 menambah seed. | Bandingkan dengan konstanta seeder (`RolePermissionSeeder::ROLES`, daftar modul `SettingSeeder`) atau `> 0` plus exist-check kunci tertentu. |
| 11 | minor | `CheckSystemHealth` cek `migrations`; risiko 7 | Migrasi dari path paket (`loadMigrationsFrom`) tidak terhitung bila hanya `database/migrations`. Risiko 7 menyebut `storage/framework/down`, sedangkan Laravel 11+ memakai `maintenance.php` (konsisten dengan stub). | Pakai `$migrator->paths()` + `database_path('migrations')`. Koreksi nama berkas di risiko 7. |
| 12 | minor | Risiko 3 (`syncPermissions` saat upgrade) | Benar saat ini (belum ada resource `Role`, hanya izin disiapkan di `RESOURCE_MODELS`), tetapi upgrade akan menimpa kustomisasi peran bawaan begitu UI peran ada. | Pertahankan catatan risiko, dan tulis di panduan bahwa peran bawaan dikembalikan ke definisi saat upgrade. Tinjau ulang saat resource `Role` dibuat. |

Catatan yang sudah benar dan tidak perlu diubah: tidak ada Docker/Redis/Node runtime; `ZipArchive` sudah wajib (`openspout`); AC-31 selaras dengan `composer.lock` (ext-intl, ext-zip, dst. semuanya tercantum di `REQUIRED_EXTENSIONS`); `.env.example` tidak memuat kunci `REDIS_` sehingga AC-48 konsisten; tidak ada migration baru dan tidak ada tabel keuangan yang tersentuh; `.gitignore` saat ini tidak mengabaikan `.env.production.example`.

Setelah temuan 1 s/d 4 ditangani (5 s/d 12 boleh ikut atau menjadi catatan), ajukan DRAFT 3 untuk Review #2.

## Review #2 — 2026-10-16

Verdict: APPROVED

Verifikasi perbaikan Review #1:
- Major 1 (`.htaccess` cPanel): tertangani. `PreserveCpanelHtaccessBlocks` (extract/merge/remember/restore), penggabungan di `PublishPublicDirectory`, pemanggilan di install/upgrade, AC-25/25a/25b/47b, panduan langkah 10 dan masalah umum.
- Major 2 (isolasi test): tertangani. Hook `isolateInstallation()` mencakup path `.env`/public/storage/bootstrap, config disk/links/view, snapshot repo yang di-assert di `afterEach`, aman untuk `--parallel`.
- Major 3 (paparan `.env`): tertangani. `/.htaccess` root wajib di paket, cek `app_exposure`, AC-4/AC-36a.
- Major 4 (AC-50): tertangani. Memakai zip nyata, alur `.env.production.example`, install ulang, upgrade, cara B, `APP_ENV=production`, tanpa Docker, dengan bersih-bersih.
- Minor 5 s/d 12: semuanya tertangani. Konstanta `SettingSeeder::MODULES` dan `UnitSeeder::UNITS` sudah ada di kode.

Tidak ada blocker atau major baru. Catatan minor di bawah boleh dibereskan saat RED/GREEN atau DRAFT berikutnya.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | `/.htaccess` root, panduan masalah umum "403" | Klaim "tidak berpengaruh di cara A/B" belum teruji. AC-50 memakai `php -S` yang mengabaikan `.htaccess`. Pada Apache dengan `AllowOverride All` di direktori induk (sebagian VPS), `.htaccess` di induk document root bisa ikut berlaku dan menutup seluruh situs cara A. Panduan menyebut 403 selalu berarti "terekstrak ke `public_html`". | Tambahkan di panduan: bila 403 padahal document root sudah `pondok/public`, hapus `/.htaccess` root (fail-closed hanya perlu bila aplikasi berada di folder web). Catat di risiko. Bila ada akses cPanel sungguhan, uji sekali dan catat di `evidence`. |
| 2 | minor | `PreserveCpanelHtaccessBlocks::restore` | Pembandingan blok utuh bisa menghasilkan dua blok handler berbeda (mis. simpanan `ea-php83`, lalu operator memilih `ea-php84` di MultiPHP dan zip menimpa; atau berkas sudah punya blok baru). Dua `AddHandler` bertentangan. | Lewati blok simpanan bila berkas sudah memuat blok dengan baris `BEGIN cPanel-generated ...` yang sama (jenis sama); tambahkan satu assert ke AC-25b. |
| 3 | minor | AC-47a | Penanda "optimize:clear tidak dijalankan" di `$tmp/bootstrap/cache` hanya bermakna bila namanya salah satu berkas yang dihapus `optimize:clear`/`clear-compiled` (mis. `config.php`, `services.php`, `packages.php`). Cara membuat seeder gagal juga masih longgar ("listener/mock"). | Tetapkan penanda `bootstrap/cache/config.php`; tetapkan satu mekanisme gagal (mis. `PublishPublicDirectory` di-mock melempar, dan `Artisan`/seeder kedua lewat `Event::listen`). |
| 4 | minor | `app_exposure` | Heuristik segmen `www`/`htdocs` memberi peringatan palsu pada VPS standar (`/var/www/pondok`, document root benar). Hanya `warning`, tetapi doctor ikut "tidak hijau". | Pesan memuat penjelasan bahwa abaikan bila document root sudah `public/`; atau hapus `www` dari daftar. |
| 5 | minor | `PreserveCpanelHtaccessBlocks` | Satu kelas dengan empat operasi bertentangan dengan "satu Action satu operasi" (CLAUDE.md Arsitektur). | Boleh dipertahankan sebagai helper murni; atau pisah `RememberCpanelHtaccessBlocks`/`RestoreCpanelHtaccessBlocks` dengan `extract`/`merge` sebagai metode statis. |
| 6 | minor | AC-50 | Mengandaikan MySQL dari Herd sudah ada/berjalan dan `php83` tersedia. | Orkestrator memverifikasi lebih dulu (`herd` / `which php83` / port 3306). Bila tidak ada MySQL, tanya user (jangan menyalakan Docker; layanan lain hanya atas izin sesuai CLAUDE.md global). |

Dependensi F012, F014, F015, F017 (`todo`) dan F018 (`in-progress`) belum `done`. Sesuai keputusan orkestrator, RED menunggu semuanya `done`; saat itu seeder baru dari fitur tersebut dipindahkan ke `InstallSeeder` dan isi paket/panduan diperiksa ulang.
