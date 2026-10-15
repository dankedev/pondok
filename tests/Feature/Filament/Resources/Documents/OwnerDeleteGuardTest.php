<?php

use App\Filament\Resources\Guardians\Pages\EditGuardian;
use App\Filament\Resources\Guardians\Pages\ListGuardians;
use App\Filament\Resources\Staff\Pages\EditStaff;
use App\Models\Document;
use App\Models\Guardian;
use App\Models\Staff;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = dokumenFixture();
    $this->walia = dokumenType(['code' => 'WALIDOK', 'owner' => 'wali']);
    $this->staffType = dokumenType(['code' => 'STAFFDOK', 'owner' => 'staff']);
    $this->actingAs($this->f->admin);
});

it('AC-40: keeps owners with documents from deletion', function () {
    $withDocuments = Guardian::factory()->create();
    $free = Guardian::factory()->create();
    dokumenCreate($withDocuments, $this->walia);
    $staffWith = Staff::factory()->create();
    $staffFree = Staff::factory()->create();
    dokumenCreate($staffWith, $this->staffType);

    Livewire::test(ListGuardians::class)
        ->assertActionHidden(TestAction::make('delete')->table($withDocuments))
        ->assertActionVisible(TestAction::make('delete')->table($free));

    Livewire::test(EditGuardian::class, ['record' => $withDocuments->id])->assertActionHidden('delete');
    Livewire::test(EditGuardian::class, ['record' => $free->id])->assertActionVisible('delete');
    Livewire::test(EditStaff::class, ['record' => $staffWith->id])->assertActionHidden('delete');
    Livewire::test(EditStaff::class, ['record' => $staffFree->id])->assertActionVisible('delete');
});

it('AC-40: refuses the delete call for an owner with documents', function () {
    $guardian = Guardian::factory()->create();
    $staff = Staff::factory()->create();
    dokumenCreate($guardian, $this->walia);
    dokumenCreate($staff, $this->staffType);

    Livewire::test(EditGuardian::class, ['record' => $guardian->id])->mountAction('delete')->assertActionNotMounted('delete');
    Livewire::test(EditStaff::class, ['record' => $staff->id])->mountAction('delete')->assertActionNotMounted('delete');

    foreach ([[EditGuardian::class, $guardian], [EditStaff::class, $staff]] as [$page, $owner]) {
        try {
            Livewire::test($page, ['record' => $owner->id])->callAction('delete');
        } catch (Throwable) {
        }
    }

    expect(Guardian::query()->find($guardian->id))->not->toBeNull()
        ->and(Staff::query()->find($staff->id))->not->toBeNull()
        ->and(DB::table('documents')->count())->toBe(2);
});

it('AC-40: still deletes an owner without documents', function () {
    $free = Guardian::factory()->create();
    $staff = Staff::factory()->create();

    Livewire::test(EditGuardian::class, ['record' => $free->id])->callAction('delete');
    Livewire::test(EditStaff::class, ['record' => $staff->id])->callAction('delete');

    expect(Guardian::query()->find($free->id))->toBeNull()
        ->and(Staff::query()->find($staff->id))->toBeNull();
});

it('AC-40: guards deletion for unit-scoped admins', function () {
    $f = $this->f;
    $alone = Guardian::factory()->create();
    $free = Guardian::factory()->create();
    $staff = Staff::factory()->create();
    dokumenCreate($f->g1, $this->walia);
    dokumenCreate($alone, $this->walia);
    dokumenCreate($staff, $this->staffType);
    $this->actingAs(keuanganUser('admin-sekolah', ['SLTP']));

    expect(Document::query()->count())->toBe(1);

    Livewire::test(ListGuardians::class)
        ->assertActionHidden(TestAction::make('delete')->table($f->g1))
        ->assertActionHidden(TestAction::make('delete')->table($alone))
        ->assertActionVisible(TestAction::make('delete')->table($free));

    Livewire::test(EditGuardian::class, ['record' => $f->g1->id])->assertActionHidden('delete');
    Livewire::test(EditGuardian::class, ['record' => $alone->id])->assertActionHidden('delete');
    Livewire::test(EditGuardian::class, ['record' => $free->id])->assertActionVisible('delete');
    Livewire::test(EditStaff::class, ['record' => $staff->id])->assertActionHidden('delete');
});

it('AC-40: keeps a guardian with students from deletion in the table as before', function () {
    $other = Guardian::factory()->create();
    $other->students()->attach($this->f->s2->id, ['relation' => 'ibu', 'is_billing_contact' => false]);

    Livewire::test(ListGuardians::class)->assertActionHidden(TestAction::make('delete')->table($other));
});
