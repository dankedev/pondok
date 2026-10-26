<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public const SUPER_ADMIN = 'super-admin';

    public const ROLES = [
        'super-admin', 'bendahara', 'admin-konten', 'admin-sekolah', 'admin-psb',
        'kesantrian', 'musyrif', 'ustadz-tahfidz', 'petugas-pos', 'wali', 'wali-kelas',
    ];

    /** Permission dashboard dari PRD 3.10. */
    public const DASHBOARD_PERMISSIONS = [
        'view-dashboard',
        'view-content-metrics',
        'view-psb-metrics',
        'view-student-metrics',
        'view-finance-metrics',
        'view-settings-metrics',
        // Widget santri terlambat kembali (F024).
        'view-leave-metrics',
    ];

    /** Halaman Filament kustom, dengan format nama permission page Shield (`view:<slug>`). */
    public const PAGE_PERMISSIONS = [
        'view:finance-report',
        // Rekap saldo uang saku santri (F028).
        'view:wallet-balances',
        // Profil lembaga (F016/F036): membuka dan menyimpan keempat halaman cluster.
        'view:school-profile',
        // Penomoran dokumen (F039): format kuitansi/tagihan (bendahara) dan NIS (admin-sekolah).
        'view:finance-numbering',
        'view:student-numbering',
        // Syarat daftar ulang (F040): nominal minimal pembayaran per jenjang (admin-psb).
        'view:re-registration-payment',
    ];

    /** Permission rapor diniyah (F027): input nilai (wali kelas, admin sekolah) dan cetak rapor. */
    public const DINIYAH_PERMISSIONS = ['input-diniyah-grades', 'view-diniyah-report'];

    /** Model yang punya resource Filament, beserta aksi policy-nya. */
    public const RESOURCE_MODELS = [
        'Unit', 'AcademicYear', 'Staff', 'User', 'Role',
        'Cohort', 'Classroom', 'Student', 'Guardian',
        'Dormitory', 'Room', 'RoomAssignment', 'DormitorySupervisor',
        'FeeType', 'FeeRate', 'Scholarship', 'FeeOverride', 'Invoice', 'Payment', 'WalletTransaction',
        'AdmissionWave', 'AdmissionTrack', 'AdmissionRequirement', 'Applicant',
        'DocumentType', 'Document', 'IssuedLetter',
        'Category', 'Post', 'Album', 'Video', 'StaticPage',
        'Achievement',
        'Halaqah', 'MemorizationRecord', 'TahfidzExam', 'TahfidzTarget',
        'HealthVisit',
        'LeaveType', 'HomecomingPeriod', 'LeaveRequest', 'Visit',
        'AttendanceSession',
        'ViolationType', 'DisciplineLevel', 'StudentViolation',
        'DiniyahSubject',
    ];

    public const RESOURCE_ACTIONS = ['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny'];

    /** Aksi tambahan per model, misalnya pemulihan untuk model soft delete. */
    public const EXTRA_RESOURCE_ACTIONS = [
        'Student' => ['restore', 'restoreAny', 'import'],
        'RoomAssignment' => ['import'],
        'Staff' => ['import'],
        // Tagihan hanya dibuat generator dan dibatalkan, tidak dibuat manual atau dihapus.
        'Invoice' => ['generate', 'cancel', 'import'],
        // Pembayaran yang salah dibatalkan (status `batal`), tidak diubah atau dihapus.
        'Payment' => ['cancel'],
        // Pendaftar dibuat lewat form publik; panitia memverifikasi berkas, mengatur ulang token, dan menagih formulir.
        'Applicant' => ['verifyDocument', 'resetToken', 'generateInvoices', 'decide', 'reopen', 'reRegister'],
        // Dokumen tidak diubah atau dihapus dari panel: koreksi lewat versi baru atau status `ditolak`.
        'Document' => ['verify', 'viewConfidential'],
        // Surat keluar tidak diubah atau dihapus: koreksi lewat pembatalan lalu terbit ulang.
        'IssuedLetter' => ['cancel', 'configureNumbering'],
        // Berita soft delete: dipulihkan dari tempat sampah, tidak dihapus permanen.
        'Post' => ['restore', 'restoreAny'],
        // Prestasi diverifikasi admin sekolah dan dijadikan draf berita oleh admin konten.
        'Achievement' => ['verify', 'publish'],
    ];

    /** Model yang aksinya lebih sempit dari aksi standar. */
    public const LIMITED_RESOURCE_ACTIONS = [
        // Halaman statis (F037): dibuat dan dihapus satu per satu (halaman sistem dijaga
        // policy dan `DeleteStaticPage`); tanpa hapus massal, sehingga tanpa `deleteAny`.
        'StaticPage' => ['viewAny', 'view', 'create', 'update', 'delete'],
        // Perizinan (F024): tidak ada yang dihapus. Jenis izin dinonaktifkan; izin dibatalkan (status `batal`).
        // `approve` = penyetuju sesuai `dormitory_supervisors` (musyrif), `approveAny` = semua izin (kesantrian).
        'LeaveType' => ['viewAny', 'view', 'create', 'update'],
        'HomecomingPeriod' => ['viewAny', 'view', 'create', 'update', 'generate'],
        'LeaveRequest' => ['viewAny', 'view', 'create', 'approve', 'approveAny', 'checkInOut', 'cancel'],
        'Visit' => ['viewAny', 'view', 'create', 'update'],
        // Absensi (F025): koreksi lewat ubah status, sesi tidak dihapus.
        'AttendanceSession' => ['viewAny', 'view', 'create', 'update'],
        // Kedisiplinan (F026): jenis dinonaktifkan, pelanggaran dibatalkan; keduanya tidak dihapus.
        'ViolationType' => ['viewAny', 'view', 'create', 'update'],
        'StudentViolation' => ['viewAny', 'view', 'create', 'update', 'cancel'],
        // Uang saku santri (F028): ledger append-only, salah catat dibatalkan (status `batal`).
        'WalletTransaction' => ['viewAny', 'view', 'create', 'cancel'],
        // Rapor diniyah (F027): mapel dinonaktifkan, tidak dihapus.
        'DiniyahSubject' => ['viewAny', 'view', 'create', 'update'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Permission yang belum ada ditulis sekaligus. `findOrCreate` per nama membersihkan dan memuat
        // ulang cache Spatie setiap kali (±650 query untuk ±220 permission, ±0,8 detik per seed),
        // padahal seeder ini dipanggil di setup sebagian besar test. Hasilnya sama dan tetap idempotent.
        $existing = Permission::query()->where('guard_name', 'web')->pluck('name')->all();
        $missing = array_values(array_diff(self::allPermissions(), $existing));
        $now = now();

        foreach (array_chunk($missing, 100) as $chunk) {
            Permission::query()->insert(array_map(
                fn (string $name): array => ['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now],
                $chunk,
            ));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Pivot disinkronkan langsung: `syncPermissions` memuat ulang cache Spatie untuk setiap peran.
        // Hasil akhirnya sama, yaitu tiap peran punya persis daftar `permissionsFor()`.
        $ids = Permission::query()->where('guard_name', 'web')->pluck('id', 'name');

        foreach (self::ROLES as $role) {
            $names = self::permissionsFor($role);

            foreach ($names as $name) {
                throw_unless($ids->has($name), PermissionDoesNotExist::create($name, 'web'));
            }

            Role::findOrCreate($role, 'web')->permissions()->sync($ids->only($names)->values()->all());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Nama permission resource dengan format Shield (kebab, pemisah `:`),
     * misalnya `view-any:academic-year`.
     */
    public static function resourcePermission(string $action, string $model): string
    {
        return Str::kebab($action).':'.Str::kebab($model);
    }

    /**
     * @return list<string>
     */
    public static function resourcePermissions(string $model, ?array $actions = null): array
    {
        return array_map(
            fn (string $action): string => self::resourcePermission($action, $model),
            $actions ?? self::LIMITED_RESOURCE_ACTIONS[$model] ?? [...self::RESOURCE_ACTIONS, ...(self::EXTRA_RESOURCE_ACTIONS[$model] ?? [])],
        );
    }

    /**
     * @return list<string>
     */
    public static function allPermissions(): array
    {
        $resource = array_merge(...array_map(
            fn (string $model): array => self::resourcePermissions($model),
            self::RESOURCE_MODELS,
        ));

        return [...self::DASHBOARD_PERMISSIONS, ...$resource, ...self::PAGE_PERMISSIONS, ...self::DINIYAH_PERMISSIONS];
    }

    /**
     * @return list<string>
     */
    public static function permissionsFor(string $role): array
    {
        $readOnly = ['viewAny', 'view'];
        // Riwayat penempatan, tugas musyrif, dan data keuangan tidak dihapus.
        $withoutDelete = ['viewAny', 'view', 'create', 'update'];

        return match ($role) {
            'super-admin' => self::allPermissions(),
            // Mengatur jenis biaya, tarif, program beasiswa, dan keringanan. Tidak ada yang
            // dihapus: jenis biaya dan program dinonaktifkan, tarif dikoreksi lewat edit,
            // keringanan diakhiri lewat tanggal berakhir. Tagihan dibuat generator dan
            // dibatalkan, tidak dibuat manual, diubah, atau dihapus. Pembayaran dicatat dan
            // dibatalkan, tidak diubah atau dihapus. Laporan keuangan (termasuk unduh CSV)
            // memakai satu permission halaman.
            'bendahara' => [
                'view-dashboard',
                'view-finance-metrics',
                ...self::resourcePermissions('FeeType', $withoutDelete),
                ...self::resourcePermissions('FeeRate', $withoutDelete),
                ...self::resourcePermissions('Scholarship', $withoutDelete),
                ...self::resourcePermissions('FeeOverride', $withoutDelete),
                ...self::resourcePermissions('Invoice', ['viewAny', 'view', 'generate', 'cancel', 'import']),
                ...self::resourcePermissions('Payment', ['viewAny', 'view', 'create', 'cancel']),
                ...self::resourcePermissions('WalletTransaction'),
                'view:wallet-balances',
                'view:finance-report',
                'view:finance-numbering',
            ],
            // Kelola konten situs. Foto album mengikuti `update:album`. Halaman statis tetap tiga:
            // hanya diubah, tidak dibuat atau dihapus.
            'admin-konten' => [
                'view-dashboard',
                'view-content-metrics',
                ...self::resourcePermissions('Category'),
                ...self::resourcePermissions('Post'),
                ...self::resourcePermissions('Album'),
                ...self::resourcePermissions('Video'),
                ...self::resourcePermissions('StaticPage'),
                // Membuat draf berita dari prestasi publik terverifikasi (F015).
                ...self::resourcePermissions('Achievement', ['publish']),
                // Profil lembaga (F036): isi beranda, kontak, dan footer portal juga dikelola
                // Admin Konten (keputusan user 16 Okt 2026).
                'view:school-profile',
            ],
            'admin-sekolah' => [
                'view-dashboard',
                'view-student-metrics',
                'view-settings-metrics',
                ...self::resourcePermissions('Staff'),
                ...self::resourcePermissions('Unit', $readOnly),
                ...self::resourcePermissions('AcademicYear', $readOnly),
                ...self::resourcePermissions('Cohort'),
                ...self::resourcePermissions('Classroom'),
                ...self::resourcePermissions('Student'),
                ...self::resourcePermissions('Guardian'),
                ...self::resourcePermissions('DocumentType'),
                ...self::resourcePermissions('Document', ['viewAny', 'view', 'create', 'verify', 'viewConfidential']),
                // PRD 2: profil lembaga dikelola Admin Sekolah dan Admin Konten (F036).
                'view:school-profile',
                // Surat keluar (F014): terbit, batal, dan atur penomoran; tidak diubah atau dihapus.
                ...self::resourcePermissions('IssuedLetter', ['viewAny', 'view', 'create', 'cancel', 'configureNumbering']),
                // Prestasi (F015): memverifikasi catatan kesantrian, tidak mencatat atau menghapus.
                ...self::resourcePermissions('Achievement', ['viewAny', 'view', 'verify']),
                // Penomoran NIS (F039).
                'view:student-numbering',
                // Tahfidz (F023): halaqah dinonaktifkan, tidak dihapus; setoran/ujian dikoreksi dengan hapus lalu catat ulang.
                ...self::resourcePermissions('Halaqah', $withoutDelete),
                ...self::resourcePermissions('MemorizationRecord', ['viewAny', 'view', 'create', 'delete']),
                ...self::resourcePermissions('TahfidzExam', ['viewAny', 'view', 'create', 'delete']),
                ...self::resourcePermissions('TahfidzTarget'),
                // Absensi harian (F025): mencatat dan mengoreksi absensi semua rombel dalam cakupannya.
                ...self::resourcePermissions('AttendanceSession'),
                // Kedisiplinan (F026): lihat pelanggaran dan rekap poin saja.
                ...self::resourcePermissions('StudentViolation', $readOnly),
                // Rapor diniyah (F027): mapel, input nilai semua rombel dalam cakupan, dan cetak rapor.
                ...self::resourcePermissions('DiniyahSubject', $withoutDelete),
                'input-diniyah-grades',
                'view-diniyah-report',
            ],
            // Gelombang dan jalur tidak dihapus: ditutup atau dinonaktifkan. Syarat berkas
            // boleh dihapus selama belum dirujuk berkas pendaftar.
            'admin-psb' => [
                'view-dashboard',
                'view-psb-metrics',
                ...self::resourcePermissions('AdmissionWave', $withoutDelete),
                ...self::resourcePermissions('AdmissionTrack', $withoutDelete),
                ...self::resourcePermissions('AdmissionRequirement'),
                ...self::resourcePermissions('Applicant', ['viewAny', 'view', 'delete', 'verifyDocument', 'resetToken', 'generateInvoices', 'decide', 'reRegister']),
                'view:re-registration-payment',
            ],
            // Santri dan wali lihat-saja; mengelola asrama, kamar, penghuni, dan musyrif.
            'kesantrian' => [
                'view-dashboard',
                'view-student-metrics',
                ...self::resourcePermissions('Student', $readOnly),
                ...self::resourcePermissions('Guardian', $readOnly),
                ...self::resourcePermissions('Dormitory'),
                ...self::resourcePermissions('Room'),
                ...self::resourcePermissions('RoomAssignment', [...$withoutDelete, 'import']),
                ...self::resourcePermissions('DormitorySupervisor', $withoutDelete),
                // Dokumen santri non-rahasia (mis. kartu BPJS) untuk keperluan asrama.
                ...self::resourcePermissions('Document', $readOnly),
                // Mencatat prestasi santri (F015); verifikasi oleh admin sekolah.
                ...self::resourcePermissions('Achievement', ['viewAny', 'view', 'create', 'update', 'delete']),
                // Kesehatan santri (F031): mencatat dan mengoreksi; hapus hanya super-admin.
                ...self::resourcePermissions('HealthVisit', ['viewAny', 'view', 'create', 'update']),
                // Perizinan (F024): seluruh izin, jenis izin, pulang massal, dan kunjungan.
                'view-leave-metrics',
                ...self::resourcePermissions('LeaveType'),
                ...self::resourcePermissions('HomecomingPeriod'),
                ...self::resourcePermissions('LeaveRequest'),
                ...self::resourcePermissions('Visit'),
                // Absensi (F025): lihat saja, untuk memantau alpa santri dalam pembinaan.
                ...self::resourcePermissions('AttendanceSession', $readOnly),
                // Kedisiplinan (F026): jenis pelanggaran, ambang, dan catatan (catat, ubah, batal).
                ...self::resourcePermissions('ViolationType'),
                ...self::resourcePermissions('DisciplineLevel'),
                ...self::resourcePermissions('StudentViolation'),
                // Rapor diniyah (F027): mengatur mapel dan mencetak rapor; tidak menginput nilai.
                ...self::resourcePermissions('DiniyahSubject', $withoutDelete),
                'view-diniyah-report',
            ],
            // Daftar penghuni lihat-saja, terbatas pada asrama/kamar yang diawasi. Mencatat kesehatan
            // santri binaannya (`HealthVisit::visibleStudents`); koreksi oleh kesantrian.
            'musyrif' => [
                'view-dashboard',
                ...self::resourcePermissions('RoomAssignment', $readOnly),
                ...self::resourcePermissions('HealthVisit', ['viewAny', 'view', 'create']),
                // Mengajukan, memutus (sesuai penugasan), dan membatalkan izin santri binaannya.
                'view-leave-metrics',
                ...self::resourcePermissions('LeaveRequest', ['viewAny', 'view', 'create', 'approve', 'cancel']),
                // Mencatat pelanggaran santri binaannya; ubah dan batal oleh kesantrian (F026).
                ...self::resourcePermissions('StudentViolation', ['viewAny', 'view', 'create']),
            ],
            // Pengampu melihat halaqah yang ia ampu (`Halaqah::visibleTo`), mencatat setoran dan ujian
            // anggotanya, dan menghapus setoran yang salah. Anggota halaqah dikelola admin sekolah.
            'ustadz-tahfidz' => [
                'view-dashboard',
                ...self::resourcePermissions('Halaqah', $readOnly),
                ...self::resourcePermissions('MemorizationRecord', ['viewAny', 'view', 'create', 'delete']),
                ...self::resourcePermissions('TahfidzExam', ['viewAny', 'view', 'create']),
            ],
            // Mencatat keluar-kembali santri berizin dan kunjungan wali di pos.
            'petugas-pos' => [
                'view-dashboard',
                'view-leave-metrics',
                ...self::resourcePermissions('LeaveRequest', ['viewAny', 'view', 'checkInOut']),
                ...self::resourcePermissions('Visit', ['viewAny', 'view', 'create', 'update']),
            ],
            // Portal wali (Fase 2) di luar panel admin.
            'wali' => [],
            // Wali kelas (F025): mencatat absensi harian rombel yang ia walikan (`AttendanceSession::visibleClassrooms`).
            'wali-kelas' => [
                'view-dashboard',
                ...self::resourcePermissions('AttendanceSession'),
                // Rapor diniyah (F027): mengisi nilai dan mencetak rapor rombel yang ia walikan.
                'input-diniyah-grades',
                'view-diniyah-report',
            ],
        };
    }
}
