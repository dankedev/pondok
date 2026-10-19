<?php

use App\Filament\Concerns\HasCsvImportActions;
use App\Filament\Imports\CsvImporter;
use App\Filament\Imports\RoomAssignmentImporter;
use App\Filament\Imports\StudentImporter;
use App\Filament\Resources\RoomAssignments\Pages\ManageRoomAssignments;
use App\Filament\Resources\Students\Pages\ListStudents;
use App\Models\Dormitory;
use App\Models\Room;
use App\Models\RoomAssignment;
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
use League\Csv\Bom;
use League\Csv\Writer;
use Livewire\Livewire;

const IMPORT_PENGHUNI_HEADERS = ['nis', 'dormitory', 'room', 'start_on'];

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, UnitSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    asramaActiveYear();
});

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function importPenghuniRow(array $overrides = []): array
{
    return ['nis' => '2026001', 'dormitory' => 'Asrama Abu Bakar', 'room' => 'Kamar 101', 'start_on' => '2026-07-15', ...$overrides];
}

/**
 * Asrama putra "Asrama Abu Bakar" dengan kamar "Kamar 101" dan satu santri NIS 2026001.
 *
 * @return array{student: Student, dormitory: Dormitory, room: Room}
 */
function importPenghuniSetup(int $capacity = 10, ?string $unitCode = null): array
{
    $dormitory = asramaDormitory('L', ['name' => 'Asrama Abu Bakar']);

    return [
        'student' => asramaStudent('L', ['nis' => '2026001', ...($unitCode ? ['unit_id' => Unit::query()->where('code', $unitCode)->value('id')] : [])]),
        'dormitory' => $dormitory,
        'room' => asramaRoom($capacity, 'L', ['name' => 'Kamar 101'], $dormitory),
    ];
}

/**
 * @param  list<array<string, string>>  $rows
 */
function importPenghuniCsv(array $rows): UploadedFile
{
    $csv = Writer::fromString();
    $csv->insertOne(IMPORT_PENGHUNI_HEADERS);
    $csv->insertAll(array_map(fn (array $row): array => array_values(array_replace(array_fill_keys(IMPORT_PENGHUNI_HEADERS, ''), $row)), $rows));

    return UploadedFile::fake()->createWithContent('penghuni.csv', $csv->toString());
}

/**
 * @param  list<array<string, string>>  $rows
 */
function importPenghuniRun(User $user, array $rows)
{
    test()->actingAs($user);

    return Livewire::test(ManageRoomAssignments::class)
        ->callAction('import', data: [
            'file' => importPenghuniCsv($rows),
            'columnMap' => array_combine(IMPORT_PENGHUNI_HEADERS, IMPORT_PENGHUNI_HEADERS),
        ]);
}

function importPenghuniFailureReasons(): array
{
    return FailedImportRow::query()->orderBy('id')->pluck('validation_error')->all();
}

describe('AC-1: akses aksi Import', function () {
    it('shows import and template actions to kesantrian and super-admin only', function () {
        foreach (['kesantrian', 'super-admin'] as $role) {
            $user = User::factory()->create()->assignRole($role);
            $this->actingAs($user);

            expect($user->can('import', RoomAssignment::class))->toBeTrue();
            Livewire::test(ManageRoomAssignments::class)
                ->assertActionVisible('import')
                ->assertActionVisible('downloadImportTemplate');
        }
    });

    it('hides the actions and refuses the import for roles that must not import', function (string $role) {
        $user = User::factory()->create()->assignRole($role);
        importPenghuniSetup();
        $this->actingAs($user);

        expect($user->can('import', RoomAssignment::class))->toBeFalse();

        if ($user->can('viewAny', RoomAssignment::class)) {
            Livewire::test(ManageRoomAssignments::class)
                ->assertActionHidden('import')
                ->assertActionHidden('downloadImportTemplate');

            try {
                Livewire::test(ManageRoomAssignments::class)->callAction('import', data: [
                    'file' => importPenghuniCsv([importPenghuniRow()]),
                    'columnMap' => array_combine(IMPORT_PENGHUNI_HEADERS, IMPORT_PENGHUNI_HEADERS),
                ]);
            } catch (Throwable) {
                // aksi tersembunyi boleh melempar; yang dinilai adalah efeknya
            }
        }

        expect(RoomAssignment::query()->count())->toBe(0)->and(Import::query()->count())->toBe(0);
    })->with(['musyrif', 'admin-sekolah', 'bendahara']);
});

