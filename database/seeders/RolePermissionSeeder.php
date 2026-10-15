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

    /** Model yang punya resource Filament, beserta aksi policy-nya. */
    public const RESOURCE_MODELS = ['Unit', 'AcademicYear', 'Staff', 'User', 'Role'];

    public const RESOURCE_ACTIONS = ['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny'];

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
            $actions ?? self::RESOURCE_ACTIONS,
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

        return [...self::DASHBOARD_PERMISSIONS, ...$resource];
    }

    /**
     * @return list<string>
     */
    public static function permissionsFor(string $role): array
    {
        $readOnly = ['viewAny', 'view'];

        return match ($role) {
            'super-admin' => self::allPermissions(),
            'bendahara' => ['view-dashboard', 'view-finance-metrics'],
            'admin-konten' => ['view-dashboard', 'view-content-metrics'],
            'admin-sekolah' => [
                'view-dashboard',
                'view-student-metrics',
                'view-settings-metrics',
                ...self::resourcePermissions('Staff'),
                ...self::resourcePermissions('Unit', $readOnly),
                ...self::resourcePermissions('AcademicYear', $readOnly),
            ],
            'admin-psb' => ['view-dashboard', 'view-psb-metrics'],
            'kesantrian' => ['view-dashboard', 'view-student-metrics'],
            'musyrif', 'ustadz-tahfidz', 'petugas-pos' => ['view-dashboard'],
            // Portal wali (Fase 2) di luar panel admin.
            'wali' => [],
        };
    }
}
