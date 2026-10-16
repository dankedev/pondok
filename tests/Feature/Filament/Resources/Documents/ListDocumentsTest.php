<?php

use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Models\Document;
use App\Models\Guardian;
use App\Models\Setting;
use App\Models\Student;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = dokumenFixture();
});

/**
 * Santri pemilik dokumen uji: S1 (SD) memegang KK menunggu, S2 (SLTP) memegang ijazah menunggu, S1 memegang rapor valid
 * dan rapor lama yang sudah diganti rapor baru.
 *
 * @return object{kk: Document, ijazah: Document, valid: Document, old: Document, new: Document}
 */
function dokumenListRows(object $f): object
{
    $old = dokumenCreate($f->s1, 'RAPOR');
    $new = dokumenCreate($f->s1, 'RAPOR');
    $old->forceFill(['replaced_by_id' => $new->id])->save();

    return (object) [
        'kk' => dokumenCreate($f->s1, 'KK', ['number' => '3201234567890001']),
        'ijazah' => dokumenCreate($f->s2, 'IJAZAH'),
        'valid' => dokumenValid($f->s1, 'FOTO'),
        'old' => $old->fresh(),
        'new' => $new,
    ];
}

it('AC-34: lists the verification queue', function () {
    $rows = dokumenListRows($this->f);
    $this->actingAs($this->f->admin);

    $component = Livewire::test(ListDocuments::class)
        ->assertCanSeeTableRecords([$rows->kk, $rows->ijazah, $rows->new])
        ->assertCanNotSeeTableRecords([$rows->valid, $rows->old]);

    $component->removeTableFilter('status')
        ->assertCanSeeTableRecords([$rows->valid])
        ->assertCanNotSeeTableRecords([$rows->old])
        ->removeTableFilter('current')
        ->assertCanSeeTableRecords([$rows->old, $rows->valid, $rows->new]);

    expect(tagihanPlainText($component))->toContain('Diganti');
});

it('AC-34: filters by status', function () {
    $rows = dokumenListRows($this->f);
    $this->actingAs($this->f->admin);

    Livewire::test(ListDocuments::class)
        ->filterTable('status', 'valid')
        ->assertCanSeeTableRecords([$rows->valid])
        ->assertCanNotSeeTableRecords([$rows->kk, $rows->ijazah, $rows->new]);
});

it('AC-34: searches by owner name and NIS', function () {
    $rows = dokumenListRows($this->f);
    $this->actingAs($this->f->admin);

    Livewire::test(ListDocuments::class)
        ->searchTable('Budi Dua')
        ->assertCanSeeTableRecords([$rows->ijazah])
        ->assertCanNotSeeTableRecords([$rows->kk, $rows->new])
        ->searchTable('1001')
        ->assertCanSeeTableRecords([$rows->kk, $rows->new])
        ->assertCanNotSeeTableRecords([$rows->ijazah]);
});

it('AC-34: finds guardian and staff documents by the owner name', function () {
    $guardianType = dokumenType(['code' => 'WALIDOK', 'owner' => 'wali']);
    $staffType = dokumenType(['code' => 'STAFFDOK', 'owner' => 'staff']);
    $guardian = dokumenCreate($this->f->g1, $guardianType);
    $staff = dokumenCreate($this->f->t1, $staffType);
    $student = dokumenCreate($this->f->s1, 'KK');
    $this->actingAs($this->f->admin);

    Livewire::test(ListDocuments::class)
        ->searchTable('Wali Satu')->assertCanSeeTableRecords([$guardian])->assertCanNotSeeTableRecords([$student, $staff])
        ->searchTable('Staff Satu')->assertCanSeeTableRecords([$staff])->assertCanNotSeeTableRecords([$student, $guardian]);
});

