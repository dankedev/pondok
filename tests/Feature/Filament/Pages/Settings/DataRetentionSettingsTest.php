<?php

use App\Actions\Core\CreateBackup;
use App\Actions\Core\UpdateDataRetentionSettings;
use App\Filament\Pages\Settings\DataRetention;
use App\Models\Setting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| F042 AC-9 dan AC-10: Pengaturan → Backup & masa simpan (khusus super-admin).
*/

beforeEach(function () {
    kontenBoot();
    $this->url = '/admin/settings/data-retention';
});

it('AC-9: opens for the super-admin with a navigation item', function () {
    $this->actingAs(kontenUser('super-admin'))->get($this->url)->assertOk();

    expect(DataRetention::getNavigationItems())->not->toBeEmpty();
});

it('AC-9: forbids the other roles and hides the navigation item', function (string $role) {
    $this->actingAs(kontenUser($role))->get($this->url)->assertForbidden();

    expect(DataRetention::getNavigationItems())->toBeEmpty();
})->with(['admin-sekolah', 'bendahara', 'admin-konten']);

it('AC-9: saves the schedule toggle and retention years and logs old and new values', function () {
    $this->actingAs(kontenUser('super-admin'));

    Livewire::test(DataRetention::class)
        ->fillForm(['backup_enabled' => true, 'letter_snapshot_years' => 5])
        ->call('save')
        ->assertHasNoFormErrors();

    $activity = Activity::query()->where('log_name', 'sistem')->latest('id')->firstOrFail();

    expect(Setting::get('backup.enabled'))->toBeTrue()
        ->and(Setting::get('retention.letter_snapshot_years'))->toBe(5)
        ->and($activity->properties['old'])->toHaveKeys(['backup_enabled', 'letter_snapshot_years'])
        ->and($activity->properties['attributes']['backup_enabled'])->toBeTrue()
        ->and($activity->properties['attributes']['letter_snapshot_years'])->toBe(5);
});

