<?php

use App\Filament\Imports\StaffImporter;
use App\Filament\Resources\Staff\Pages\ListStaff;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Imports\Models\FailedImportRow;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use League\Csv\Bom;
use League\Csv\Writer;
use Livewire\Livewire;

const IMPORT_STAFF_HEADERS = ['nip', 'name', 'gender', 'phone', 'position', 'is_active'];

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel('admin');
});

/**
 * @param  list<array<string, string>>  $rows
 */
function importStaffCsv(array $rows): UploadedFile
{
    $csv = Writer::fromString();
    $csv->insertOne(IMPORT_STAFF_HEADERS);
    $csv->insertAll(array_map(fn (array $row): array => array_values(array_replace(array_fill_keys(IMPORT_STAFF_HEADERS, ''), $row)), $rows));

    return UploadedFile::fake()->createWithContent('pegawai.csv', $csv->toString());
}

describe('AC-7: akses aksi Import', function () {
    it('shows import and template actions to admin-sekolah and super-admin and serves the template', function () {
        foreach (['admin-sekolah', 'super-admin'] as $role) {
            $this->actingAs(User::factory()->create()->assignRole($role));

            Livewire::test(ListStaff::class)
                ->assertActionVisible('import')
                ->assertActionVisible('downloadImportTemplate');
        }

        $columns = collect(StaffImporter::getColumns());
        $expected = Writer::fromString();
        $expected->insertOne($columns->map->getExampleHeader()->all());
        $expected->insertOne($columns->map(fn ($column): string => (string) collect($column->getExamples())->first())->all());
        $expected->setOutputBOM(Bom::Utf8);

        expect($columns->map->getName()->all())->toBe(IMPORT_STAFF_HEADERS)
            ->and((string) collect($columns->get(3)->getExamples())->first())->toStartWith('0800');

        Livewire::test(ListStaff::class)
            ->callAction('downloadImportTemplate')
            ->assertFileDownloaded(__('pondok.staff.import.template_file_name'), $expected->toString());

        expect(__('pondok.staff.import.template_file_name'))->toEndWith('.csv')->not->toContain('pondok.');
    });

    it('hides the actions and refuses the import for other roles', function (string $role) {
        $user = User::factory()->create()->assignRole($role);
        $this->actingAs($user);

        expect($user->can('import', Staff::class))->toBeFalse();

        if ($user->can('viewAny', Staff::class)) {
            Livewire::test(ListStaff::class)
                ->assertActionHidden('import')
                ->assertActionHidden('downloadImportTemplate');
        }

        expect(Staff::query()->count())->toBe(0);
    })->with(['kesantrian', 'bendahara', 'musyrif']);
});

describe('AC-9: import end-to-end', function () {
    it('saves two valid rows, records one failed row with a reason, and notifies the counts', function () {
        config(['queue.default' => 'sync']);
        $user = User::factory()->create()->assignRole('admin-sekolah');
        $this->actingAs($user);

        Livewire::test(ListStaff::class)->callAction('import', data: [
            'file' => importStaffCsv([
                ['nip' => '197001012026001', 'name' => 'Ustadz Contoh', 'gender' => 'L', 'phone' => '0800000000001', 'position' => 'Ustadz', 'is_active' => 'ya'],
                ['nip' => '', 'name' => 'Ustadzah Contoh', 'gender' => 'p', 'phone' => '', 'position' => 'Musyrifah', 'is_active' => ''],
                ['nip' => '197001012026003', 'name' => 'Salah Gender', 'gender' => 'X', 'phone' => '', 'position' => 'TU', 'is_active' => ''],
            ]),
            'columnMap' => array_combine(IMPORT_STAFF_HEADERS, IMPORT_STAFF_HEADERS),
        ])->assertHasNoActionErrors();

        $import = Import::query()->latest('id')->firstOrFail();

        expect(Staff::query()->count())->toBe(2)
            ->and(Staff::query()->where('name', 'Ustadzah Contoh')->value('gender'))->toBe('P')
            ->and($import->successful_rows)->toBe(2)
            ->and($import->getFailedRowsCount())->toBe(1)
            ->and(FailedImportRow::query()->value('validation_error'))->not->toBeEmpty()->not->toContain('pondok.')->not->toContain('validation.')
            ->and($user->notifications()->latest()->firstOrFail()->data['body'])->toContain('2')->toContain('1');
    });
});
