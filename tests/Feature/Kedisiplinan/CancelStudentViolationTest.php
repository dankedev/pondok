<?php

use App\Actions\Kedisiplinan\CancelStudentViolation;
use App\Actions\Kedisiplinan\RecordStudentViolation;
use App\Filament\Resources\StudentViolations\Pages\ListStudentViolations;
use App\Models\StudentViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = disiplinFixture();
});

it('AC-6: cancels with a reason, records who and when, keeps the row and drops it from the total', function () {
    $f = $this->f;
    $keep = disiplinRaw($f, $f->s1, $f->ringan);
    $violation = disiplinRaw($f, $f->s1, $f->sedang);
    $this->actingAs($f->kes);

    app(CancelStudentViolation::class)->handle($f->kes, $violation, 'Salah santri');

    $fresh = $violation->fresh();

    expect($fresh->cancelled_at)->not->toBeNull()
        ->and($fresh->cancelled_by)->toBe($f->kes->id)
        ->and($fresh->cancel_reason)->toBe('Salah santri')
        ->and(disiplinCount())->toBe(2)
        ->and((int) StudentViolation::withoutGlobalScopes()->countable()->where('student_id', $f->s1->id)->sum('points'))->toBe(5)
        ->and(StudentViolation::withoutGlobalScopes()->countable()->pluck('id')->all())->toBe([$keep->id]);

    $logs = disiplinActivities('cancelled');

    expect($logs)->toHaveCount(1)
        ->and(json_encode($logs->first()->properties))->toContain('Salah santri');
});

it('AC-6: rejects an empty, blank or too long reason and leaves the row untouched', function () {
    $f = $this->f;
    $violation = disiplinRaw($f, $f->s1, $f->ringan);
    $this->actingAs($f->kes);

    foreach (['' => 'kosong', '   ' => 'spasi', str_repeat('a', 256) => '256 karakter'] as $reason => $label) {
        try {
            app(CancelStudentViolation::class)->handle($f->kes, $violation, (string) $reason);
            $this->fail("{$label}: ValidationException diharapkan");
        } catch (ValidationException $exception) {
            expect($exception->errors())->not->toBeEmpty($label);
        }
    }

    expect($violation->fresh()->cancelled_at)->toBeNull()->and(disiplinActivities('cancelled'))->toHaveCount(0);
});

it('AC-6: refuses to cancel twice or to change a cancelled violation', function () {
    $f = $this->f;
    $violation = disiplinRawCancelled($f, $f->s1, $f->ringan, ['cancel_reason' => 'Alasan awal']);
    $this->actingAs($f->kes);

    expect(fn () => app(CancelStudentViolation::class)->handle($f->kes, $violation, 'Alasan lain'))->toThrow(ValidationException::class)
        ->and(fn () => app(RecordStudentViolation::class)->handle($f->kes, disiplinData($f, ['description' => 'diubah']), $violation))->toThrow(ValidationException::class);

    $fresh = $violation->fresh();

    expect($fresh->cancel_reason)->toBe('Alasan awal')->and($fresh->description)->toBeNull();
});

it('AC-6: refuses cancel for musyrif, admin-sekolah and other roles', function () {
    $f = $this->f;
    $violation = disiplinRaw($f, $f->s1, $f->ringan);

    foreach ([$f->musyrif, $f->adminSekolah, $f->waliKelas, $f->bendahara] as $user) {
        $this->actingAs($user);

        expect(fn () => app(CancelStudentViolation::class)->handle($user, $violation, 'Coba batal'))->toThrow(AuthorizationException::class);
    }

    expect($violation->fresh()->cancelled_at)->toBeNull();
});

it('AC-6: shows a cancelled violation with a badge, asks a reason in the cancel action, and offers no delete', function () {
    $f = $this->f;
    $active = disiplinRaw($f, $f->s1, $f->ringan);
    $cancelled = disiplinRawCancelled($f, $f->s2, $f->sedang);
    $this->actingAs($f->kes);

    $component = Livewire::test(ListStudentViolations::class)->assertCanSeeTableRecords([$active, $cancelled]);

    expect(tagihanPlainText($component))->toContain('Dibatalkan');

    $component
        ->assertTableActionHidden('cancel', $cancelled)
        ->assertTableActionDoesNotExist('delete')
        ->callTableAction('cancel', $active, ['cancel_reason' => ''])
        ->assertHasTableActionErrors(['cancel_reason' => ['required']]);

    expect($active->fresh()->cancelled_at)->toBeNull();

    $component->callTableAction('cancel', $active, ['cancel_reason' => 'Dicatat dua kali'])->assertHasNoTableActionErrors();

    expect($active->fresh()->cancelled_at)->not->toBeNull()->and($active->fresh()->cancel_reason)->toBe('Dicatat dua kali');

    foreach ([$f->admin, $f->kes] as $user) {
        $this->actingAs($user);

        expect(Livewire::test(ListStudentViolations::class)->instance()->getTable()->getFlatBulkActions())->toBeEmpty();
    }
});
