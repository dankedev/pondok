<?php

use App\Enums\BackupFrequency;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/*
| F085 AC-1 dan AC-2: frekuensi backup terjadwal.
*/

it('AC-1: has three cases and marks only weekly as recommended', function () {
    expect(collect(BackupFrequency::cases())->map->value->all())->toBe(['daily', 'weekly', 'monthly'])
        ->and(BackupFrequency::Weekly->label())->toContain('Mingguan')->toContain('(disarankan)')
        ->and(BackupFrequency::Daily->label())->not->toContain('disarankan')
        ->and(BackupFrequency::Monthly->label())->not->toContain('disarankan');
});

it('AC-1: current() falls back to daily for a missing, empty or unknown value', function () {
    kontenBoot();

    expect(BackupFrequency::current())->toBe(BackupFrequency::Daily);

    foreach (['yearly', '', null] as $value) {
        Setting::set('backup.frequency', $value);
        expect(BackupFrequency::current())->toBe(BackupFrequency::Daily, 'nilai: '.var_export($value, true));
    }

    Setting::set('backup.frequency', 'weekly');
    expect(BackupFrequency::current())->toBe(BackupFrequency::Weekly);

    Setting::set('backup.frequency', 'monthly');
    expect(BackupFrequency::current())->toBe(BackupFrequency::Monthly);
});

it('AC-2: weekly is due only on Sunday, monthly only on the 1st, daily always', function () {
    // 2026-10-19 Senin sampai 2026-10-25 Ahad.
    foreach (range(19, 25) as $day) {
        $at = CarbonImmutable::create(2026, 10, $day, 1, 30);

        expect(BackupFrequency::Daily->isDueOn($at))->toBeTrue("harian {$day}")
            ->and(BackupFrequency::Weekly->isDueOn($at))->toBe($day === 25, "mingguan {$day} Okt");
    }

    foreach ([1 => true, 2 => false, 15 => false, 31 => false] as $day => $due) {
        $at = CarbonImmutable::create(2026, 12, $day, 1, 30);

        expect(BackupFrequency::Monthly->isDueOn($at))->toBe($due, "bulanan tanggal {$day}")
            ->and(BackupFrequency::Daily->isDueOn($at))->toBeTrue("harian tanggal {$day}");
    }
});

it('AC-1: the description mentions the history range from the archive count', function () {
    expect(BackupFrequency::Daily->description())->toContain('3 hari')
        ->and(BackupFrequency::Weekly->description())->toContain('3 minggu')
        ->and(BackupFrequency::Monthly->description())->toContain('3 bulan');
});
