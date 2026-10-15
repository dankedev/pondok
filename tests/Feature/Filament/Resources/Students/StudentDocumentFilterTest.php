<?php

use App\Filament\Resources\Students\Pages\ListStudents;
use App\Models\Setting;
use App\Models\Student;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = dokumenFixture();
    $this->actingAs($this->f->admin);
});

/**
 * Santri SD lengkap (KK dan AKTA valid), santri SD dengan KK menunggu, dan santri SD tanpa berkas.
 *
 * @return array{complete: Student, pending: Student, missing: Student}
 */
function dokumenFilterStudents(object $f): array
{
    $make = fn (string $name): Student => Student::factory()->forCohort($f->cohortSd)->create(['name' => $name]);
    $complete = $make('Santri Lengkap');
    $pending = $make('Santri Menunggu');
    $missing = $make('Santri Kosong');

    dokumenValid($complete, 'KK');
    dokumenValid($complete, 'AKTA');
    dokumenCreate($pending, 'KK');
    dokumenValid($pending, 'AKTA');

    return ['complete' => $complete, 'pending' => $pending, 'missing' => $missing];
}

it('AC-39: filters students by required documents', function () {
    $students = dokumenFilterStudents($this->f);

    Livewire::test(ListStudents::class)
        ->assertTableFilterExists('required_documents')
        ->filterTable('required_documents', false)
        ->assertCanSeeTableRecords([$students['pending'], $students['missing'], $this->f->s1, $this->f->s2])
        ->assertCanNotSeeTableRecords([$students['complete']])
        ->removeTableFilter('required_documents')
        ->filterTable('required_documents', true)
        ->assertCanSeeTableRecords([$students['complete']])
        ->assertCanNotSeeTableRecords([$students['pending'], $students['missing'], $this->f->s1, $this->f->s2])
        ->removeTableFilter('required_documents')
        ->assertCanSeeTableRecords([$students['complete'], $students['pending'], $students['missing']]);
});

it('AC-39: labels the filter options Lengkap and Belum lengkap', function () {
    $text = tagihanPlainText(Livewire::test(ListStudents::class));

    expect($text)->toContain('Berkas wajib')
        ->and(__('pondok.document.completeness_labels.1'))->toBe('Lengkap')
        ->and(__('pondok.document.completeness_labels.0'))->toBe('Belum lengkap')
        ->and(__('pondok.document.completeness_filter'))->toBe('Berkas wajib');
});

it('AC-39: combines the document filter with the unit scope of the user', function () {
    $students = dokumenFilterStudents($this->f);
    $this->actingAs(keuanganUser('admin-sekolah', ['SLTP']));

    Livewire::test(ListStudents::class)
        ->filterTable('required_documents', false)
        ->assertCanSeeTableRecords([$this->f->s2])
        ->assertCanNotSeeTableRecords([$students['pending'], $students['missing'], $students['complete']]);
});

it('AC-39: hides the filter when the dokumen module is off', function () {
    Setting::set('modules.dokumen.enabled', false);
    dokumenFilterStudents($this->f);

    $component = Livewire::test(ListStudents::class)->assertSuccessful();

    expect($component->instance()->getTable()->getFilter('required_documents'))->toBeNull();

    $this->get('/admin/students')->assertOk();
});

it('AC-39: does not run a query per student for the filter', function () {
    $students = dokumenFilterStudents($this->f);
    $count = fn () => dokumenQueryCount(fn () => Livewire::test(ListStudents::class)->filterTable('required_documents', false));
    $few = $count();

    foreach (range(1, 6) as $ignored) {
        dokumenCreate(Student::factory()->forCohort($this->f->cohortSd)->create(), 'KK');
    }

    expect($count())->toBeLessThanOrEqual($few + 1)
        ->and($students)->toHaveCount(3);
});
