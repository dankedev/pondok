<?php

use App\Filament\Resources\AdmissionTracks\Pages\ManageAdmissionTracks;
use App\Models\AdmissionTrack;
use App\Models\FeeType;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
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

/**
 * Jenis biaya untuk uji pemetaan: UANG_PANGKAL dan KITAB (`saat_diterima`), FORMULIR (`saat_daftar`).
 *
 * @return array{pangkal: FeeType, kitab: FeeType, formulir: FeeType}
 */
function seleksiTrackFormTypes(): array
{
    return [
        'pangkal' => psbFeeType('saat_diterima', ['code' => 'UANG_PANGKAL', 'name' => 'Uang pangkal', 'billing_group' => 'PSB_TERIMA', 'priority' => 20]),
        'kitab' => psbFeeType('saat_diterima', ['code' => 'KITAB', 'name' => 'Kitab', 'billing_group' => 'PSB_KITAB', 'priority' => 40]),
        'formulir' => psbFeeType('saat_daftar', ['code' => 'FORMULIR', 'name' => 'Formulir pendaftaran', 'billing_group' => 'PSB_DAFTAR', 'priority' => 10]),
    ];
}

it('AC-4 (F011): edits the fee type mapping', function () {
    $types = seleksiTrackFormTypes();
    $track = AdmissionTrack::factory()->create(['code' => 'TAHFIDZ', 'name' => 'Tahfidz', 'scholarship_id' => $this->f->tahfidz->id]);
    seleksiMapFeeType($track, $types['pangkal']);

    Livewire::test(ManageAdmissionTracks::class)
        ->assertActionVisible(TestAction::make('edit')->table($track))
        ->callAction(TestAction::make('edit')->table($track), psbTrackData($this->f, ['code' => 'TAHFIDZ', 'name' => 'Tahfidz', 'fee_type_ids' => [$types['pangkal']->id, $types['kitab']->id]]))
        ->assertHasNoFormErrors();

    expect(DB::table('admission_track_fee_type')->where('admission_track_id', $track->id)->pluck('fee_type_id')->map(fn ($id): int => (int) $id)->sort()->values()->all())
        ->toBe([$types['pangkal']->id, $types['kitab']->id]);
});

it('AC-4 (F011): keeps the mapping when another field is edited', function () {
    $types = seleksiTrackFormTypes();
    $track = AdmissionTrack::factory()->create(['code' => 'TAHFIDZ', 'name' => 'Tahfidz', 'scholarship_id' => $this->f->tahfidz->id]);
    seleksiMapFeeType($track, $types['pangkal']);

    Livewire::test(ManageAdmissionTracks::class)
        ->assertActionVisible(TestAction::make('edit')->table($track))
        ->callAction(TestAction::make('edit')->table($track), ['name' => 'Tahfidz baru'])
        ->assertHasNoFormErrors();

    expect($track->fresh()->name)->toBe('Tahfidz baru')
        ->and(DB::table('admission_track_fee_type')->where('admission_track_id', $track->id)->pluck('fee_type_id')->map(fn ($id): int => (int) $id)->all())->toBe([$types['pangkal']->id]);
});

it('AC-4 (F011): hides the fee type field while no scholarship is chosen', function () {
    $track = AdmissionTrack::factory()->create(['code' => 'REGULER', 'name' => 'Reguler', 'scholarship_id' => null]);
    $withScholarship = AdmissionTrack::factory()->create(['code' => 'TAHFIDZ', 'name' => 'Tahfidz', 'scholarship_id' => $this->f->tahfidz->id]);

    Livewire::test(ManageAdmissionTracks::class)
        ->mountAction(TestAction::make('edit')->table($withScholarship))
        ->assertSchemaComponentExists('fee_type_ids');

    Livewire::test(ManageAdmissionTracks::class)
        ->mountAction(TestAction::make('edit')->table($track))
        ->assertSchemaComponentDoesNotExist('fee_type_ids');
});

it('AC-4 (F011): offers acceptance, monthly and yearly fee types but not registration fees', function () {
    $types = seleksiTrackFormTypes();
    $spp = psbFeeType('bulanan', ['code' => 'SPP', 'name' => 'SPP', 'billing_group' => 'BULANAN']);
    $manual = psbFeeType('manual', ['code' => 'LAIN', 'name' => 'Biaya lain', 'billing_group' => 'MANUAL']);
    $track = AdmissionTrack::factory()->create(['code' => 'TAHFIDZ', 'name' => 'Tahfidz', 'scholarship_id' => $this->f->tahfidz->id]);

    Livewire::test(ManageAdmissionTracks::class)
        ->mountAction(TestAction::make('edit')->table($track))
        ->assertSchemaComponentExists('fee_type_ids', checkComponentUsing: function ($component) use ($types, $spp, $manual): bool {
            $options = $component->getOptions();

            return array_key_exists($types['pangkal']->id, $options)
                && array_key_exists($types['kitab']->id, $options)
                && array_key_exists($spp->id, $options)
                && ! array_key_exists($types['formulir']->id, $options)
                && ! array_key_exists($manual->id, $options)
                && str_contains((string) $options[$types['pangkal']->id], 'Uang pangkal');
        });
});

it('AC-4 (F011): lists the mapped fee types or the fallback text in the table', function () {
    $types = seleksiTrackFormTypes();
    $tahfidz = AdmissionTrack::factory()->create(['code' => 'TAHFIDZ', 'name' => 'Tahfidz', 'scholarship_id' => $this->f->tahfidz->id]);
    $yatim = AdmissionTrack::factory()->create(['code' => 'YATIM', 'name' => 'Yatim', 'scholarship_id' => $this->f->lama->id]);
    seleksiMapFeeType($tahfidz, $types['pangkal']);

    expect(__('pondok.admission_track.all_acceptance_fees'))->toBe('Semua biaya saat diterima');

    $component = Livewire::test(ManageAdmissionTracks::class)->assertCanSeeTableRecords([$tahfidz, $yatim]);

    expect(tagihanPlainText($component))->toContain('Uang pangkal')->toContain('Semua biaya saat diterima')
        ->and(substr_count(tagihanPlainText($component), 'Semua biaya saat diterima'))->toBe(1);
});

it('AC-4 (F011): translates the fee type field texts', function () {
    expect(seleksiText('pondok.admission_track.fee_types'))->toBe('Jenis biaya yang mendapat beasiswa')
        ->and(seleksiText('pondok.admission_track.fee_types_helper'))->toBe('Kosongkan untuk menerapkan beasiswa ke semua biaya saat diterima.');
});
