<?php

use App\Actions\Asrama\AssignRoom;
use App\Filament\Resources\Dormitories\Pages\EditDormitory;
use App\Filament\Resources\Dormitories\Pages\ListDormitories;
use App\Filament\Resources\Dormitories\RelationManagers\RoomsRelationManager;
use App\Filament\Resources\RoomAssignments\Pages\ManageRoomAssignments;
use App\Filament\Resources\Students\Pages\ListStudents;
use App\Models\Cohort;
use App\Models\DormitorySupervisor;
use App\Models\RoomAssignment;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Unit;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\UnitSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([UnitSeeder::class, RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->year = asramaActiveYear();
    $this->units = Unit::query()->pluck('id', 'code');
});

function asramaUnitStudent(string $unitCode, string $gender = 'L', array $attributes = []): Student
{
    return Student::factory()
        ->forCohort(Cohort::factory()->create(['unit_id' => test()->units[$unitCode]]))
        ->create(['gender' => $gender, ...$attributes]);
}

it('VERIFY: musyrif with a room supervision and a whole-dormitory supervision sees both', function () {
    $dormitoryA = asramaDormitory();
    $dormitoryB = asramaDormitory();
    $roomA = asramaRoom(5, 'L', [], $dormitoryA);
    $roomB1 = asramaRoom(5, 'L', [], $dormitoryB);
    $roomB2 = asramaRoom(5, 'L', [], $dormitoryB);
    $a = asramaResident(asramaStudent(), $roomA);
    $b1 = asramaResident(asramaStudent(), $roomB1);
    $b2 = asramaResident(asramaStudent(), $roomB2);
    $user = asramaUser('musyrif');
    $staff = Staff::factory()->create(['user_id' => $user->id]);
    foreach ([[$dormitoryA, null], [$dormitoryB, $roomB1]] as [$dormitory, $room]) {
        DormitorySupervisor::factory()->create(['staff_id' => $staff->id, 'dormitory_id' => $dormitory->id, 'room_id' => $room?->id, 'academic_year_id' => $this->year->id, 'role' => 'musyrif', 'start_on' => '2026-07-15', 'end_on' => null]);
    }

    $this->actingAs($user);

    Livewire::test(ManageRoomAssignments::class)
        ->assertCanSeeTableRecords([$a, $b1])
        ->assertCanNotSeeTableRecords([$b2]);
});

it('VERIFY: the module switch closes the residents page for livewire as well', function () {
    Setting::set('modules.asrama.enabled', false);
    $this->actingAs(asramaUser('super-admin'));

    $this->get('/admin/room-assignments')->assertForbidden();
});

it('VERIFY: the move action only offers other rooms of an active dormitory with the student gender', function (Closure $makeTarget) {
    $this->actingAs(asramaUser('kesantrian'));
    $student = asramaStudent('L');
    $current = asramaResident($student, asramaRoom(5, 'L'));
    $target = $makeTarget($current);

    Livewire::test(ManageRoomAssignments::class)
        ->callAction(TestAction::make('move')->table($current), ['dormitory_id' => $target->dormitory_id, 'room_id' => $target->id, 'start_on' => '2026-10-01'])
        ->assertHasFormErrors();

    expect($current->fresh()->end_on)->toBeNull()
        ->and(RoomAssignment::query()->where('student_id', $student->id)->count())->toBe(1);
})->with([
    'other gender dormitory' => [fn () => asramaRoom(5, 'P')],
    'inactive dormitory' => [fn () => asramaRoom(5, 'L', [], asramaDormitory('L', ['is_active' => false]))],
    'inactive room' => [fn () => asramaRoom(5, 'L', ['is_active' => false])],
    'the current room' => [fn (RoomAssignment $current) => $current->room],
]);

it('VERIFY: residents of a soft-deleted student stay visible in the residents table', function () {
    $this->actingAs(asramaUser('kesantrian', ['SLTP']));
    $student = asramaUnitStudent('SLTP');
    $assignment = asramaResident($student, asramaRoom(5));
    $student->delete();

    Livewire::test(ManageRoomAssignments::class)
        ->assertCanSeeTableRecords([$assignment])
        ->assertSeeText($student->name);
});

it('VERIFY: occupancy counts every unit in the dormitory table and the rooms relation manager', function () {
    $room = asramaRoom(5, 'L', ['name' => 'Kamar Uji'], asramaDormitory('L', ['name' => 'Asrama Uji']));
    asramaResident(asramaUnitStudent('SD'), $room);
    asramaResident(asramaUnitStudent('SD'), $room);
    asramaResident(asramaUnitStudent('SLTP'), $room);
    $this->actingAs(asramaUser('kesantrian', ['SLTP']));

    Livewire::test(ListDormitories::class)->assertSeeText('3 / 5');
    Livewire::test(RoomsRelationManager::class, ['ownerRecord' => $room->dormitory, 'pageClass' => EditDormitory::class])->assertSeeText('3 / 5');
});

it('VERIFY: editing only the capacity of a room keeps its own name valid', function () {
    $this->actingAs(asramaUser('kesantrian'));
    $room = asramaRoom(3);

    Livewire::test(RoomsRelationManager::class, ['ownerRecord' => $room->dormitory, 'pageClass' => EditDormitory::class])
        ->callAction(TestAction::make('edit')->table($room), ['name' => $room->name, 'floor' => 1, 'capacity' => 6, 'is_active' => true])
        ->assertHasNoFormErrors();

    expect($room->fresh()->capacity)->toBe(6);
});

it('VERIFY: the student table does not run one room query per student', function () {
    $this->actingAs(asramaUser('admin-sekolah'));
    $room = asramaRoom(20);
    foreach (range(1, 6) as $ignored) {
        asramaResident(asramaUnitStudent('SD'), $room);
    }

    DB::enableQueryLog();
    Livewire::test(ListStudents::class)->assertSuccessful();
    $roomQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'from "rooms"') || str_contains($sql, 'from "dormitories"'));

    expect($roomQueries->count())->toBeLessThanOrEqual(3);
});

it('VERIFY: a scoped user does not see residents of other units in the student room column', function () {
    $this->actingAs(asramaUser('admin-sekolah', ['SLTP']));
    $sd = asramaUnitStudent('SD');
    asramaResident($sd, asramaRoom(5, 'L', ['name' => 'Kamar Rahasia']));

    Livewire::test(ListStudents::class)->assertCanNotSeeTableRecords([$sd])->assertDontSeeText('Kamar Rahasia');
});

it('VERIFY: a soft-deleted student cannot be placed in a room', function () {
    $student = asramaStudent('L');
    $student->delete();

    expect(fn () => app(AssignRoom::class)->handle($student, asramaRoom(5, 'L')))
        ->toThrow(ValidationException::class);
    expect(RoomAssignment::query()->count())->toBe(0);
});
