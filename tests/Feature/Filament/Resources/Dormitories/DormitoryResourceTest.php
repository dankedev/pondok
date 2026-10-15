<?php

use App\Filament\Resources\Dormitories\Pages\CreateDormitory;
use App\Filament\Resources\Dormitories\Pages\EditDormitory;
use App\Filament\Resources\Dormitories\Pages\ListDormitories;
use App\Filament\Resources\Dormitories\RelationManagers\RoomsRelationManager;
use App\Filament\Resources\Dormitories\RelationManagers\SupervisorsRelationManager;
use App\Models\Dormitory;
use App\Models\DormitorySupervisor;
use App\Models\RoomAssignment;
use App\Models\Staff;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->year = asramaActiveYear();
    $this->actingAs(asramaUser('kesantrian'));
});

function asramaRoomsManager(Dormitory $dormitory)
{
    return Livewire::test(RoomsRelationManager::class, ['ownerRecord' => $dormitory, 'pageClass' => EditDormitory::class]);
}

it('AC-35: creates a dormitory and its rooms', function () {
    Livewire::test(CreateDormitory::class)
        ->fillForm(['code' => 'ABK-1', 'name' => 'Asrama Abu Bakar', 'gender' => 'L', 'location' => 'Blok A', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $dormitory = Dormitory::query()->where('code', 'ABK-1')->sole();

    asramaRoomsManager($dormitory)
        ->callAction(TestAction::make('create')->table(), ['name' => 'Kamar 101', 'floor' => 1, 'capacity' => 4, 'is_active' => true])
        ->assertHasNoFormErrors();

    expect($dormitory->rooms()->sole())->name->toBe('Kamar 101')->capacity->toBe(4);
});

it('AC-35: rejects a duplicate room name in one dormitory', function () {
    $room = asramaRoom(4);

    asramaRoomsManager($room->dormitory)
        ->callAction(TestAction::make('create')->table(), ['name' => $room->name, 'floor' => 1, 'capacity' => 4, 'is_active' => true])
        ->assertHasFormErrors(['name' => 'unique']);

    expect($room->dormitory->rooms()->count())->toBe(1);
});

it('AC-35: rejects a capacity below current occupancy', function () {
    $room = asramaRoom(3);
    foreach (range(1, 3) as $ignored) {
        asramaResident(asramaStudent('L'), $room);
    }

    $component = asramaRoomsManager($room->dormitory)
        ->callAction(TestAction::make('edit')->table($room), ['name' => $room->name, 'floor' => 1, 'capacity' => 2, 'is_active' => true]);

    $notified = collect(asramaNotifications())->contains(fn (array $notification): bool => preg_match(asramaMessagePattern('capacity_below_occupancy'), (string) ($notification['title'] ?? '')) === 1
        || preg_match(asramaMessagePattern('capacity_below_occupancy'), (string) ($notification['body'] ?? '')) === 1);

    expect($room->fresh()->capacity)->toBe(3)
        ->and($component->errors()->isNotEmpty() || $notified)->toBeTrue();
});

it('AC-35: hides delete for dormitories and rooms with history', function () {
    $withSupervisorHistory = asramaDormitory();
    DormitorySupervisor::factory()->ended()->create(['dormitory_id' => $withSupervisorHistory->id, 'room_id' => null]);
    $clean = asramaDormitory();

    Livewire::test(ListDormitories::class)
        ->assertActionHidden(TestAction::make('delete')->table($withSupervisorHistory))
        ->assertActionVisible(TestAction::make('delete')->table($clean));

    $dormitory = asramaDormitory();
    $roomWithAssignments = asramaRoom(4, 'L', [], $dormitory);
    $roomWithSupervisors = asramaRoom(4, 'L', [], $dormitory);
    $cleanRoom = asramaRoom(4, 'L', [], $dormitory);
    RoomAssignment::factory()->ended()->create(['room_id' => $roomWithAssignments->id]);
    DormitorySupervisor::factory()->ended()->forRoom($roomWithSupervisors)->create();

    asramaRoomsManager($dormitory)
        ->assertActionHidden(TestAction::make('delete')->table($roomWithAssignments))
        ->assertActionHidden(TestAction::make('delete')->table($roomWithSupervisors))
        ->assertActionVisible(TestAction::make('delete')->table($cleanRoom));
});

it('AC-36: assigns and ends a supervisor', function () {
    $dormitory = asramaDormitory();
    $staff = Staff::factory()->create();

    $component = Livewire::test(SupervisorsRelationManager::class, ['ownerRecord' => $dormitory, 'pageClass' => EditDormitory::class])
        ->callAction(TestAction::make('assignSupervisor')->table(), [
            'staff_id' => $staff->id,
            'role' => 'musyrif',
            'academic_year_id' => $this->year->id,
            'start_on' => '2026-07-15',
        ])
        ->assertHasNoFormErrors();

    $supervisor = DormitorySupervisor::query()->where('staff_id', $staff->id)->sole();
    expect($supervisor)->end_on->toBeNull()->room_id->toBeNull();

    $component
        ->callAction(TestAction::make('endSupervision')->table($supervisor), ['end_on' => '2026-10-01'])
        ->assertHasNoFormErrors();

    expect($supervisor->fresh()->end_on->toDateString())->toBe('2026-10-01')
        ->and(DormitorySupervisor::query()->count())->toBe(1);
});