it('AC-34: filters by document type, owner type, unit and expiry', function () {
    $f = $this->f;
    $rows = dokumenListRows($f);
    $guardianType = dokumenType(['code' => 'WALIDOK', 'owner' => 'wali']);
    $guardian = dokumenCreate($f->g1, $guardianType);
    $expired = dokumenCreate($f->s2, 'BPJS', ['expires_on' => '2026-10-14']);
    $fresh = dokumenCreate($f->s2, 'BPJS', ['expires_on' => '2027-10-14']);
    $this->actingAs($f->admin);

    Livewire::test(ListDocuments::class)
        ->filterTable('document_type_id', $f->types['KK']->id)
        ->assertCanSeeTableRecords([$rows->kk])->assertCanNotSeeTableRecords([$rows->ijazah, $guardian, $expired])
        ->removeTableFilter('document_type_id')
        ->filterTable('documentable_type', 'guardian')
        ->assertCanSeeTableRecords([$guardian])->assertCanNotSeeTableRecords([$rows->kk, $rows->ijazah])
        ->removeTableFilter('documentable_type')
        ->filterTable('unit_id', $f->sltp->id)
        ->assertCanSeeTableRecords([$rows->ijazah, $expired, $fresh])->assertCanNotSeeTableRecords([$rows->kk, $guardian])
        ->removeTableFilter('unit_id')
        ->filterTable('expired', true)
        ->assertCanSeeTableRecords([$expired])->assertCanNotSeeTableRecords([$fresh, $rows->kk]);
});

it('AC-34: shows masked numbers, dates and owners and never the full number', function () {
    $f = $this->f;
    dokumenListRows($f);
    dokumenCreate($f->s2, 'BPJS', ['expires_on' => '2026-10-14']);
    $this->actingAs($f->admin);

    $text = tagihanPlainText(Livewire::test(ListDocuments::class));

    expect($text)->toContain('3201••••••••0001')
        ->not->toContain('3201234567890001')
        ->toContain('Ahmad Satu')->toContain('Budi Dua')->toContain('Kartu Keluarga')
        ->toContain('14 Okt 2026')->toContain('Menunggu')
        ->not->toContain('pondok.document.')->not->toContain('pondok.navigation.');
});

it('AC-34: has no create, edit, delete or bulk actions', function () {
    $rows = dokumenListRows($this->f);
    $this->actingAs(keuanganUser('super-admin'));

    Livewire::test(ListDocuments::class)
        ->assertActionDoesNotExist('create')
        ->assertActionDoesNotExist(TestAction::make('edit')->table($rows->kk))
        ->assertActionDoesNotExist(TestAction::make('delete')->table($rows->kk))
        ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());

    expect(array_keys(DocumentResource::getPages()))->toBe(['index']);
});

it('AC-34: sorts the newest documents first', function () {
    $older = dokumenCreate($this->f->s1, 'KK', ['created_at' => '2026-10-01 08:00:00']);
    $newer = dokumenCreate($this->f->s2, 'KK', ['created_at' => '2026-10-10 08:00:00']);
    $this->actingAs($this->f->admin);

    Livewire::test(ListDocuments::class)->assertCanSeeTableRecords([$newer, $older], inOrder: true);
});

it('AC-34: shows the empty states', function () {
    $this->actingAs($this->f->admin);

    $queue = Livewire::test(ListDocuments::class);

    expect(tagihanPlainText($queue))->toContain('Tidak ada dokumen yang menunggu verifikasi.');

    $all = Livewire::test(ListDocuments::class)->removeTableFilter('status')->removeTableFilter('current');

    expect(tagihanPlainText($all))->toContain('Belum ada dokumen.');
});

it('AC-34: does not query per row', function () {
    $f = $this->f;
    $this->actingAs($f->admin);
    dokumenCreate($f->s1, 'KK', ['number' => '3201234567890001']);
    $few = dokumenQueryCount(fn () => Livewire::test(ListDocuments::class));

    foreach (range(1, 6) as $i) {
        $student = Student::factory()->forCohort($i % 2 ? $f->cohortSd : $f->cohortSltp)->create();
        dokumenCreate($student, 'KK', ['number' => "32012345678900{$i}1"]);
        dokumenCreate($f->t1, dokumenType(['owner' => 'staff']));
        dokumenCreate(Guardian::factory()->create(), dokumenType(['owner' => 'wali']));
    }

    expect(dokumenQueryCount(fn () => Livewire::test(ListDocuments::class)))->toBeLessThanOrEqual($few + 4);
});

