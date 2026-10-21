<?php

use App\Actions\Core\SetModuleEnabled;
use App\Enums\Module;
use App\Models\Post;
use App\Models\Setting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    kontenBoot();
    $this->admin = kontenUser('super-admin');
});

/**
 * Pesan ValidationException pada key `module`.
 */
function moduleRejection(Closure $callback): string
{
    try {
        $callback();
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('module');

        return implode(' ', $e->errors()['module']);
    }

    test()->fail('ValidationException diharapkan, tetapi tidak dilempar.');
}

it('AC-3: disables a module without active dependents and logs the change', function () {
    app(SetModuleEnabled::class)->handle($this->admin, Module::Konten, false);

    $activity = Activity::query()->where('log_name', 'pengaturan')->sole();

    expect(Module::Konten->isEnabled())->toBeFalse()
        ->and($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->properties['module'])->toBe('konten')
        ->and($activity->properties['old']['enabled'])->toBeTrue()
        ->and($activity->properties['attributes']['enabled'])->toBeFalse();
});

it('AC-3: enabling again restores the setting and leaves module data untouched', function () {
    kontenPost();
    kontenPost();
    $before = Post::query()->count();

    app(SetModuleEnabled::class)->handle($this->admin, Module::Konten, false);
    expect(Post::query()->count())->toBe($before);

    app(SetModuleEnabled::class)->handle($this->admin, Module::Konten, true);

    expect(Module::Konten->isEnabled())->toBeTrue()
        ->and(Post::query()->count())->toBe($before)
        ->and(Activity::query()->where('log_name', 'pengaturan')->count())->toBe(2);
});

it('AC-4: refuses to disable a module while a dependent is active', function () {
    $message = moduleRejection(fn () => app(SetModuleEnabled::class)->handle($this->admin, Module::Keuangan, false));

    expect($message)->toContain('PSB')
        ->and(Module::Keuangan->isEnabled())->toBeTrue()
        ->and(Activity::query()->where('log_name', 'pengaturan')->count())->toBe(0);
});

it('AC-4: names every active dependent when disabling akademik', function () {
    $message = moduleRejection(fn () => app(SetModuleEnabled::class)->handle($this->admin, Module::Akademik, false));

    expect($message)->toContain(Module::Asrama->label())
        ->toContain(Module::Keuangan->label())->toContain(Module::Psb->label())
        ->and(Module::Akademik->isEnabled())->toBeTrue();
});

it('AC-5: refuses to enable psb while keuangan is off, then allows it once keuangan is on', function () {
    Setting::set('modules.psb.enabled', false);
    Setting::set('modules.keuangan.enabled', false);

    $message = moduleRejection(fn () => app(SetModuleEnabled::class)->handle($this->admin, Module::Psb, true));

    expect($message)->toContain('Keuangan')
        ->and(Module::Psb->isEnabled())->toBeFalse()
        ->and(Activity::query()->where('log_name', 'pengaturan')->count())->toBe(0);

    app(SetModuleEnabled::class)->handle($this->admin, Module::Keuangan, true);
    app(SetModuleEnabled::class)->handle($this->admin, Module::Psb, true);

    expect(Module::Psb->isEnabled())->toBeTrue();
});

it('AC-6: rejects an actor who is not super-admin and changes nothing', function () {
    $actor = kontenUser('admin-sekolah');

    expect(fn () => app(SetModuleEnabled::class)->handle($actor, Module::Konten, false))->toThrow(AuthorizationException::class)
        ->and(Module::Konten->isEnabled())->toBeTrue()
        ->and(Activity::query()->where('log_name', 'pengaturan')->count())->toBe(0);
});

it('AC-6: a value equal to the current one is a no-op without an activity', function () {
    app(SetModuleEnabled::class)->handle($this->admin, Module::Konten, true);

    expect(Module::Konten->isEnabled())->toBeTrue()
        ->and(Activity::query()->where('log_name', 'pengaturan')->count())->toBe(0);
});