describe('AC-2: template contoh dan ekstraksi bersama', function () {
    it('lists the four columns with Indonesian headers and a fictitious example', function () {
        $columns = collect(RoomAssignmentImporter::getColumns());

        expect($columns->map->getName()->all())->toBe(IMPORT_PENGHUNI_HEADERS)
            ->and($columns->map->getExampleHeader()->all())->toBe($columns->map->getLabel()->all())
            ->and($columns->map(fn ($column): array => $column->getExamples())->all())
            ->toBe([['2026001'], ['Asrama Abu Bakar'], ['Kamar 101'], ['2026-07-15']]);

        foreach ($columns->map->getLabel() as $label) {
            expect($label)->not->toBeEmpty()->not->toContain('pondok.');
        }
    });

    it('downloads a BOM UTF-8 CSV template for importers only', function () {
        $this->actingAs(User::factory()->create()->assignRole('kesantrian'));

        $columns = collect(RoomAssignmentImporter::getColumns());
        $expected = Writer::fromString();
        $expected->insertOne($columns->map->getExampleHeader()->all());
        $expected->insertOne($columns->map(fn ($column): string => (string) collect($column->getExamples())->first())->all());
        $expected->setOutputBOM(Bom::Utf8);

        Livewire::test(ManageRoomAssignments::class)
            ->callAction('downloadImportTemplate')
            ->assertFileDownloaded(__('pondok.asrama.import.template_file_name'), $expected->toString());

        expect(__('pondok.asrama.import.template_file_name'))->toEndWith('.csv')->not->toContain('pondok.');
    });

    it('imports the example row successfully when the matching dormitory and room exist', function () {
        $example = collect(RoomAssignmentImporter::getColumns())
            ->mapWithKeys(fn ($column): array => [$column->getName() => (string) collect($column->getExamples())->first()])
            ->all();
        $dormitory = asramaDormitory('L', ['name' => $example['dormitory']]);
        asramaRoom(5, 'L', ['name' => $example['room']], $dormitory);
        asramaStudent('L', ['nis' => $example['nis']]);

        $this->actingAs(asramaUser('kesantrian'));
        RoomAssignmentImporter::test()->import($example)->assertImported();

        expect(RoomAssignment::query()->count())->toBe(1);
    });

    it('shares the import base class and trait with the student import, which still works', function () {
        expect(is_subclass_of(StudentImporter::class, CsvImporter::class))->toBeTrue()
            ->and(is_subclass_of(RoomAssignmentImporter::class, CsvImporter::class))->toBeTrue()
            ->and(class_uses(ListStudents::class))->toHaveKey(HasCsvImportActions::class)
            ->and(class_uses(ManageRoomAssignments::class))->toHaveKey(HasCsvImportActions::class);
    });
});

