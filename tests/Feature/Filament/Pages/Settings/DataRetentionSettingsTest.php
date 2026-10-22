<?php

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
