<?php

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| F040 AC-1. Migrasi: database/migrations/*_add_min_reregistration_amount_to_units_table.php.
*/

function minReRegistrationMigration(): object
{
    $files = glob(database_path('migrations/*_add_min_reregistration_amount_to_units_table.php'));
    expect($files)->toHaveCount(1);

    return require $files[0];
}

it('AC-1: adds a nullable nominal column to units and removes the old percent setting', function () {
    expect(Schema::hasColumn('units', 'min_reregistration_amount'))->toBeTrue();

    $migration = minReRegistrationMigration();
    $migration->down();

    expect(Schema::hasColumn('units', 'min_reregistration_amount'))->toBeFalse();

    Setting::set('psb.min_paid_percent', 50);
    Setting::set('students.nis_format', '{YYYY}{NNNN}');
    expect(Setting::get('psb.min_paid_percent'))->toBe(50);

    $migration->up();

    DB::table('units')->insert(['code' => 'ZZ', 'name' => 'Uji', 'sort_order' => 9, 'created_at' => now(), 'updated_at' => now()]);

    expect(Schema::hasColumn('units', 'min_reregistration_amount'))->toBeTrue()
        ->and(DB::table('units')->where('code', 'ZZ')->value('min_reregistration_amount'))->toBeNull()
        ->and(DB::table('settings')->where('key', 'psb.min_paid_percent')->count())->toBe(0)
        ->and(Setting::get('psb.min_paid_percent', 'hilang'))->toBe('hilang')
        ->and(Setting::get('students.nis_format'))->toBe('{YYYY}{NNNN}');
});