describe('AC-3 sampai AC-8: isi file', function () {
    it('AC-3: places valid rows from the panel with case-insensitive names and a default start date', function () {
        ['student' => $student, 'room' => $room] = importPenghuniSetup();
        $other = asramaStudent('L', ['nis' => '2026002']);

        importPenghuniRun(asramaUser('kesantrian'), [
            importPenghuniRow(['dormitory' => 'asrama abu BAKAR', 'room' => 'KAMAR 101']),
            importPenghuniRow(['nis' => '2026002', 'start_on' => '']),
        ])->assertHasNoActionErrors();

        $one = RoomAssignment::query()->where('student_id', $student->id)->firstOrFail();
        $two = RoomAssignment::query()->where('student_id', $other->id)->firstOrFail();

        expect($one->room_id)->toBe($room->id)
            ->and($one->end_on)->toBeNull()
            ->and($one->start_on->toDateString())->toBe('2026-07-15')
            ->and($two->start_on->toDateString())->toBe(today()->toDateString());
    });

    it('AC-4: moves a resident to the new room and fails a start date before the current placement', function () {
        ['student' => $student, 'dormitory' => $dormitory] = importPenghuniSetup();
        $other = asramaStudent('L', ['nis' => '2026002']);
        $old = asramaRoom(5, 'L', ['name' => 'Kamar 202'], $dormitory);
        asramaResident($student, $old, ['start_on' => '2026-07-15']);
        $earlier = asramaResident($other, $old, ['start_on' => '2026-07-15']);

        importPenghuniRun(asramaUser('kesantrian'), [
            importPenghuniRow(['start_on' => '2026-08-10']),
            importPenghuniRow(['nis' => '2026002', 'start_on' => '2026-07-01']),
        ]);

        expect(RoomAssignment::query()->where('student_id', $student->id)->count())->toBe(2)
            ->and(RoomAssignment::query()->where('student_id', $student->id)->whereNull('end_on')->value('room_id'))->not->toBe($old->id)
            ->and(RoomAssignment::query()->where('student_id', $other->id)->count())->toBe(1)
            ->and($earlier->fresh()->end_on)->toBeNull()
            ->and(FailedImportRow::query()->count())->toBe(1);
    });

    it('AC-5: records full room and gender mismatch as failed rows while valid rows still go in, and notifies the counts', function () {
        ['dormitory' => $dormitory] = importPenghuniSetup();
        asramaRoom(1, 'L', ['name' => 'Kamar Penuh'], $dormitory);
        asramaResident(asramaStudent('L'), Room::query()->where('name', 'Kamar Penuh')->firstOrFail());
        $putri = asramaDormitory('P', ['name' => 'Asrama Khadijah']);
        asramaRoom(5, 'P', ['name' => 'Kamar 1'], $putri);
        asramaStudent('L', ['nis' => '2026002']);
        asramaStudent('L', ['nis' => '2026003']);
        $user = asramaUser('kesantrian');

        importPenghuniRun($user, [
            importPenghuniRow(),
            importPenghuniRow(['nis' => '2026002', 'room' => 'Kamar Penuh']),
            importPenghuniRow(['nis' => '2026003', 'dormitory' => 'Asrama Khadijah', 'room' => 'Kamar 1']),
        ]);

        $import = Import::query()->latest('id')->firstOrFail();

        expect($import->total_rows)->toBe(3)
            ->and($import->successful_rows)->toBe(1)
            ->and($import->getFailedRowsCount())->toBe(2)
            ->and(RoomAssignment::query()->whereNull('end_on')->count())->toBe(2);

        foreach (importPenghuniFailureReasons() as $reason) {
            expect($reason)->not->toBeEmpty()->not->toContain('pondok.')->not->toContain('validation.');
        }

        expect($user->notifications()->latest()->firstOrFail()->data['body'])->toContain('1')->toContain('2');
    });

    it('AC-6: rejects invalid rows with an Indonesian reason and creates no dormitory, room, or placement', function (array $override) {
        importPenghuniSetup();
        $dormitories = Dormitory::query()->count();
        $rooms = Room::query()->count();

        importPenghuniRun(asramaUser('kesantrian'), [importPenghuniRow($override)]);

        expect(RoomAssignment::query()->count())->toBe(0)
            ->and(Dormitory::query()->count())->toBe($dormitories)
            ->and(Room::query()->count())->toBe($rooms)
            ->and(FailedImportRow::query()->count())->toBe(1)
            ->and(importPenghuniFailureReasons()[0])->not->toBeEmpty()->not->toContain('pondok.')->not->toContain('validation.');
    })->with([
        'unknown dormitory' => [['dormitory' => 'Asrama Hantu']],
        'unknown room' => [['room' => 'Kamar 999']],
        'unknown NIS' => [['nis' => '9999999']],
        'wrong date format' => [['start_on' => '15/07/2026']],
        'empty NIS' => [['nis' => '']],
        'empty dormitory' => [['dormitory' => '']],
        'empty room' => [['room' => '']],
    ]);

    it('AC-7: importing the same file twice keeps one placement and the old start date', function () {
        ['student' => $student] = importPenghuniSetup(1);
        $user = asramaUser('kesantrian');

        importPenghuniRun($user, [importPenghuniRow(['start_on' => '2026-07-15'])]);
        importPenghuniRun($user, [importPenghuniRow(['start_on' => '2026-07-15'])]);
        importPenghuniRun($user, [importPenghuniRow(['start_on' => '2026-09-01'])]);

        $rows = RoomAssignment::query()->where('student_id', $student->id)->get();

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->start_on->toDateString())->toBe('2026-07-15')
            ->and(FailedImportRow::query()->count())->toBe(0)
            ->and(Import::query()->get()->every(fn (Import $import): bool => $import->successful_rows === 1))->toBeTrue();
    });

    it('AC-8: fails for left, soft-deleted students and inactive rooms or dormitories', function (Closure $arrange) {
        $setup = importPenghuniSetup();
        $arrange($setup);

        importPenghuniRun(asramaUser('kesantrian'), [importPenghuniRow()]);

        expect(RoomAssignment::query()->count())->toBe(0)->and(FailedImportRow::query()->count())->toBe(1);
    })->with([
        'student left' => [fn (array $s) => $s['student']->update(['status' => 'lulus'])],
        'student soft-deleted' => [fn (array $s) => $s['student']->delete()],
        'room inactive' => [fn (array $s) => $s['room']->update(['is_active' => false])],
        'dormitory inactive' => [fn (array $s) => $s['dormitory']->update(['is_active' => false])],
    ]);
});

