<?php

use App\Filament\Resources\AdmissionTracks\Pages\ManageAdmissionTracks;
use App\Models\AdmissionTrack;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->f = psbFixture();
    $this->actingAs(psbUser('admin-psb'));
});

it('AC-27: creates a track from the modal', function () {
    Livewire::test(ManageAdmissionTracks::class)
        ->assertActionVisible('create')
        ->callAction('create', psbTrackData($this->f, ['code' => 'tahfidz_2']))
        ->assertHasNoFormErrors();

    $track = AdmissionTrack::query()->sole();

    expect($track)->code->toBe('TAHFIDZ_2')->name->toBe('Tahfidz Lanjutan')->scholarship_id->toBe($this->f->tahfidz->id)->is_active->toBeTrue();

    Livewire::test(ManageAdmissionTracks::class)
        ->assertCanSeeTableRecords([$track])
        ->assertSeeText('Beasiswa Tahfidz');
});

it('AC-27: shows the duplicate code error on the code field', function () {
    AdmissionTrack::factory()->create(['code' => 'REGULER', 'name' => 'Reguler']);

    Livewire::test(ManageAdmissionTracks::class)
        ->assertActionVisible('create')
        ->callAction('create', psbTrackData($this->f, ['code' => 'REGULER']))
        ->assertHasFormErrors(['code']);

    expect(AdmissionTrack::query()->count())->toBe(1);
});

it('AC-27: edits the scholarship of a track', function () {
    $track = AdmissionTrack::factory()->create(['code' => 'TAHFIDZ', 'name' => 'Tahfidz', 'scholarship_id' => null]);

    Livewire::test(ManageAdmissionTracks::class)
        ->assertActionVisible(TestAction::make('edit')->table($track))
        ->callAction(TestAction::make('edit')->table($track), psbTrackData($this->f, ['code' => 'TAHFIDZ', 'name' => 'Tahfidz']))
        ->assertHasNoFormErrors();

    expect($track->fresh()->scholarship_id)->toBe($this->f->tahfidz->id);
});

it('AC-27: offers only active scholarships when creating', function () {
    $f = $this->f;

    Livewire::test(ManageAdmissionTracks::class)
        ->mountAction('create')
        ->assertSchemaComponentExists('scholarship_id', checkComponentUsing: fn ($component): bool => in_array($f->tahfidz->id, array_keys($component->getOptions()), true)
            && ! in_array($f->lama->id, array_keys($component->getOptions()), true));
});

it('AC-27: offers an inactive scholarship only on the track it is linked to', function () {
    $f = $this->f;
    $linked = AdmissionTrack::factory()->create(['code' => 'LAMA', 'name' => 'Lama', 'scholarship_id' => $f->lama->id]);
    $other = AdmissionTrack::factory()->create(['code' => 'BARU', 'name' => 'Baru', 'scholarship_id' => $f->tahfidz->id]);

    Livewire::test(ManageAdmissionTracks::class)
        ->mountAction(TestAction::make('edit')->table($linked))
        ->assertSchemaComponentExists('scholarship_id', checkComponentUsing: fn ($component): bool => in_array($f->lama->id, array_keys($component->getOptions()), true)
            && in_array($f->tahfidz->id, array_keys($component->getOptions()), true));

    Livewire::test(ManageAdmissionTracks::class)
        ->mountAction(TestAction::make('edit')->table($other))
        ->assertSchemaComponentExists('scholarship_id', checkComponentUsing: fn ($component): bool => ! in_array($f->lama->id, array_keys($component->getOptions()), true));
});

it('AC-27: has no delete or bulk delete action', function () {
    $track = AdmissionTrack::factory()->create();

    Livewire::test(ManageAdmissionTracks::class)
        ->assertActionDoesNotExist(TestAction::make('delete')->table($track));

    $table = Livewire::test(ManageAdmissionTracks::class)->instance()->getTable();

    expect($table->getFlatBulkActions())->not->toHaveKey('delete')->and($table->getBulkAction('delete'))->toBeNull();
});

it('AC-27: shows the empty state', function () {
    expect(__('pondok.admission_track.empty'))->not->toBe('pondok.admission_track.empty');

    Livewire::test(ManageAdmissionTracks::class)->assertSeeText(__('pondok.admission_track.empty'));
});

it('AC-27: shows a track without a scholarship with a placeholder', function () {
    $track = AdmissionTrack::factory()->create(['code' => 'REGULER', 'name' => 'Reguler', 'scholarship_id' => null]);

    Livewire::test(ManageAdmissionTracks::class)
        ->assertCanSeeTableRecords([$track])
        ->assertSeeText('Tanpa beasiswa');
});
