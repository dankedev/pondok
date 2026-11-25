<?php

use App\Filament\Pages\AttendanceRecap;
use App\Filament\Pages\DiniyahGradeEntry;
use App\Filament\Pages\DisciplineRecap;
use App\Filament\Pages\FinanceReport;
use App\Filament\Pages\Settings\Contacts;
use App\Filament\Pages\Settings\Identity;
use App\Filament\Pages\Settings\Modules;
use App\Filament\Pages\Site\Footer;
use App\Filament\Pages\Site\Home;
use App\Filament\Pages\Site\Navigation;
use App\Filament\Pages\Site\Theme;
use App\Filament\Pages\Site\ThemeCatalog;
use App\Filament\Pages\Site\ThemeEditor;
use App\Filament\Pages\WalletBalances;
use App\Filament\Resources\AcademicYears\AcademicYearResource;
use App\Filament\Resources\Achievements\AchievementResource;
use App\Filament\Resources\AdmissionRequirements\AdmissionRequirementResource;
use App\Filament\Resources\AdmissionTracks\AdmissionTrackResource;
use App\Filament\Resources\AdmissionWaves\AdmissionWaveResource;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Applicants\ApplicantResource;
use App\Filament\Resources\AttendanceSessions\AttendanceSessionResource;
use App\Filament\Resources\Classrooms\ClassroomResource;
use App\Filament\Resources\DiniyahSubjects\DiniyahSubjectResource;
use App\Filament\Resources\DisciplineLevels\DisciplineLevelResource;
use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\Dormitories\DormitoryResource;
use App\Filament\Resources\FeeOverrides\FeeOverrideResource;
use App\Filament\Resources\FeeRates\FeeRateResource;
use App\Filament\Resources\FeeTypes\FeeTypeResource;
use App\Filament\Resources\Guardians\GuardianResource;
use App\Filament\Resources\Halaqahs\HalaqahResource;
use App\Filament\Resources\HealthVisits\HealthVisitResource;
use App\Filament\Resources\HomecomingPeriods\HomecomingPeriodResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\IssuedLetters\IssuedLetterResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\LeaveTypes\LeaveTypeResource;
use App\Filament\Resources\MemorizationRecords\MemorizationRecordResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\RoomAssignments\RoomAssignmentResource;
use App\Filament\Resources\Scholarships\ScholarshipResource;
use App\Filament\Resources\StaticPages\StaticPageResource;
use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\StudentViolations\StudentViolationResource;
use App\Filament\Resources\TahfidzExams\TahfidzExamResource;
use App\Filament\Resources\TahfidzTargets\TahfidzTargetResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Videos\VideoResource;
use App\Filament\Resources\ViolationTypes\ViolationTypeResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Filament\Resources\WalletTransactions\WalletTransactionResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\CreateRole;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ListRoles;
use Filament\Pages\Dashboard;

