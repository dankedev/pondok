<?php

use App\Filament\Resources\AcademicYears\Pages\ManageAcademicYears;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\AcademicYear;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = prestasiFixture();
});

it('AC-37: keeps users and academic years with achievements', function () {
    $f = $this->f;
    $recorder = keuanganUser('kesantrian');
    $verifier = keuanganUser('admin-sekolah');
    $free = keuanganUser('kesantrian');
    prestasiCreate($f, [$f->s2], ['recorded_by' => $recorder->id]);
    prestasiCreate($f, [$f->s2], ['recorded_by' => $f->k->id, 'verified_by' => $verifier->id]);
    $this->actingAs($f->admin);

    Livewire::test(EditUser::class, ['record' => $recorder->id])->assertActionHidden('delete');
    Livewire::test(EditUser::class, ['record' => $verifier->id])->assertActionHidden('delete');
    Livewire::test(EditUser::class, ['record' => $free->id])->assertActionVisible('delete');

    $withAchievement = AcademicYear::factory()->create();
    prestasiCreate($f, [$f->s2], ['academic_year_id' => $withAchievement->id, 'achieved_on' => '2026-03-10']);
    $clean = AcademicYear::factory()->create();

    Livewire::test(ManageAcademicYears::class)
        ->assertActionHidden(TestAction::make('delete')->table($withAchievement))
        ->assertActionVisible(TestAction::make('delete')->table($clean));
});

it('AC-37: counts achievements of students outside the unit scope of the admin', function () {
    $f = $this->f;
    $recorder = keuanganUser('kesantrian');
    $scopedAdmin = keuanganUser('super-admin', ['SLTP']);
    $achievement = prestasiCreate($f, [$f->s1], ['recorded_by' => $recorder->id]);
    $this->actingAs($scopedAdmin);

    Livewire::test(EditUser::class, ['record' => $recorder->id])->assertActionHidden('delete');

    $year = AcademicYear::factory()->create();
    DB::table('achievements')->where('id', $achievement->id)->update(['academic_year_id' => $year->id]);

    Livewire::test(ManageAcademicYears::class)->assertActionHidden(TestAction::make('delete')->table($year));
});

it('AC-37: refuses to delete a user or an academic year that has achievements even when forced', function () {
    $f = $this->f;
    $recorder = keuanganUser('kesantrian');
    prestasiCreate($f, [$f->s2], ['recorded_by' => $recorder->id, 'academic_year_id' => $f->y1->id, 'achieved_on' => '2026-03-10']);
    $this->actingAs($f->admin);

    Livewire::test(EditUser::class, ['record' => $recorder->id])->mountAction('delete')->assertActionNotMounted('delete');

    try {
        Livewire::test(EditUser::class, ['record' => $recorder->id])->callAction('delete');
    } catch (Throwable) {
    }

    try {
        Livewire::test(ManageAcademicYears::class)->callAction(TestAction::make('delete')->table($f->y1));
    } catch (Throwable) {
    }

    expect(User::query()->whereKey($recorder->id)->exists())->toBeTrue()
        ->and(AcademicYear::query()->whereKey($f->y1->id)->exists())->toBeTrue()
        ->and(DB::table('achievements')->count())->toBe(1);
});