it('AC-34: scopes the list to the units of the user', function () {
    $rows = dokumenListRows($this->f);
    $this->actingAs(keuanganUser('admin-sekolah', ['SLTP']));

    Livewire::test(ListDocuments::class)
        ->assertCanSeeTableRecords([$rows->ijazah])
        ->assertCanNotSeeTableRecords([$rows->kk, $rows->new]);
});

it('AC-34: offers only the units of the user in the unit filter', function () {
    $this->actingAs(keuanganUser('admin-sekolah', ['SLTP']));

    Livewire::test(ListDocuments::class)
        ->assertTableFilterExists('unit_id', fn ($filter): bool => array_keys($filter->getOptions()) === [$this->f->sltp->id]);
});

it('AC-34: hides confidential data from kesantrian', function () {
    $f = $this->f;
    $rows = dokumenListRows($f);
    $ijazah = dokumenCreate($f->s1, 'IJAZAH', ['number' => 'IJZ-2020-0099-ABCD']);
    $guardianType = dokumenType(['code' => 'WALIDOK', 'owner' => 'wali']);
    $guardian = dokumenCreate($f->g1, $guardianType);
    $this->actingAs(keuanganUser('kesantrian'));

    $component = Livewire::test(ListDocuments::class)
        ->assertCanSeeTableRecords([$rows->kk, $ijazah])
        ->assertCanNotSeeTableRecords([$guardian])
        ->assertActionHidden(TestAction::make('download')->table($rows->kk))
        ->assertActionHidden(TestAction::make('verify')->table($rows->kk))
        ->assertActionVisible(TestAction::make('download')->table($ijazah))
        ->assertActionHidden(TestAction::make('verify')->table($ijazah))
        ->assertActionHidden(TestAction::make('replace')->table($ijazah))
        ->assertActionVisible(TestAction::make('view')->table($rows->kk))
        ->assertTableFilterExists('documentable_type', fn ($filter): bool => array_keys($filter->getOptions()) === ['student', 'achievement']);

    $text = tagihanPlainText($component);

    expect($text)->toContain('Rahasia')
        ->not->toContain('3201')
        ->not->toContain('3201234567890001')
        ->toContain('IJZ-')->not->toContain('IJZ-2020-0099-ABCD');
});

it('AC-34: shows the full number and file name in the detail only to users who may download', function () {
    $f = $this->f;
    $rows = dokumenListRows($f);
    $ijazah = dokumenCreate($f->s1, 'IJAZAH', ['number' => 'IJZ-2020-0099-ABCD', 'original_name' => 'ijazah-asli.pdf']);

    $this->actingAs($f->admin);
    $admin = Livewire::test(ListDocuments::class)->mountAction(TestAction::make('view')->table($rows->kk));

    expect(tagihanPlainText($admin))->toContain('3201234567890001')->toContain('asli-kk.pdf');

    $this->actingAs(keuanganUser('kesantrian'));
    $own = Livewire::test(ListDocuments::class)->mountAction(TestAction::make('view')->table($ijazah));

    expect(tagihanPlainText($own))->toContain('IJZ-2020-0099-ABCD')->toContain('ijazah-asli.pdf');

    $secret = Livewire::test(ListDocuments::class)->mountAction(TestAction::make('view')->table($rows->kk));

    expect(tagihanPlainText($secret))->not->toContain('3201234567890001')->not->toContain('asli-kk.pdf');
});

it('AC-34: builds the download link from one place', function () {
    $document = dokumenCreate($this->f->s1, 'KK');

    expect(DocumentResource::downloadUrl($document))->toEndWith("/admin/documents/{$document->id}/download");
});

