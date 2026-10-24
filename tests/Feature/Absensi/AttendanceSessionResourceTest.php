<?php

use App\Filament\Pages\AttendanceRecap;
use App\Filament\Resources\AttendanceSessions\Pages\CreateAttendanceSession;
use App\Filament\Resources\AttendanceSessions\Pages\EditAttendanceSession;
use App\Filament\Resources\AttendanceSessions\Pages\ListAttendanceSessions;
use App\Models\AttendanceSession;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = absensiFixture();
});

/*
| Kontrak form (dipakai test): field `classroom_id`, `held_on`, dan status per santri di `statuses.<student_id>`.
| Rekap: filter `data.classroom_id` dan `data.month` (Y-m); empat sel terakhir tiap baris = Hadir, Sakit, Izin, Alpa.
*/

it('AC-10: loads the roster with hadir by default, saves the sheet and returns to the list', function () {
    $f = $this->f;
    $this->actingAs($f->admin);

    $this->get('/admin/attendance-sessions/create')->assertOk();

    Livewire::test(CreateAttendanceSession::class)
        ->set('data.classroom_id', $f->c7a->id)
        ->set('data.held_on', '2026-10-15')
        ->assertFormSet([
            "statuses.{$f->s1->id}" => 'hadir',
            "statuses.{$f->s2->id}" => 'hadir',
            "statuses.{$f->s3->id}" => 'hadir',
        ])
        ->set("data.statuses.{$f->s1->id}", 'sakit')
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect(ListAttendanceSessions::getUrl());

    $session = AttendanceSession::query()->sole();

    expect($session->recorded_by)->toBe($f->admin->id)
        ->and(absensiStored($f->c7a, '2026-10-15'))->toBe([$f->s1->id => 'sakit', $f->s2->id => 'hadir', $f->s3->id => 'hadir']);

    Livewire::test(CreateAttendanceSession::class)
        ->set('data.classroom_id', $f->c7a->id)
        ->set('data.held_on', '2026-10-15')
        ->assertFormSet(["statuses.{$f->s1->id}" => 'sakit']);

    Livewire::test(ListAttendanceSessions::class)->assertCanSeeTableRecords([$session]);
});

it('AC-10: shows a locked izin and a hint for boundary leaves, and rejects a future date', function () {
    $f = $this->f;
    absensiLeave($f->s1, 'kembali', [
        'planned_departure' => '2026-10-10 08:00', 'return_due' => '2026-10-13 17:00',
        'departed_at' => '2026-10-10 09:00', 'returned_at' => '2026-10-13 15:00',
    ]);
    $this->actingAs($f->admin);

    $locked = Livewire::test(CreateAttendanceSession::class)
        ->set('data.classroom_id', $f->c7a->id)
        ->set('data.held_on', '2026-10-12')
        ->assertFormSet(["statuses.{$f->s1->id}" => 'izin'])
        ->assertFormFieldDisabled("statuses.{$f->s1->id}");

    $boundary = Livewire::test(CreateAttendanceSession::class)
        ->set('data.classroom_id', $f->c7a->id)
        ->set('data.held_on', '2026-10-10')
        ->assertFormSet(["statuses.{$f->s1->id}" => 'hadir']);

    expect(tagihanPlainText($boundary))->toContain('Izin Berobat')
        ->and(tagihanPlainText($locked))->toContain('Santri Satu');

    Livewire::test(CreateAttendanceSession::class)
        ->set('data.classroom_id', $f->c7a->id)
        ->set('data.held_on', '2026-10-16')
        ->call('create')
        ->assertHasFormErrors(['held_on']);

    expect(AttendanceSession::query()->count())->toBe(0);
});

it('AC-10: edits the saved statuses of a session', function () {
    $f = $this->f;
    $session = absensiRaw($f->c7a, '2026-10-14', [$f->s1->id => 'hadir', $f->s2->id => 'hadir', $f->s3->id => 'hadir']);
    $this->actingAs($f->admin);

    Livewire::test(EditAttendanceSession::class, ['record' => $session->id])
        ->assertFormSet(["statuses.{$f->s1->id}" => 'hadir'])
        ->set("data.statuses.{$f->s2->id}", 'alpa')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(absensiStored($f->c7a, '2026-10-14')[$f->s2->id])->toBe('alpa')
        ->and(AttendanceSession::query()->count())->toBe(1);
});

it('AC-11: counts hadir, sakit, izin and alpa per student for the month, edges included', function () {
    $f = $this->f;
    absensiRaw($f->c7a, '2026-09-30', [$f->s1->id => 'alpa', $f->s2->id => 'alpa', $f->s3->id => 'alpa']);
    absensiRaw($f->c7a, '2026-10-01', [$f->s1->id => 'hadir', $f->s2->id => 'sakit', $f->s3->id => 'hadir']);
    absensiRaw($f->c7a, '2026-10-12', [$f->s1->id => 'sakit', $f->s2->id => 'izin', $f->s3->id => 'hadir']);
    absensiRaw($f->c7a, '2026-10-14', [$f->s1->id => 'alpa', $f->s2->id => 'hadir', $f->s3->id => 'hadir']);
    absensiRaw($f->c7a, '2026-11-01', [$f->s1->id => 'alpa', $f->s2->id => 'alpa', $f->s3->id => 'alpa']);
    absensiRaw($f->c7b, '2026-10-12', [$f->o7b->id => 'alpa']);
    $this->actingAs($f->admin);

    $component = Livewire::test(AttendanceRecap::class)->set('data.classroom_id', $f->c7a->id)->set('data.month', '2026-10');

    expect(absensiRecapRow($component, 'Santri Satu'))->toBe([1, 1, 0, 1])
        ->and(absensiRecapRow($component, 'Santri Dua'))->toBe([1, 1, 1, 0])
        ->and(absensiRecapRow($component, 'Santri Tiga'))->toBe([3, 0, 0, 0])
        ->and(absensiRecapRow($component, 'Santri Tujuh B'))->toBeNull();
});
