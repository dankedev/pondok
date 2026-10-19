<?php

use App\Actions\Core\ImportStaffRow;
use App\Filament\Imports\StaffImporter;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function staffRow(array $overrides = []): array
{
    return ['nip' => '197001012026001', 'name' => 'Ustadz Contoh', 'gender' => 'L', 'phone' => '0800000000001', 'position' => 'Ustadz', 'is_active' => '', ...$overrides];
}

function importStaffRow(array $row): Staff
{
    return app(ImportStaffRow::class)->handle($row);
}

it('AC-1: creates a new active staff without user when nothing matches', function () {
    $staff = importStaffRow(staffRow(['nip' => '']));

    expect(Staff::query()->count())->toBe(1)
        ->and($staff->nip)->toBeNull()
        ->and($staff->name)->toBe('Ustadz Contoh')
        ->and($staff->gender)->toBe('L')
        ->and($staff->phone)->toBe('0800000000001')
        ->and($staff->position)->toBe('Ustadz')
        ->and($staff->is_active)->toBeTrue()
        ->and($staff->user_id)->toBeNull();
});

it('AC-2: updates the staff with the same NIP and keeps its user', function () {
    $user = User::factory()->create();
    $staff = Staff::factory()->create(['nip' => '197001012026001', 'user_id' => $user->id, 'name' => 'Nama Lama', 'gender' => 'P', 'position' => 'TU', 'phone' => '0811']);

    importStaffRow(staffRow(['nip' => ' 197001012026001 ']));

    $staff->refresh();
    expect(Staff::query()->count())->toBe(1)
        ->and($staff->name)->toBe('Ustadz Contoh')
        ->and($staff->gender)->toBe('L')
        ->and($staff->position)->toBe('Ustadz')
        ->and($staff->phone)->toBe('0800000000001')
        ->and($staff->user_id)->toBe($user->id);
});

it('AC-3: matches by name and phone without NIP, ignoring case and edge spaces', function () {
    $staff = Staff::factory()->create(['nip' => null, 'name' => 'Ustadz Contoh', 'phone' => '0800000000001', 'position' => 'TU']);
    $sameNameOtherPhone = ['phone' => '0899'];

    importStaffRow(staffRow(['nip' => '', 'name' => '  USTADZ contoh ', 'position' => 'Ustadz']));

    expect(Staff::query()->count())->toBe(1)
        ->and($staff->refresh()->position)->toBe('Ustadz')
        ->and($staff->nip)->toBeNull();

    importStaffRow(staffRow(['nip' => '', ...$sameNameOtherPhone]));

    expect(Staff::query()->count())->toBe(2);
});

it('AC-4: empty optional columns keep old values and is_active ya/tidak toggles', function () {
    $staff = Staff::factory()->create(['nip' => '197001012026001', 'phone' => '0811', 'is_active' => false]);

    importStaffRow(staffRow(['phone' => '', 'is_active' => '']));
    expect($staff->refresh()->phone)->toBe('0811')->and($staff->is_active)->toBeFalse()->and($staff->nip)->toBe('197001012026001');

    importStaffRow(staffRow(['is_active' => 'ya']));
    expect($staff->refresh()->is_active)->toBeTrue();

    importStaffRow(staffRow(['is_active' => 'tidak']));
    expect($staff->refresh()->is_active)->toBeFalse();

    $nameOnly = Staff::factory()->create(['nip' => '555', 'name' => 'Tanpa Nip', 'phone' => '0822']);
    importStaffRow(staffRow(['nip' => '', 'name' => 'Tanpa Nip', 'phone' => '0822']));
    expect($nameOnly->refresh()->nip)->toBe('555');
});

it('AC-5: importing the same rows twice adds nothing', function () {
    foreach ([staffRow(), staffRow(['nip' => '', 'name' => 'Tanpa Nip', 'phone' => ''])] as $row) {
        importStaffRow($row);
        importStaffRow($row);
    }

    expect(Staff::query()->count())->toBe(2);
});

it('AC-8: fails with an ambiguity message when two staff share name and phone, and changes nothing', function () {
    Staff::factory()->count(2)->create(['nip' => null, 'name' => 'Ustadz Contoh', 'phone' => '0800000000001', 'position' => 'TU']);

    try {
        importStaffRow(staffRow(['nip' => '']));
        $this->fail('Baris ambigu seharusnya gagal.');
    } catch (RowImportFailedException $e) {
        expect($e->getMessage())->not->toBeEmpty()->not->toContain('pondok.');
    }

    expect(Staff::query()->count())->toBe(2)
        ->and(Staff::query()->where('position', 'Ustadz')->count())->toBe(0);
});

it('AC-6: rejects invalid rows with an Indonesian reason and writes no staff', function (array $override) {
    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(User::factory()->create()->assignRole('super-admin'));

    $result = StaffImporter::test()->import(staffRow($override));
    if ($result->errors()->isEmpty()) {
        $result->assertHasRowFailure();
    }

    expect(Staff::query()->count())->toBe(0)
        ->and(collect($result->errors())->flatten()->implode(' '))->not->toContain('pondok.')->not->toContain('validation.');
})->with([
    'gender outside L/P' => [['gender' => 'X']],
    'empty gender' => [['gender' => '']],
    'empty name' => [['name' => '']],
    'empty position' => [['position' => '']],
    'is_active not ya/tidak' => [['is_active' => 'mungkin']],
]);

it('AC-6: normalises lowercase gender and uppercase is_active before the rules', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(User::factory()->create()->assignRole('super-admin'));

    StaffImporter::test()->import(staffRow(['gender' => 'p', 'is_active' => 'TIDAK']))->assertImported();

    expect(Staff::query()->sole())->gender->toBe('P')->is_active->toBeFalse();
});
