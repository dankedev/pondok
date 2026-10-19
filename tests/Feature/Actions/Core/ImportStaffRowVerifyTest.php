<?php

use App\Filament\Imports\StaffImporter;
use App\Filament\Resources\Staff\Pages\ListStaff;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use League\Csv\Bom;
use League\Csv\Writer;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel('admin');
});

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function verifyStaffRow(array $overrides = []): array
{
    return ['nip' => '', 'name' => 'Ustadz Contoh', 'gender' => 'L', 'phone' => '0800000000001', 'position' => 'Ustadz', 'is_active' => '', ...$overrides];
}

it('VERIFY: a row without NIP matching a staff that has NIP updates it and keeps the NIP', function () {
    $staff = Staff::factory()->create(['nip' => '197001012026001', 'name' => 'Ustadz Contoh', 'phone' => '0800000000001', 'position' => 'TU']);

    $this->actingAs(User::factory()->create()->assignRole('super-admin'));
    StaffImporter::test()->import(verifyStaffRow())->assertImported();

    expect(Staff::query()->count())->toBe(1)
        ->and($staff->refresh()->position)->toBe('Ustadz')
        ->and($staff->nip)->toBe('197001012026001');
});

it('VERIFY: a blank phone row does not wipe the phone nor match a staff that has a phone', function () {
    $this->actingAs(User::factory()->create()->assignRole('super-admin'));
    $withPhone = Staff::factory()->create(['nip' => '111', 'name' => 'Ustadz Contoh', 'phone' => '0800000000001']);

    StaffImporter::test()->import(verifyStaffRow(['nip' => '111', 'phone' => '']))->assertImported();

    expect($withPhone->refresh()->phone)->toBe('0800000000001')
        ->and(Staff::query()->count())->toBe(1);
});

it('VERIFY: over-long NIP, phone, name and position fail the row and write nothing', function (array $override) {
    $this->actingAs(User::factory()->create()->assignRole('super-admin'));

    $result = StaffImporter::test()->import(verifyStaffRow($override));

    expect(Staff::query()->count())->toBe(0)
        ->and($result->errors())->not->toBeEmpty();
})->with([
    'nip 51' => [['nip' => str_repeat('1', 51)]],
    'phone 31' => [['phone' => str_repeat('0', 31)]],
    'name 256' => [['name' => str_repeat('a', 256)]],
    'position 101' => [['position' => str_repeat('a', 101)]],
    'name spaces only' => [['name' => '   ']],
]);

it('VERIFY: values with edge spaces are stored trimmed and gender with spaces is accepted', function () {
    $this->actingAs(User::factory()->create()->assignRole('super-admin'));

    StaffImporter::test()->import(verifyStaffRow(['nip' => ' 777 ', 'name' => '  Ustadz Rapi  ', 'gender' => ' p ', 'position' => ' Guru ', 'is_active' => ' YA ']));

    $staff = Staff::query()->sole();
    expect($staff->nip)->toBe('777')
        ->and($staff->name)->toBe('Ustadz Rapi')
        ->and($staff->position)->toBe('Guru')
        ->and($staff->gender)->toBe('P')
        ->and($staff->is_active)->toBeTrue();
});

it('VERIFY: the same NIP twice in one file yields one staff', function () {
    $this->actingAs(User::factory()->create()->assignRole('super-admin'));

    StaffImporter::test()->import(verifyStaffRow(['nip' => '888', 'name' => 'Pertama']))->assertImported();
    StaffImporter::test()->import(verifyStaffRow(['nip' => '888', 'name' => 'Kedua']))->assertImported();

    expect(Staff::query()->sole()->name)->toBe('Kedua');
});

it('VERIFY: a real CSV with BOM and Indonesian headers imports through ListStaff', function () {
    config(['queue.default' => 'sync']);
    $this->actingAs(User::factory()->create()->assignRole('admin-sekolah'));

    $headers = ['NIP', 'Nama', 'Jenis kelamin (L/P)', 'Telepon', 'Jabatan', 'Aktif (ya/tidak)'];
    $csv = Writer::fromString();
    $csv->setOutputBOM(Bom::Utf8);
    $csv->insertOne($headers);
    $csv->insertOne(['197001012026009', 'Ustadz Bom', 'L', '0800000000009', 'Ustadz', 'ya']);

    Livewire::test(ListStaff::class)->callAction('import', data: [
        'file' => UploadedFile::fake()->createWithContent('pegawai.csv', $csv->toString()),
        'columnMap' => array_combine(['nip', 'name', 'gender', 'phone', 'position', 'is_active'], $headers),
    ])->assertHasNoActionErrors();

    expect(Import::query()->latest('id')->firstOrFail()->successful_rows)->toBe(1)
        ->and(Staff::query()->where('nip', '197001012026009')->value('name'))->toBe('Ustadz Bom');
});

it('VERIFY: a role that can view staff but lacks import:staff cannot run the import action', function () {
    config(['queue.default' => 'sync']);
    $role = Role::findOrCreate('hanya-lihat-staff', 'web');
    $role->givePermissionTo('view-any:staff');
    $this->actingAs(User::factory()->create()->assignRole($role));

    $page = Livewire::test(ListStaff::class)->assertActionHidden('import');

    expect(fn () => $page->callAction('import', data: [
        'file' => UploadedFile::fake()->createWithContent('pegawai.csv', "nip,name,gender,phone,position,is_active\n,Liar,L,,TU,\n"),
        'columnMap' => array_combine(['nip', 'name', 'gender', 'phone', 'position', 'is_active'], ['nip', 'name', 'gender', 'phone', 'position', 'is_active']),
    ]))->toThrow(AssertionFailedError::class);

    expect(Staff::query()->count())->toBe(0);
});
