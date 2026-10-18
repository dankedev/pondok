<?php

use App\Filament\Imports\StudentImporter;
use App\Filament\Resources\Students\Pages\ListStudents;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\UnitSeeder;
use Filament\Actions\Imports\ContentGenerators\CsvImportFailureContentGenerator;
use Filament\Actions\Imports\Models\FailedImportRow;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use League\Csv\Writer;
use Livewire\Livewire;

const IMPORT_SANTRI_HEADERS = [
    'nis', 'name', 'gender', 'birth_place', 'birth_date', 'unit', 'entry_year', 'classroom',
    'guardian1_name', 'guardian1_relation', 'guardian1_phone', 'guardian1_billing',
    'guardian2_name', 'guardian2_relation', 'guardian2_phone', 'guardian2_billing',
];

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, UnitSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, string>
 */
function importSantriRow(array $overrides = []): array
{
    return [
        'nis' => '2026001', 'name' => 'Ahmad Fauzan', 'gender' => 'L', 'birth_place' => 'Bandung',
        'birth_date' => '2012-03-15', 'unit' => 'SLTP', 'entry_year' => '2026', 'classroom' => '',
        'guardian1_name' => 'Abdullah Rahman', 'guardian1_relation' => 'ayah', 'guardian1_phone' => '081234567890', 'guardian1_billing' => 'ya',
        'guardian2_name' => '', 'guardian2_relation' => '', 'guardian2_phone' => '', 'guardian2_billing' => '',
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, string>>  $rows
 */
function importSantriCsv(array $rows): UploadedFile
{
    $csv = Writer::fromString();
    $csv->insertOne(IMPORT_SANTRI_HEADERS);
    $csv->insertAll(array_map(fn (array $row): array => array_values(array_replace(array_fill_keys(IMPORT_SANTRI_HEADERS, ''), $row)), $rows));

    return UploadedFile::fake()->createWithContent('santri.csv', $csv->toString());
}

function importSantriRun(User $user, array $rows)
{
    test()->actingAs($user);

    return Livewire::test(ListStudents::class)
        ->callAction('import', data: [
            'file' => importSantriCsv($rows),
            'columnMap' => array_combine(IMPORT_SANTRI_HEADERS, IMPORT_SANTRI_HEADERS),
        ]);
}

function importSantriAdmin(): User
{
    return User::factory()->create()->assignRole('admin-sekolah');
}

describe('AC-1: akses aksi Import', function () {
    it('shows the import action to admin-sekolah and super-admin only among roles that can see students', function () {
        foreach (['admin-sekolah', 'super-admin'] as $role) {
            $user = User::factory()->create()->assignRole($role);
            $this->actingAs($user);

            expect($user->can('import', Student::class))->toBeTrue();
            Livewire::test(ListStudents::class)->assertActionVisible('import');
        }

        $kesantrian = User::factory()->create()->assignRole('kesantrian');
        $this->actingAs($kesantrian);

        expect($kesantrian->can('import', Student::class))->toBeFalse();
        Livewire::test(ListStudents::class)->assertActionHidden('import');
    });

    it('denies the import permission to roles without it', function (string $role) {
        expect(User::factory()->create()->assignRole($role)->can('import', Student::class))->toBeFalse();
    })->with(['bendahara', 'admin-psb', 'musyrif']);
});

describe('AC-2: template contoh', function () {
    it('lists every template column, with an example for each required one and no forbidden terms', function () {
        $columns = collect(StudentImporter::getColumns());

        expect($columns->map->getName()->all())->toBe(IMPORT_SANTRI_HEADERS);

        $text = $columns->map(fn ($column): string => $column->getLabel().' '.$column->getExampleHeader().' '.collect((array) $column->getExample())->implode(' '))->implode(' ');

        foreach (['maulid', 'isra', 'nisfu', 'haul', 'tahlil', 'harlah', 'hadrah', 'shalawat', 'nuzul'] as $term) {
            expect(mb_strtolower($text))->not->toContain($term);
        }
    });

    it('imports the example row successfully', function () {
        $year = AcademicYear::factory()->active()->create();
        $example = collect(StudentImporter::getColumns())
            ->mapWithKeys(fn ($column): array => [$column->getName() => (string) collect((array) $column->getExample())->first()])
            ->all();

        if ($example['classroom'] !== '') {
            Classroom::factory()->create(['academic_year_id' => $year->id, 'unit_id' => Unit::query()->where('code', 'SLTP')->value('id'), 'name' => $example['classroom']]);
        }

        $this->actingAs(importSantriAdmin());
        StudentImporter::test()->import($example)->assertImported();

        expect(Student::query()->count())->toBe(1);
    });
});

describe('AC-8: baris tidak valid', function () {
    it('rejects an invalid row with a reason and stores nothing', function (array $override) {
        $this->actingAs(importSantriAdmin());
        $result = StudentImporter::test()->import(importSantriRow($override));

        if ($result->errors()->isEmpty()) {
            $result->assertHasRowFailure();
        }

        expect(Student::query()->count())->toBe(0)
            ->and(Guardian::query()->count())->toBe(0);
    })->with([
        'empty NIS' => [['nis' => '']],
        'unknown unit' => [['unit' => 'XYZ']],
        'wrong date format' => [['birth_date' => '15/03/2012']],
        'wrong gender' => [['gender' => 'X']],
        'wrong relation' => [['guardian1_relation' => 'paman']],
        'entry year not 4 digits' => [['entry_year' => '26']],
    ]);

    it('imports valid rows of a file and records failed rows with an Indonesian reason', function () {
        $user = importSantriAdmin();

        importSantriRun($user, [
            importSantriRow(['nis' => '1', 'name' => 'Ahmad Fauzan']),
            importSantriRow(['nis' => '2', 'name' => 'Umar Fauzan', 'gender' => 'X']),
            importSantriRow(['nis' => '3', 'name' => 'Ali Fauzan', 'guardian1_name' => 'Lain', 'guardian1_phone' => '081555555555']),
        ])->assertHasNoActionErrors();

        $import = Import::query()->latest('id')->firstOrFail();
        $reason = FailedImportRow::query()->firstOrFail()->validation_error;

        expect(Student::query()->pluck('nis')->sort()->values()->all())->toBe(['1', '3'])
            ->and($import->total_rows)->toBe(3)
            ->and($import->successful_rows)->toBe(2)
            ->and($import->getFailedRowsCount())->toBe(1)
            ->and($reason)->not->toBeEmpty()
            ->and($reason)->not->toContain('validation.')
            ->and($reason)->not->toContain('pondok.');

        $notification = $user->notifications()->latest()->firstOrFail();
        expect($notification->data['body'])->toContain('2')->toContain('1');
    });
});

describe('AC-10: formula injection', function () {
    it('prevents formula injection in the failed rows file', function () {
        expect(StudentImporter::shouldPreventFormulaInjection())->toBeTrue();

        importSantriRun(importSantriAdmin(), [
            importSantriRow(['nis' => '9', 'name' => '=HYPERLINK("http://x")', 'gender' => 'X', 'guardian1_phone' => '+6281234567890']),
        ]);

        $import = Import::query()->latest('id')->firstOrFail();
        $csv = Writer::fromString();
        (new CsvImportFailureContentGenerator)($import, $csv);
        $content = $csv->toString();

        expect($content)->toContain("'=HYPERLINK")
            ->and($content)->toContain('+6281234567890')
            ->and($content)->not->toContain("'+6281234567890");
    });

    it('re-imports a phone prefixed with a quote as a normalized phone', function () {
        $this->actingAs(importSantriAdmin());

        StudentImporter::test()->import(importSantriRow(['guardian1_phone' => "'+6281234567890"]))->assertImported();

        expect(Guardian::query()->value('phone'))->toBe('081234567890');
    });
});

describe('AC-11: notifikasi dan antrean', function () {
    it('has database notifications enabled on the panel', function () {
        expect(Filament::getPanel('admin')->hasDatabaseNotifications())->toBeTrue();
    });

    it('imports immediately and notifies the importer with the sync queue', function () {
        config(['queue.default' => 'sync']);
        $user = importSantriAdmin();

        importSantriRun($user, [importSantriRow()]);

        expect(Student::query()->count())->toBe(1)
            ->and($user->notifications()->count())->toBe(1)
            ->and(DB::table('notifications')->count())->toBe(1);
    });

    it('waits for the worker with the database queue and finishes after queue:work --stop-when-empty', function () {
        config(['queue.default' => 'database']);
        $user = importSantriAdmin();

        importSantriRun($user, [importSantriRow()]);

        expect(Student::query()->count())->toBe(0)
            ->and(DB::table('jobs')->count())->toBeGreaterThan(0);

        Artisan::call('queue:work', ['--stop-when-empty' => true, '--memory' => 2048]);

        expect(Student::query()->count())->toBe(1)
            ->and($user->notifications()->count())->toBe(1);
    });

    it('applies the importer unit scope inside the queue job', function () {
        config(['queue.default' => 'sync']);
        $user = importSantriAdmin();
        $user->units()->attach(Unit::query()->where('code', 'SD')->value('id'));

        importSantriRun($user, [
            importSantriRow(['nis' => '1', 'unit' => 'SLTP']),
            importSantriRow(['nis' => '2', 'unit' => 'SD', 'guardian1_name' => 'Lain', 'guardian1_phone' => '081555555555']),
        ]);

        expect(Student::withoutUnitScope()->pluck('nis')->all())->toBe(['2'])
            ->and(FailedImportRow::query()->count())->toBe(1);
    });
});

describe('VERIFY: celah keamanan dan integritas', function () {
    it('neutralizes every formula trigger in the failed rows file', function () {
        importSantriRun(importSantriAdmin(), [
            importSantriRow(['nis' => '1', 'gender' => 'X', 'name' => '@SUM(A1)', 'birth_place' => "\t=1+1", 'guardian1_name' => "+cmd|' /C calc'!A0"]),
        ]);

        $import = Import::query()->latest('id')->firstOrFail();
        $csv = Writer::fromString();
        (new CsvImportFailureContentGenerator)($import, $csv);
        $content = $csv->toString();

        expect($content)->toContain("'@SUM(A1)")
            ->and($content)->toContain("'\t=1+1")
            ->and($content)->toContain("'+cmd|")
            ->and($content)->not->toMatch('/(^|,)"?[=@]/m');
    });

    it('lets only the importer download the failed rows file', function () {
        importSantriRun(importSantriAdmin(), [importSantriRow(['gender' => 'X'])]);
        $import = Import::query()->latest('id')->firstOrFail();
        $url = route('filament.imports.failed-rows.download', ['import' => $import]);

        $this->actingAs(User::factory()->create()->assignRole('admin-sekolah'))->get($url)->assertForbidden();
        $this->actingAs($import->user)->get($url)->assertOk();
    });

    it('does not import when a role without the permission calls the hidden action', function () {
        $this->actingAs(User::factory()->create()->assignRole('kesantrian'));

        try {
            Livewire::test(ListStudents::class)->callAction('import', data: [
                'file' => importSantriCsv([importSantriRow()]),
                'columnMap' => array_combine(IMPORT_SANTRI_HEADERS, IMPORT_SANTRI_HEADERS),
            ]);
        } catch (Throwable) {
            // aksi tersembunyi boleh melempar; yang dinilai adalah efeknya
        }

        expect(Student::withoutUnitScope()->count())->toBe(0)
            ->and(Import::query()->count())->toBe(0);
    });

    it('keeps rows around a failing row intact and leaves no orphan data from the failing row', function () {
        AcademicYear::factory()->active()->create();

        importSantriRun(importSantriAdmin(), [
            importSantriRow(['nis' => '1']),
            importSantriRow(['nis' => '2', 'classroom' => 'TIDAK-ADA', 'guardian1_name' => 'Yatim Piatu', 'guardian1_phone' => '081777777777']),
            importSantriRow(['nis' => '3', 'guardian1_name' => 'Lain', 'guardian1_phone' => '081555555555']),
        ]);

        expect(Student::query()->pluck('nis')->sort()->values()->all())->toBe(['1', '3'])
            ->and(Guardian::query()->where('name', 'Yatim Piatu')->exists())->toBeFalse()
            ->and(FailedImportRow::query()->count())->toBe(1);
    });

    it('does not put names or phones into the completion notification', function () {
        $user = importSantriAdmin();

        importSantriRun($user, [importSantriRow(), importSantriRow(['nis' => '2', 'gender' => 'X'])]);

        $payload = json_encode($user->notifications()->latest()->firstOrFail()->data);

        expect($payload)->not->toContain('Ahmad')->not->toContain('Abdullah')->not->toContain('0812');
    });

    it('does not reveal the other student data in the NIS conflict reason', function () {
        $user = importSantriAdmin();
        Student::factory()->create(['nis' => '777', 'name' => 'Rahasia Sekali', 'unit_id' => Unit::query()->where('code', 'SD')->value('id')]);

        importSantriRun($user, [importSantriRow(['nis' => '777', 'unit' => 'SLTP'])]);

        $reason = FailedImportRow::query()->firstOrFail()->validation_error;

        expect($reason)->not->toBeEmpty()->not->toContain('Rahasia');
    });

    it('limits the shared temporary upload endpoint to PSB documents and CSV, not html or svg', function () {
        $rules = config('livewire.temporary_file_upload.rules');
        $check = fn (UploadedFile $file): bool => ! validator(['file' => $file], ['file' => $rules])->fails();

        expect($check(UploadedFile::fake()->createWithContent('a.csv', "nis,name\n1,Ali\n")))->toBeTrue()
            ->and($check(UploadedFile::fake()->createWithContent('a.html', '<html><script>alert(1)</script></html>')))->toBeFalse()
            ->and($check(UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')))->toBeFalse()
            ->and($check(UploadedFile::fake()->createWithContent('a.php', '<?php echo 1;')))->toBeFalse();
    });
});
