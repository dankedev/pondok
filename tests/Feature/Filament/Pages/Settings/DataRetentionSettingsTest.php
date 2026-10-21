<?php

use App\Actions\Core\UpdateDataRetentionSettings;
use App\Filament\Pages\Settings\DataRetention;
use App\Models\Setting;
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
