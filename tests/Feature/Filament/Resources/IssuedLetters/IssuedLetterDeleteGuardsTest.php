<?php

use App\Actions\Dokumen\CancelIssuedLetter;
use App\Filament\Resources\Staff\Pages\EditStaff;
use App\Filament\Resources\Staff\Pages\ListStaff;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\IssuedLetter;
use App\Models\Staff;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = suratFixture();
    $this->actingAs(suratUser('super-admin'));
});

it('AC-38: protects staff referenced by letters', function () {
    $f = $this->f;
    suratIssue($f);
    $free = Staff::factory()->create();

    Livewire::test(EditStaff::class, ['record' => $f->t1->id])->assertActionHidden('delete');
    Livewire::test(EditStaff::class, ['record' => $free->id])->assertActionVisible('delete');
});

it('AC-38: refuses the delete call for a staff member who signed a letter', function () {
    $f = $this->f;
    suratIssue($f);

    Livewire::test(EditStaff::class, ['record' => $f->t1->id])->mountAction('delete')->assertActionNotMounted('delete');

    try {
        Livewire::test(EditStaff::class, ['record' => $f->t1->id])->callAction('delete');
    } catch (Throwable) {
    }

    expect(Staff::query()->find($f->t1->id))->not->toBeNull()
        ->and(DB::table('issued_letters')->count())->toBe(1);
});

it('AC-38: protects staff who only signed a cancelled letter', function () {
    $f = $this->f;
    app(CancelIssuedLetter::class)->handle(suratIssue($f), $f->admin, 'Salah');

    Livewire::test(EditStaff::class, ['record' => $f->t1->id])->assertActionHidden('delete');
});

it('AC-38: still deletes a staff member without letters', function () {
    $free = Staff::factory()->create();

    Livewire::test(EditStaff::class, ['record' => $free->id])->assertActionVisible('delete')->callAction('delete');

    expect(Staff::query()->find($free->id))->toBeNull();
});

it('AC-38: protects users who issued or cancelled letters', function () {
    $f = $this->f;
    $canceller = suratUser('admin-sekolah');
    $free = suratUser('admin-sekolah');
    $letter = suratIssue($f);
    app(CancelIssuedLetter::class)->handle($letter, $canceller, 'Salah');

    Livewire::test(EditUser::class, ['record' => $f->admin->id])->assertActionHidden('delete');
    Livewire::test(EditUser::class, ['record' => $canceller->id])->assertActionHidden('delete');
    Livewire::test(EditUser::class, ['record' => $free->id])->assertActionVisible('delete');
});

it('AC-38: refuses the delete call for a user who issued a letter', function () {
    $f = $this->f;
    suratIssue($f);

    Livewire::test(EditUser::class, ['record' => $f->admin->id])->mountAction('delete')->assertActionNotMounted('delete');

    try {
        Livewire::test(EditUser::class, ['record' => $f->admin->id])->callAction('delete');
    } catch (Throwable) {
    }

    expect(User::query()->find($f->admin->id))->not->toBeNull()
        ->and(IssuedLetter::withoutGlobalScopes()->count())->toBe(1);
});

it('AC-38: still deletes a user without letters', function () {
    $free = suratUser('admin-sekolah');

    Livewire::test(EditUser::class, ['record' => $free->id])->assertActionVisible('delete')->callAction('delete');

    expect(User::query()->find($free->id))->toBeNull();
});

it('AC-38: counts letters without the unit scope', function () {
    $f = $this->f;
    suratIssue($f);
    $this->actingAs($f->scoped);

    expect(IssuedLetter::query()->count())->toBe(0);

    Livewire::test(EditStaff::class, ['record' => $f->t1->id])->assertActionHidden('delete');
});

it('AC-38: guards deletion from the tables whenever they offer it', function () {
    $f = $this->f;
    suratIssue($f);
    $free = Staff::factory()->create();

    $staffTable = Livewire::test(ListStaff::class)->instance()->getTable();
    $userTable = Livewire::test(ListUsers::class)->instance()->getTable();

    if (array_key_exists('delete', $staffTable->getFlatActions())) {
        Livewire::test(ListStaff::class)
            ->assertActionHidden(TestAction::make('delete')->table($f->t1))
            ->assertActionVisible(TestAction::make('delete')->table($free));
    }

    if (array_key_exists('delete', $userTable->getFlatActions())) {
        Livewire::test(ListUsers::class)->assertActionHidden(TestAction::make('delete')->table($f->admin));
    }

    foreach ([$staffTable, $userTable] as $table) {
        expect(array_keys($table->getFlatBulkActions()))->not->toContain('delete');
    }
});
