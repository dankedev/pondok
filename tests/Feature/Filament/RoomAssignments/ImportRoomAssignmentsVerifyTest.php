<?php

use App\Filament\Resources\RoomAssignments\Pages\ManageRoomAssignments;
use App\Models\RoomAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\UnitSeeder;
use Filament\Actions\Imports\Models\FailedImportRow;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, UnitSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    asramaActiveYear();
});

function verifyPenghuniImport(User $user, string $content, array $map = ['nis' => 'nis', 'dormitory' => 'dormitory', 'room' => 'room', 'start_on' => 'start_on'])
{
    test()->actingAs($user);

    return Livewire::test(ManageRoomAssignments::class)->callAction('import', data: [
        'file' => UploadedFile::fake()->createWithContent('penghuni.csv', $content),
        'columnMap' => $map,
    ]);
}

it('keeps the importer authenticated after a sync import on both list pages', function () {
    config(['queue.default' => 'sync']);
    $dormitory = asramaDormitory('L', ['name' => 'Asrama Abu Bakar']);
    asramaRoom(5, 'L', ['name' => 'Kamar 101'], $dormitory);
    asramaStudent('L', ['nis' => '2026001']);
    $user = asramaUser('kesantrian');

    $component = verifyPenghuniImport($user, "nis,dormitory,room,start_on\n2026001,Asrama Abu Bakar,Kamar 101,2026-07-15\n");

    expect(auth()->check())->toBeTrue()
        ->and(auth()->id())->toBe($user->id);

    $component->assertSuccessful();
});

it('places a resident whose dormitory and room names contain non-ASCII capitals (portable case-insensitive match)', function () {
    config(['queue.default' => 'sync']);
    $dormitory = asramaDormitory('L', ['name' => 'Asrama Úmar']);
    asramaRoom(5, 'L', ['name' => 'Ägypten 1'], $dormitory);
    asramaStudent('L', ['nis' => '2026001']);

    verifyPenghuniImport(asramaUser('kesantrian'), "nis,dormitory,room,start_on\n2026001,Asrama Úmar,Ägypten 1,2026-07-15\n");

    expect(FailedImportRow::query()->pluck('validation_error')->all())->toBe([])
        ->and(RoomAssignment::query()->count())->toBe(1);
});

it('reads a CSV with UTF-8 BOM', function () {
    config(['queue.default' => 'sync']);
    $dormitory = asramaDormitory('L', ['name' => 'Asrama Abu Bakar']);
    asramaRoom(5, 'L', ['name' => 'Kamar 101'], $dormitory);
    asramaStudent('L', ['nis' => '2026001']);

    verifyPenghuniImport(asramaUser('kesantrian'), "\xEF\xBB\xBFnis,dormitory,room,start_on\n2026001,Asrama Abu Bakar,Kamar 101,2026-07-15\n");

    expect(RoomAssignment::query()->count())->toBe(1);
});

it('trims spaces around NIS and start_on', function () {
    config(['queue.default' => 'sync']);
    $dormitory = asramaDormitory('L', ['name' => 'Asrama Abu Bakar']);
    asramaRoom(5, 'L', ['name' => 'Kamar 101'], $dormitory);
    asramaStudent('L', ['nis' => '2026001']);

    verifyPenghuniImport(asramaUser('kesantrian'), "nis,dormitory,room,start_on\n \"  2026001 \", Asrama Abu Bakar ,Kamar 101,\" 2026-07-15 \"\n");

    expect(FailedImportRow::query()->pluck('validation_error')->all())->toBe([])
        ->and(RoomAssignment::query()->count())->toBe(1);
});

it('rejects an impossible calendar date without a server error', function () {
    config(['queue.default' => 'sync']);
    $dormitory = asramaDormitory('L', ['name' => 'Asrama Abu Bakar']);
    asramaRoom(5, 'L', ['name' => 'Kamar 101'], $dormitory);
    asramaStudent('L', ['nis' => '2026001']);

    verifyPenghuniImport(asramaUser('kesantrian'), "nis,dormitory,room,start_on\n2026001,Asrama Abu Bakar,Kamar 101,2026-02-30\n");

    expect(RoomAssignment::query()->count())->toBe(0)->and(FailedImportRow::query()->count())->toBe(1);
});

it('handles the same student moved twice in one file with the same start date', function () {
    config(['queue.default' => 'sync']);
    $dormitory = asramaDormitory('L', ['name' => 'Asrama Abu Bakar']);
    asramaRoom(5, 'L', ['name' => 'Kamar A'], $dormitory);
    $b = asramaRoom(5, 'L', ['name' => 'Kamar B'], $dormitory);
    $student = asramaStudent('L', ['nis' => '2026001']);

    verifyPenghuniImport(asramaUser('kesantrian'), "nis,dormitory,room,start_on\n2026001,Asrama Abu Bakar,Kamar A,2026-07-15\n2026001,Asrama Abu Bakar,Kamar B,2026-07-15\n");

    expect(RoomAssignment::query()->where('student_id', $student->id)->whereNull('end_on')->count())->toBe(1)
        ->and(RoomAssignment::query()->where('student_id', $student->id)->whereNull('end_on')->value('room_id'))->toBe($b->id)
        ->and(FailedImportRow::query()->count())->toBe(0);
});
