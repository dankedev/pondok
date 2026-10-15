<?php

use App\Actions\Psb\SaveAdmissionRequirement;
use App\Actions\Psb\SaveAdmissionTrack;
use App\Actions\Psb\SaveAdmissionWave;
use App\Models\Scholarship;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->f = psbFixture();
    $this->user = psbUser('admin-psb');
    $this->actingAs($this->user);
});

it('AC-30: logs wave creation with the causer', function () {
    $wave = app(SaveAdmissionWave::class)->handle(psbWaveData($this->f));

    $activity = Activity::query()->where('subject_type', $wave->getMorphClass())->where('subject_id', $wave->id)->sole();

    expect($activity)
        ->log_name->toBe('psb')
        ->event->toBe('created')
        ->causer_id->toBe($this->user->id)
        ->and($activity->subject->is($wave))->toBeTrue()
        ->and($activity->properties['attributes']['name'])->toBe('Gelombang 1');
});

it('AC-30: logs wave changes with old and new values', function () {
    $wave = app(SaveAdmissionWave::class)->handle(psbWaveData($this->f, ['quota' => 150]));

    app(SaveAdmissionWave::class)->handle(psbWaveData($this->f, ['quota' => 200]), $wave);

    $updated = Activity::query()->where('subject_id', $wave->id)->where('subject_type', $wave->getMorphClass())->where('event', 'updated')->sole();

    expect($updated->log_name)->toBe('psb')
        ->and($updated->properties['old']['quota'])->toBe(150)
        ->and($updated->properties['attributes']['quota'])->toBe(200);
});

it('AC-30: skips empty wave saves', function () {
    $wave = app(SaveAdmissionWave::class)->handle(psbWaveData($this->f));
    $before = Activity::query()->count();

    app(SaveAdmissionWave::class)->handle(psbWaveData($this->f), $wave->fresh());

    expect(Activity::query()->count())->toBe($before);
});

it('AC-30: logs track creation and scholarship changes', function () {
    $track = app(SaveAdmissionTrack::class)->handle(psbTrackData($this->f, ['scholarship_id' => null]));
    $other = Scholarship::factory()->create();

    app(SaveAdmissionTrack::class)->handle(psbTrackData($this->f, ['scholarship_id' => $other->id]), $track);

    $activities = Activity::query()->where('subject_id', $track->id)->where('subject_type', $track->getMorphClass())->orderBy('id')->get();

    expect($activities->pluck('event')->all())->toBe(['created', 'updated'])
        ->and($activities->pluck('log_name')->unique()->all())->toBe(['psb'])
        ->and($activities[1]->properties['attributes']['scholarship_id'])->toBe($other->id);
});

it('AC-30: skips empty track saves', function () {
    $track = app(SaveAdmissionTrack::class)->handle(psbTrackData($this->f));
    $before = Activity::query()->count();

    app(SaveAdmissionTrack::class)->handle(psbTrackData($this->f), $track->fresh());

    expect(Activity::query()->count())->toBe($before);
});

it('AC-30: does not log requirement saves', function () {
    $before = Activity::query()->count();

    app(SaveAdmissionRequirement::class)->handle(psbRequirementData($this->f));

    expect(Activity::query()->count())->toBe($before);
});