return [

    /*
    | Akun super-admin yang dibuat oleh SuperAdminSeeder saat instalasi.
    */
    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

    /*
    | Proksi tepercaya (Cloudflare, reverse proxy cPanel) agar IP pengunjung
    | untuk rate limit PSB benar. Daftar IP/CIDR dipisah koma, atau `*`.
    | Kosong = tidak ada proksi tepercaya; header X-Forwarded-For diabaikan.
    */
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /*
    | Document root terpisah dari folder aplikasi (cPanel yang document root
    | domainnya tidak bisa diubah), mis. `/home/<user>/public_html`. Bila diisi,
    | `public_path()` menunjuk ke sini dan `pondok:publish-public` menyalin isi
    | `public/` ke sana.
    */
    'public_path' => env('PONDOK_PUBLIC_PATH'),

    /*
    | Portal publik (F017). `cache_max_age` dalam detik untuk Cache-Control
    | publik respons 200 (0 = tanpa cache publik). `csp_enabled` mengirim
    | Content-Security-Policy portal (otomatis tidak dikirim saat Vite hot).
    */
    'portal' => [
        'cache_max_age' => (int) env('PORTAL_CACHE_MAX_AGE', 300),
        'csp_enabled' => (bool) env('PORTAL_CSP', true),
    ],

    /*
    | Direktori tema portal (F102): satu subfolder per tema berisi `theme.json`.
    | Bawaan `resources/themes` (tiga tema resmi); test mengarahkannya ke folder sementara.
    */
    'themes_path' => resource_path('themes'),

    /*
    | Tema resmi terpasang dari katalog (F103): `<slug>/theme.json` (+ `previous.json`), ditulis aplikasi, di luar web root.
    */
    'installed_themes_path' => storage_path('app/themes'),

    /*
    | Panduan pondok.org (F115). Slug /panduan/<slug>/ stabil; jangan menautkan ke anchor (#...), bisa berubah.
    | `pages` memetakan halaman admin ke slug panduannya; tautan "Panduan" tampil di header halaman itu.
    | Kunci berupa Resource berlaku untuk semua halamannya; entri halaman eksplisit didahulukan.
    */
    'guides' => [
        'base_url' => 'https://pondok.org/panduan/',
        'pages' => [
            ListRoles::class => 'membuat-peran',
            CreateRole::class => 'membuat-peran',
            EditRole::class => 'membuat-peran',
            ListUsers::class => 'menambah-pengguna',
            CreateUser::class => 'menambah-pengguna',
            EditUser::class => 'mengelola-akun-pengguna',
            Modules::class => 'modul',
            Dashboard::class => 'mengenal-dasbor-dan-menu',
            DiniyahGradeEntry::class => 'mengisi-nilai-dan-rapor',
            FinanceReport::class => 'laporan-keuangan',
            WalletBalances::class => 'mengelola-uang-saku',
            DisciplineRecap::class => 'mencatat-pelanggaran',
            AttendanceRecap::class => 'mencatat-absensi-harian',
            Identity::class => 'mengatur-menu-footer-halaman',
            Contacts::class => 'mengatur-menu-footer-halaman',
            Navigation::class => 'mengatur-menu-footer-halaman',
            Footer::class => 'mengatur-menu-footer-halaman',
            Theme::class => 'memilih-tema-portal',
            ThemeCatalog::class => 'memilih-tema-portal',
            ThemeEditor::class => 'memilih-tema-portal',
            Home::class => 'menyusun-beranda',
            StudentResource::class => 'menambah-santri',
            GuardianResource::class => 'menambah-santri',
            ClassroomResource::class => 'mengatur-rombel',
            AcademicYearResource::class => 'mengatur-rombel',
            DiniyahSubjectResource::class => 'mengatur-mapel-diniyah',
            FeeTypeResource::class => 'mengatur-jenis-biaya',
            FeeRateResource::class => 'mengatur-jenis-biaya',
            InvoiceResource::class => 'menerbitkan-tagihan',
            PaymentResource::class => 'mencatat-pembayaran',
            FeeOverrideResource::class => 'keringanan-beasiswa',
            ScholarshipResource::class => 'keringanan-beasiswa',
            WalletTransactionResource::class => 'mengelola-uang-saku',
            AdmissionTrackResource::class => 'jalur-dan-syarat-berkas-psb',
            AdmissionRequirementResource::class => 'jalur-dan-syarat-berkas-psb',
            AdmissionWaveResource::class => 'membuka-gelombang-psb',
            ApplicantResource::class => 'memeriksa-pendaftar',
            DormitoryResource::class => 'mengatur-asrama-kamar',
            RoomAssignmentResource::class => 'menempatkan-santri-di-kamar',
            LeaveRequestResource::class => 'mengajukan-izin-santri',
            LeaveTypeResource::class => 'mengatur-jenis-izin-dan-pulang-massal',
            HomecomingPeriodResource::class => 'mengatur-jenis-izin-dan-pulang-massal',
            VisitResource::class => 'mencatat-kunjungan-wali',
            HealthVisitResource::class => 'mencatat-kesehatan-santri',
            StudentViolationResource::class => 'mencatat-pelanggaran',
            ViolationTypeResource::class => 'mengatur-jenis-pelanggaran',
            DisciplineLevelResource::class => 'mengatur-jenis-pelanggaran',
            MemorizationRecordResource::class => 'mencatat-setoran-hafalan',
            HalaqahResource::class => 'mengatur-halaqah',
            TahfidzTargetResource::class => 'mengatur-halaqah',
            TahfidzExamResource::class => 'ujian-tahfidz',
            AttendanceSessionResource::class => 'mencatat-absensi-harian',
            AchievementResource::class => 'input-prestasi',
            IssuedLetterResource::class => 'menerbitkan-surat-keluar',
            DocumentResource::class => 'mengunggah-dokumen-santri',
            StaticPageResource::class => 'mengatur-menu-footer-halaman',
            PostResource::class => 'menulis-berita-galeri-video',
            AlbumResource::class => 'menulis-berita-galeri-video',
            VideoResource::class => 'menulis-berita-galeri-video',
        ],
    ],

    /*
    | Katalog tema resmi (F103). URL dapat diganti env hanya untuk uji, bukan pengaturan admin. Kunci publik Ed25519
    | (base64) sengaja literal di sini, tidak dari env: `k1` utama, `k2` cadangan (privatnya disimpan terpisah offline).
    | Kunci kosong membuat pemasangan tema ditolak dengan pesan jelas. Kunci privat tidak ada di repo (docs/rilis.md).
    */
    'theme_catalog_url' => env('PONDOK_THEME_CATALOG_URL', 'https://pondok.org/themes/themes.json'),
    'theme_public_keys' => [
        'k1' => 'iBp6Ju+fcrjdcH2jps6A6QNXUvkeZ6pisMR0FHL3Piw=',
        'k2' => 'EJFcfG8aypTKgociXiahFy2gdmByxqKZ3U2ZNOqA6xk=',
    ],

    /*
    | Data demo (F034, `php artisan db:seed --class=DemoSeeder`). Password semua
    | akun demo; kosong = password bawaan `DemoSeeder::DEFAULT_PASSWORD` yang
    | tertulis di docs/demo.md (seeder menampilkan peringatan).
    */
    'demo' => [
        'password' => env('DEMO_PASSWORD'),
    ],

];