describe('AC-9: cakupan jenjang', function () {
    it('fails NIS of another unit and places in-scope students, for a scoped importer', function () {
        config(['queue.default' => 'sync']);
        ['dormitory' => $dormitory] = importPenghuniSetup(10, 'SLTP');
        asramaStudent('L', ['nis' => '2026002', 'unit_id' => Unit::query()->where('code', 'SD')->value('id')]);
        $user = asramaUser('kesantrian', ['SD']);

        importPenghuniRun($user, [
            importPenghuniRow(),
            importPenghuniRow(['nis' => '2026002']),
        ]);

        expect(Student::withoutUnitScope()->where('nis', '2026001')->first()->roomAssignments()->count())->toBe(0)
            ->and(RoomAssignment::withoutUnitScope()->count())->toBe(1)
            ->and(FailedImportRow::query()->count())->toBe(1)
            ->and($dormitory->exists)->toBeTrue();
    });

    it('applies the unit scope inside the database queue worker too, and notifies when it finishes', function () {
        config(['queue.default' => 'database']);
        importPenghuniSetup(10, 'SLTP');
        asramaStudent('L', ['nis' => '2026002', 'unit_id' => Unit::query()->where('code', 'SD')->value('id')]);
        $user = asramaUser('kesantrian', ['SD']);

        importPenghuniRun($user, [importPenghuniRow(), importPenghuniRow(['nis' => '2026002'])]);

        expect(RoomAssignment::withoutUnitScope()->count())->toBe(0)
            ->and(DB::table('jobs')->count())->toBeGreaterThan(0);

        Artisan::call('queue:work', ['--stop-when-empty' => true, '--memory' => 2048]);

        expect(RoomAssignment::withoutUnitScope()->count())->toBe(1)
            ->and(RoomAssignment::withoutUnitScope()->first()->student->nis)->toBe('2026002')
            ->and(FailedImportRow::query()->count())->toBe(1)
            ->and($user->notifications()->count())->toBe(1);
    });

    it('lets an importer without unit restriction place students of every unit', function () {
        config(['queue.default' => 'sync']);
        importPenghuniSetup(10, 'SLTP');
        asramaStudent('L', ['nis' => '2026002', 'unit_id' => Unit::query()->where('code', 'SD')->value('id')]);

        importPenghuniRun(asramaUser('kesantrian'), [importPenghuniRow(), importPenghuniRow(['nis' => '2026002'])]);

        expect(RoomAssignment::withoutUnitScope()->count())->toBe(2)->and(FailedImportRow::query()->count())->toBe(0);
    });
});

describe('AC-10 dan AC-11: file gagal aman, notifikasi, antrean', function () {
    it('prevents formula injection in the failed rows file', function () {
        expect(RoomAssignmentImporter::shouldPreventFormulaInjection())->toBeTrue();

        importPenghuniRun(asramaUser('kesantrian'), [importPenghuniRow(['nis' => '=HYPERLINK("http://x")', 'dormitory' => '@SUM(A1)', 'room' => '+cmd|x'])]);

        $import = Import::query()->latest('id')->firstOrFail();
        $csv = Writer::fromString();
        (new CsvImportFailureContentGenerator)($import, $csv);
        $content = $csv->toString();

        expect($content)->toContain("'=HYPERLINK")
            ->and($content)->toContain("'@SUM(A1)")
            ->and($content)->toContain("'+cmd|");
    });

    it('imports immediately and notifies the importer with the sync queue', function () {
        config(['queue.default' => 'sync']);
        importPenghuniSetup();
        $user = asramaUser('kesantrian');

        importPenghuniRun($user, [importPenghuniRow()]);

        expect(RoomAssignment::query()->count())->toBe(1)
            ->and($user->notifications()->count())->toBe(1)
            ->and($user->notifications()->first()->data['body'])->toContain('1');
    });

    it('waits for the worker with the database queue and finishes after queue:work --stop-when-empty', function () {
        config(['queue.default' => 'database']);
        importPenghuniSetup();
        $user = asramaUser('kesantrian');

        importPenghuniRun($user, [importPenghuniRow()]);

        expect(RoomAssignment::query()->count())->toBe(0)->and(DB::table('jobs')->count())->toBeGreaterThan(0);

        Artisan::call('queue:work', ['--stop-when-empty' => true, '--memory' => 2048]);

        expect(RoomAssignment::query()->count())->toBe(1)->and($user->notifications()->count())->toBe(1);
    });
});