it('AC-9: an empty retention field stores null', function () {
    $this->actingAs(kontenUser('super-admin'));
    Setting::set('retention.letter_snapshot_years', 5);

    Livewire::test(DataRetention::class)
        ->fillForm(['backup_enabled' => false, 'letter_snapshot_years' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::get('retention.letter_snapshot_years'))->toBeNull();
});

it('AC-9: rejects retention years outside 1 to 100', function (int $years) {
    $this->actingAs(kontenUser('super-admin'));
    Setting::set('retention.letter_snapshot_years', 3);

    Livewire::test(DataRetention::class)
        ->fillForm(['backup_enabled' => false, 'letter_snapshot_years' => $years])
        ->call('save')
        ->assertHasFormErrors(['letter_snapshot_years']);

    expect(Setting::get('retention.letter_snapshot_years'))->toBe(3);
})->with([0, 101]);

it('AC-9: shows that no backup exists yet, or the name and size of the latest archive', function () {
    $this->actingAs(kontenUser('super-admin'));

    expect(bayarHtmlText($this->get($this->url)->getContent()))->toContain(__('pondok.backup.none_yet'));

    Storage::disk('private')->put('backups/pondok-20260101-000000.zip', 'lama');
    Storage::disk('private')->put('backups/pondok-20260102-000000.zip', str_repeat('a', 2048));

    $text = bayarHtmlText($this->get($this->url)->getContent());

    expect($text)->toContain('pondok-20260102-000000.zip')
        ->not->toContain('pondok-20260101-000000.zip')
        ->not->toContain(__('pondok.backup.none_yet'));
});

it('AC-10: on Postgres the page warns, disables the toggle and the action refuses to enable backups', function () {
    $admin = kontenUser('super-admin');
    retentionPretendPgsql();
    $this->actingAs($admin);

    $text = bayarHtmlText($this->get($this->url)->assertOk()->getContent());

    expect($text)->toContain(__('pondok.backup.pgsql_unsupported'));

    Livewire::test(DataRetention::class)->assertFormFieldIsDisabled('backup_enabled');

    try {
        app(UpdateDataRetentionSettings::class)->handle(['backup_enabled' => true, 'letter_snapshot_years' => null], $admin);
        $this->fail('ValidationException diharapkan.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('backup_enabled');
    }

    expect(Setting::get('backup.enabled', false))->toBeFalse();
});

it('AC-9: the data retention language keys have text', function () {
    expectTranslatedKeys(['pondok.backup.none_yet', 'pondok.backup.pgsql_unsupported']);
});

it('F076 AC-9: saves the activity log retention and logs old and new values', function () {
    $this->actingAs(kontenUser('super-admin'));

    Livewire::test(DataRetention::class)
        ->fillForm(['backup_enabled' => false, 'letter_snapshot_years' => null, 'activity_log_months' => 36])
        ->call('save')
        ->assertHasNoFormErrors();

    $activity = Activity::query()->where('log_name', 'sistem')->where('event', 'updated')->latest('id')->firstOrFail();

    expect(Setting::get('retention.activity_log_months'))->toBe(36)
        ->and($activity->properties['old'])->toHaveKey('activity_log_months')
        ->and($activity->properties['attributes']['activity_log_months'])->toBe(36);
});

it('F076 AC-9: an empty activity log field stores null', function () {
    $this->actingAs(kontenUser('super-admin'));
    Setting::set('retention.activity_log_months', 36);

    Livewire::test(DataRetention::class)
        ->fillForm(['backup_enabled' => false, 'letter_snapshot_years' => null, 'activity_log_months' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::get('retention.activity_log_months'))->toBeNull();
});

it('F076 AC-9: rejects an activity log retention outside 24 to 1200 months', function (int $months) {
    $this->actingAs(kontenUser('super-admin'));
    Setting::set('retention.activity_log_months', 36);

    Livewire::test(DataRetention::class)
        ->fillForm(['backup_enabled' => false, 'letter_snapshot_years' => null, 'activity_log_months' => $months])
        ->call('save')
        ->assertHasFormErrors(['activity_log_months']);

    expect(Setting::get('retention.activity_log_months'))->toBe(36);
})->with([0, 23, 1201]);

it('F076 AC-10: the action rejects 23 months and treats a missing key as null', function () {
    $admin = kontenUser('super-admin');
    Setting::set('retention.activity_log_months', 36);

    try {
        app(UpdateDataRetentionSettings::class)->handle(['backup_enabled' => false, 'letter_snapshot_years' => null, 'activity_log_months' => 23], $admin);
        $this->fail('ValidationException diharapkan.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('activity_log_months');
    }

    app(UpdateDataRetentionSettings::class)->handle(['backup_enabled' => false, 'letter_snapshot_years' => null], $admin);

    expect(Setting::get('retention.activity_log_months'))->toBeNull();
});

it('F076 AC-10: the activity log language keys have text', function () {
    expectTranslatedKeys([
        'pondok.data_retention.activity_log_heading',
        'pondok.data_retention.activity_log_description',
        'pondok.data_retention.activity_log_months',
        'pondok.data_retention.activity_log_months_helper',
        'pondok.data_retention.months_suffix',
        'pondok.data_retention.activity_log_pruned',
        'pondok.data_retention.activity_pruned',
    ]);
});

it('VERIFY F076: accepts exactly 24 and 1200 months through the page', function (int $months) {
    $this->actingAs(kontenUser('super-admin'));

    Livewire::test(DataRetention::class)
        ->fillForm(['backup_enabled' => false, 'letter_snapshot_years' => null, 'activity_log_months' => $months])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::get('retention.activity_log_months'))->toBe($months);
})->with([24, 1200]);

it('VERIFY F076: saving again without changes writes no extra activity', function () {
    $this->actingAs(kontenUser('super-admin'));
    Setting::set('retention.activity_log_months', 36);

    Livewire::test(DataRetention::class)
        ->fillForm(['backup_enabled' => false, 'letter_snapshot_years' => null, 'activity_log_months' => 36])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Activity::query()->where('event', 'updated')->count())->toBe(0);
});

it('VERIFY F076: the action refuses a non super-admin and keeps the retention', function () {
    Setting::set('retention.activity_log_months', 36);

    expect(fn () => app(UpdateDataRetentionSettings::class)->handle(
        ['backup_enabled' => false, 'letter_snapshot_years' => null, 'activity_log_months' => 24],
        kontenUser('admin-sekolah'),
    ))->toThrow(AuthorizationException::class);

    expect(Setting::get('retention.activity_log_months'))->toBe(36);
});

it('VERIFY F076: a non-integer or text retention is rejected by the action', function (mixed $value) {
    Setting::set('retention.activity_log_months', 36);

    expect(fn () => app(UpdateDataRetentionSettings::class)->handle(
        ['backup_enabled' => false, 'letter_snapshot_years' => null, 'activity_log_months' => $value],
        kontenUser('super-admin'),
    ))->toThrow(ValidationException::class);

    expect(Setting::get('retention.activity_log_months'))->toBe(36);
})->with(['abc', '24.5']);

/*
| F085: frekuensi backup terjadwal.
*/

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function frequencyPayload(array $extra = []): array
{
    return ['backup_enabled' => false, 'letter_snapshot_years' => null, ...$extra];
}

it('F085 AC-6: shows three frequency choices with the recommendation, history range and quota help', function () {
    $this->actingAs(kontenUser('super-admin'));

    Livewire::test(DataRetention::class)->assertFormFieldExists('backup_frequency');

    $text = bayarHtmlText($this->get($this->url)->assertOk()->getContent());

    expect($text)->toContain('Mingguan (disarankan)')
        ->toContain('3 hari')->toContain('3 minggu')->toContain('3 bulan')
        ->toContain(__('pondok.data_retention.backup_frequency_helper', ['keep' => CreateBackup::KEEP]))
        ->and(mb_strtolower(__('pondok.data_retention.backup_enabled')))->not->toContain('harian');
});

it('F085 AC-6: fills the frequency with daily when unset and with the saved value otherwise', function () {
    $this->actingAs(kontenUser('super-admin'));

    Livewire::test(DataRetention::class)->assertFormSet(['backup_frequency' => 'daily']);

    Setting::set('backup.frequency', 'weekly');

    Livewire::test(DataRetention::class)->assertFormSet(['backup_frequency' => 'weekly']);
});

it('F085 AC-7: saves the chosen frequency and logs the old and new value', function () {
    $this->actingAs(kontenUser('super-admin'));

    Livewire::test(DataRetention::class)
        ->fillForm(['backup_frequency' => 'weekly'])
        ->call('save')
        ->assertHasNoFormErrors();

    $activity = Activity::query()->where('log_name', 'sistem')->where('event', 'updated')->latest('id')->firstOrFail();

    expect(Setting::get('backup.frequency'))->toBe('weekly')
        ->and($activity->properties['old']['backup_frequency'])->toBe('daily')
        ->and($activity->properties['attributes']['backup_frequency'])->toBe('weekly');
});

it('F085 AC-7: saving daily on an instance without the key writes no activity', function () {
    $this->actingAs(kontenUser('super-admin'));

    Livewire::test(DataRetention::class)
        ->fillForm(['backup_frequency' => 'daily'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Activity::query()->where('event', 'updated')->count())->toBe(0);
});

it('F085 AC-7: saving the same frequency again writes no extra activity', function () {
    $this->actingAs(kontenUser('super-admin'));
    Setting::set('backup.frequency', 'monthly');

    Livewire::test(DataRetention::class)
        ->fillForm(['backup_frequency' => 'monthly'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Activity::query()->where('event', 'updated')->count())->toBe(0)
        ->and(Setting::get('backup.frequency'))->toBe('monthly');
});

it('F085 AC-8: the action rejects an unknown frequency and keeps the setting', function (mixed $value) {
    Setting::set('backup.frequency', 'weekly');

    expect(fn () => app(UpdateDataRetentionSettings::class)->handle(
        frequencyPayload(['backup_frequency' => $value]),
        kontenUser('super-admin'),
    ))->toThrow(ValidationException::class);

    expect(Setting::get('backup.frequency'))->toBe('weekly');
})->with(['yearly', '', 1]);

it('F085 AC-8: a legacy call without the key keeps the stored frequency, or leaves the key absent', function () {
    $admin = kontenUser('super-admin');
    Setting::set('backup.frequency', 'monthly');

    app(UpdateDataRetentionSettings::class)->handle(frequencyPayload(['letter_snapshot_years' => 7]), $admin);

    expect(Setting::get('backup.frequency'))->toBe('monthly')
        ->and(Setting::get('retention.letter_snapshot_years'))->toBe(7);

    Setting::query()->where('key', 'backup.frequency')->delete();
    Setting::forget('backup.frequency');

    app(UpdateDataRetentionSettings::class)->handle(frequencyPayload(['letter_snapshot_years' => 8]), $admin);

    expect(Setting::query()->where('key', 'backup.frequency')->exists())->toBeFalse()
        ->and(Setting::get('retention.letter_snapshot_years'))->toBe(8);
});

it('F085 AC-9: a non super-admin cannot change the frequency', function () {
    expect(fn () => app(UpdateDataRetentionSettings::class)->handle(
        frequencyPayload(['backup_frequency' => 'weekly']),
        kontenUser('admin-sekolah'),
    ))->toThrow(AuthorizationException::class);

    expect(Setting::get('backup.frequency'))->toBeNull();
});

it('F085 AC-9: on Postgres the frequency field is disabled', function () {
    $this->actingAs(kontenUser('super-admin'));
    retentionPretendPgsql();

    Livewire::test(DataRetention::class)->assertFormFieldIsDisabled('backup_frequency');
});

it('F085 AC-11: the backup frequency language keys have text', function () {
    $keys = ['pondok.data_retention.backup_frequency', 'pondok.data_retention.backup_frequency_helper'];

    foreach (['daily', 'weekly', 'monthly'] as $value) {
        $keys[] = "pondok.backup_frequency.{$value}.label";
        $keys[] = "pondok.backup_frequency.{$value}.description";
    }

    expectTranslatedKeys($keys);
});
