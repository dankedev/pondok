<?php

use App\Models\Classroom;
use App\Models\Setting;

beforeEach(function () {
    $this->f = waliBoot();
    Setting::set('modules.absensi.enabled', true);

    $this->room = Classroom::factory()->create(['academic_year_id' => $this->f->year->id, 'unit_id' => $this->f->sltp->id, 'grade' => 7, 'name' => '7A']);
});

/**
 * Teks halaman memuat label status berdampingan dengan angkanya (urutan bebas).
 */
function waliAttendanceShows(string $text, string $label, int $count): bool
{
    return (bool) preg_match('/(?:'.preg_quote($label, '/').'\W{0,3}'.$count.'\b|\b'.$count.'\W{0,3}'.preg_quote($label, '/').')/iu', $text);
}

it('AC-12: shows this month summary and absent days of the own child only', function () {
    $f = $this->f;
    absensiRaw($this->room, '2026-09-30', [$f->s1->id => 'alpa', $f->s2->id => 'alpa']);
    absensiRaw($this->room, '2026-10-02', [$f->s1->id => 'hadir', $f->s2->id => 'alpa']);
    absensiRaw($this->room, '2026-10-05', [$f->s1->id => 'sakit', $f->s2->id => 'hadir']);
    absensiRaw($this->room, '2026-10-06', [$f->s1->id => 'hadir', $f->s2->id => 'hadir']);
    absensiRaw($this->room, '2026-10-07', [$f->s1->id => 'izin', $f->s2->id => 'hadir']);
    absensiRaw($this->room, '2026-10-08', [$f->s1->id => 'alpa', $f->s2->id => 'hadir']);
    $f->wali->units()->attach($f->sd->id);

    $text = waliText($f->wali, "/wali/santri/{$f->s1->id}");

    expect($text)->toContain('Kehadiran Oktober 2026')
        ->and(waliAttendanceShows($text, __('pondok.attendance.statuses.hadir'), 2))->toBeTrue()
        ->and(waliAttendanceShows($text, __('pondok.attendance.statuses.sakit'), 1))->toBeTrue()
        ->and(waliAttendanceShows($text, __('pondok.attendance.statuses.izin'), 1))->toBeTrue()
        ->and(waliAttendanceShows($text, __('pondok.attendance.statuses.alpa'), 1))->toBeTrue()
        ->and($text)->toContain('5 Okt 2026')->toContain('7 Okt 2026')->toContain('8 Okt 2026')
        ->not->toContain('2 Okt 2026')->not->toContain('6 Okt 2026')->not->toContain('30 Sep 2026');
});

it('AC-12: answers 404 for a student of another wali', function () {
    $f = $this->f;
    absensiRaw($this->room, '2026-10-05', [$f->o1->id => 'alpa']);

    $this->actingAs($f->wali)->get("/wali/santri/{$f->o1->id}")->assertNotFound();
});

it('AC-12: hides the attendance section when the module is off but keeps the page open', function () {
    $f = $this->f;
    absensiRaw($this->room, '2026-10-05', [$f->s1->id => 'sakit']);

    expect(waliText($f->wali, "/wali/santri/{$f->s1->id}"))->toContain('Kehadiran Oktober 2026');

    Setting::set('modules.absensi.enabled', false);

    expect(waliText($f->wali, "/wali/santri/{$f->s1->id}"))->not->toContain('Kehadiran')->toContain('Ahmad Satu');
});