it('AC-34: refuses other roles and the page when the module is off', function () {
    $this->actingAs(keuanganUser('bendahara'));
    Livewire::test(ListDocuments::class)->assertForbidden();

    Setting::set('modules.dokumen.enabled', false);
    $this->actingAs($this->f->admin);
    Livewire::test(ListDocuments::class)->assertForbidden();
});

it('AC-35: verifies a document from the list', function () {
    $document = dokumenCreate($this->f->s1, 'KK');
    $this->actingAs($this->f->admin);

    Livewire::test(ListDocuments::class)
        ->assertActionVisible(TestAction::make('verify')->table($document))
        ->callAction(TestAction::make('verify')->table($document), ['status' => 'valid'])
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($document->fresh())->status->toBe('valid')->verified_by->toBe($this->f->admin->id);
});

it('AC-35: offers only valid and ditolak in the verification modal', function () {
    $document = dokumenCreate($this->f->s1, 'KK');
    $this->actingAs($this->f->admin);

    Livewire::test(ListDocuments::class)
        ->mountAction(TestAction::make('verify')->table($document))
        ->assertSchemaComponentExists('status', checkComponentUsing: fn ($component): bool => array_keys($component->getOptions()) === ['valid', 'ditolak']);
});

it('AC-35: rejects a document from the list', function () {
    $document = dokumenCreate($this->f->s1, 'KK');
    $this->actingAs($this->f->admin);

    Livewire::test(ListDocuments::class)
        ->assertActionVisible(TestAction::make('verify')->table($document))
        ->callAction(TestAction::make('verify')->table($document), ['status' => 'ditolak']);

    expect($document->fresh()->status)->toBe('ditolak');
});

it('AC-35: shows the expiry error on the status field and keeps the status', function () {
    $expired = dokumenCreate($this->f->s1, 'BPJS', ['expires_on' => '2026-10-14']);
    $this->actingAs($this->f->admin);

    $component = Livewire::test(ListDocuments::class)
        ->assertActionVisible(TestAction::make('verify')->table($expired))
        ->callAction(TestAction::make('verify')->table($expired), ['status' => 'valid'])
        ->assertHasFormErrors(['status']);

    expect($expired->fresh()->status)->toBe('menunggu')
        ->and(dokumenSeeMessage($component, 'document_expired'))->toBeTrue();
});

it('AC-35: hides verification for replaced documents', function () {
    $rows = dokumenListRows($this->f);
    $this->actingAs($this->f->admin);

    Livewire::test(ListDocuments::class)
        ->removeTableFilter('status')->removeTableFilter('current')
        ->assertCanSeeTableRecords([$rows->old])
        ->assertActionHidden(TestAction::make('verify')->table($rows->old))
        ->assertActionHidden(TestAction::make('replace')->table($rows->old))
        ->assertActionVisible(TestAction::make('verify')->table($rows->new))
        ->assertActionVisible(TestAction::make('replace')->table($rows->new));
});

it('AC-35: refuses a verification run without the verify permission', function () {
    $document = dokumenCreate($this->f->s1, 'IJAZAH');
    $this->actingAs(keuanganUser('kesantrian'));

    Livewire::test(ListDocuments::class)
        ->assertActionHidden(TestAction::make('verify')->table($document))
        ->mountAction(TestAction::make('verify')->table($document))
        ->assertActionNotMounted(TestAction::make('verify')->table($document));

    expect($document->fresh()->status)->toBe('menunggu');
});

it('AC-35: refuses to verify a confidential document without the confidential permission', function () {
    $document = dokumenCreate($this->f->s1, 'KK');
    $this->actingAs(dashboardUserWith(['view-any:document', 'view:document', 'verify:document']));

    Livewire::test(ListDocuments::class)
        ->assertActionHidden(TestAction::make('verify')->table($document))
        ->mountAction(TestAction::make('verify')->table($document))
        ->assertActionNotMounted(TestAction::make('verify')->table($document));

    expect($document->fresh()->status)->toBe('menunggu');
});
