<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public const SUPER_ADMIN = 'super-admin';

    public const ROLES = [
        'super-admin', 'bendahara', 'admin-konten', 'admin-sekolah', 'admin-psb',
        'kesantrian', 'musyrif', 'ustadz-tahfidz', 'petugas-pos', 'wali',
    ];

    /** Permission dashboard dari PRD 3.10. */
    public const DASHBOARD_PERMISSIONS = [
        'view-dashboard',
        'view-content-metrics',
        'view-psb-metrics',
        'view-student-metrics',
        'view-finance-metrics',
        'view-settings-metrics',
    ];

    /** Halaman Filament kustom, dengan format nama permission page Shield (`view:<slug>`). */
    public const PAGE_PERMISSIONS = [
        'view:finance-report',
    ];

    /** Model yang punya resource Filament, beserta aksi policy-nya. */
    public const RESOURCE_MODELS = [
        'Unit', 'AcademicYear', 'Staff', 'User', 'Role',
        'Cohort', 'Classroom', 'Student', 'Guardian',
        'Dormitory', 'Room', 'RoomAssignment', 'DormitorySupervisor',
        'FeeType', 'FeeRate', 'Scholarship', 'FeeOverride', 'Invoice', 'Payment',
        'AdmissionWave', 'AdmissionTrack', 'AdmissionRequirement', 'Applicant',
    ];

    public const RESOURCE_ACTIONS = ['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny'];

    /** Aksi tambahan per model, misalnya pemulihan untuk model soft delete. */
    public const EXTRA_RESOURCE_ACTIONS = [
        'Student' => ['restore', 'restoreAny'],
        // Tagihan hanya dibuat generator dan dibatalkan, tidak dibuat manual atau dihapus.
        'Invoice' => ['generate', 'cancel'],
        // Pembayaran yang salah dibatalkan (status `batal`), tidak diubah atau dihapus.
        'Payment' => ['cancel'],
        // Pendaftar dibuat lewat form publik; panitia memverifikasi berkas, mengatur ulang token, dan menagih formulir.
        'Applicant' => ['verifyDocument', 'resetToken', 'generateInvoices', 'decide', 'reopen'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::allPermissions() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web')->syncPermissions(self::permissionsFor($role));
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
            $actions ?? [...self::RESOURCE_ACTIONS, ...(self::EXTRA_RESOURCE_ACTIONS[$model] ?? [])],
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

        return [...self::DASHBOARD_PERMISSIONS, ...$resource, ...self::PAGE_PERMISSIONS];
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
                ...self::resourcePermissions('Invoice', ['viewAny', 'view', 'generate', 'cancel']),
                ...self::resourcePermissions('Payment', ['viewAny', 'view', 'create', 'cancel']),
                'view:finance-report',
            ],
            'admin-konten' => ['view-dashboard', 'view-content-metrics'],
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
            ],
            // Gelombang dan jalur tidak dihapus: ditutup atau dinonaktifkan. Syarat berkas
            // boleh dihapus selama belum dirujuk berkas pendaftar.
            'admin-psb' => [
                'view-dashboard',
                'view-psb-metrics',
                ...self::resourcePermissions('AdmissionWave', $withoutDelete),
                ...self::resourcePermissions('AdmissionTrack', $withoutDelete),
                ...self::resourcePermissions('AdmissionRequirement'),
                ...self::resourcePermissions('Applicant', ['viewAny', 'view', 'delete', 'verifyDocument', 'resetToken', 'generateInvoices', 'decide']),
            ],
            // Santri dan wali lihat-saja; mengelola asrama, kamar, penghuni, dan musyrif.
            'kesantrian' => [
                'view-dashboard',
                'view-student-metrics',
                ...self::resourcePermissions('Student', $readOnly),
                ...self::resourcePermissions('Guardian', $readOnly),
                ...self::resourcePermissions('Dormitory'),
                ...self::resourcePermissions('Room'),
                ...self::resourcePermissions('RoomAssignment', $withoutDelete),
                ...self::resourcePermissions('DormitorySupervisor', $withoutDelete),
            ],
            // Daftar penghuni lihat-saja, terbatas pada asrama/kamar yang diawasi.
            'musyrif' => [
                'view-dashboard',
                ...self::resourcePermissions('RoomAssignment', $readOnly),
            ],
            'ustadz-tahfidz', 'petugas-pos' => ['view-dashboard'],
            // Portal wali (Fase 2) di luar panel admin.
            'wali' => [],
        };
    }
}
