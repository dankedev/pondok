<?php

use App\Filament\Resources\RoomAssignments\Pages\ManageRoomAssignments;
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
});

it('AC-28: hides placement actions from musyrif', function () {
    $assignment = asramaResident(asramaStudent(), asramaRoom());
    $staff = Staff::factory()->create(['user_id' => ($user = asramaUser('musyrif'))->id]);
    DormitorySupervisor::factory()->create([
        'staff_id' => $staff->id,
        'dormitory_id' => $assignment->room->dormitory_id,
        'room_id' => null,
        'academic_year_id' => $this->year->id,
        'role' => 'musyrif',
        'start_on' => '2026-07-15',
        'end_on' => null,
    ]);
    $this->actingAs($user);

    Livewire::test(ManageRoomAssignments::class)
        ->assertCanSeeTableRecords([$assignment])
        ->assertActionHidden('assign')
        ->assertActionHidden(TestAction::make('move')->table($assignment))
        ->assertActionHidden(TestAction::make('vacate')->table($assignment));
});

it('AC-33: places a student from the panel', function () {
    $this->actingAs(asramaUser('kesantrian'));
    $student = asramaStudent('L');
    $room = asramaRoom(3, 'L');

    Livewire::test(ManageRoomAssignments::class)
        ->callAction('assign', [
            'student_id' => $student->id,
            'dormitory_id' => $room->dormitory_id,
            'room_id' => $room->id,
            'start_on' => '2026-10-01',
            'note' => 'Santri baru',
        ])
        ->assertHasNoFormErrors();

    expect(RoomAssignment::query()->where('student_id', $student->id)->sole())
        ->room_id->toBe($room->id)
        ->end_on->toBeNull()
        ->note->toBe('Santri baru');
    $statuses = asramaNotifications()->pluck('status')->all();
    expect($statuses)->toContain('success')->not->toContain('danger');
});

it('AC-33: shows a danger notification when the room is full', function () {
    $this->actingAs(asramaUser('kesantrian'));
    $student = asramaStudent('L');
    $room = asramaRoom(1, 'L');
    asramaResident(asramaStudent('L'), $room);

    Livewire::test(ManageRoomAssignments::class)
        ->callAction('assign', [
            'student_id' => $student->id,
            'dormitory_id' => $room->dormitory_id,
            'room_id' => $room->id,
            'start_on' => '2026-10-01',
        ]);

    $danger = asramaNotifications()->firstWhere('status', 'danger');

    expect($student->fresh()->isResident())->toBeFalse()
        ->and(RoomAssignment::query()->count())->toBe(1)
        ->and($danger)->not->toBeNull()
        ->and(preg_match(asramaMessagePattern('room_full'), (string) $danger['title']) === 1 || preg_match(asramaMessagePattern('room_full'), (string) $danger['body']) === 1)->toBeTrue();
});

it('AC-34: moves and vacates from the table', function () {
    $this->actingAs(asramaUser('kesantrian'));
    $student = asramaStudent('L');
    $roomA = asramaRoom(3, 'L');
    $roomB = asramaRoom(3, 'L');
    $assignment = asramaResident($student, $roomA, ['start_on' => '2026-07-15']);

    $component = Livewire::test(ManageRoomAssignments::class)
        ->callAction(TestAction::make('move')->table($assignment), [
            'dormitory_id' => $roomB->dormitory_id,
            'room_id' => $roomB->id,
            'start_on' => '2026-10-01',
            'note' => 'Pindah lantai',
        ])
        ->assertHasNoFormErrors();

    expect(RoomAssignment::query()->where('student_id', $student->id)->count())->toBe(2)
        ->and($assignment->fresh()->end_on->toDateString())->toBe('2026-10-01');

    $current = RoomAssignment::query()->where('student_id', $student->id)->whereNull('end_on')->sole();
    expect($current->room_id)->toBe($roomB->id);

    $component
        ->callAction(TestAction::make('vacate')->table($current), ['end_on' => '2026-10-10', 'note' => 'Lulus'])
        ->assertHasNoFormErrors()
        ->filterTable('status', 'aktif')
        ->assertCanNotSeeTableRecords([$current])
        ->filterTable('status', 'riwayat')
        ->assertCanSeeTableRecords([$current]);

    expect($current->fresh()->end_on->toDateString())->toBe('2026-10-10')
        ->and($student->fresh()->isResident())->toBeFalse();
});
