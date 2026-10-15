<?php

use App\Models\Setting;
use Filament\Widgets\StatsOverviewWidget;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->f = dashboardBoot();
});

it('AC-1: renders only the widgets allowed for :role', function (string $role, array $allowed) {
    foreach (dashboardWidgets() as $class) {
        expect(class_exists($class))->toBeTrue("Kelas widget {$class} belum ada");
    }

    $response = dashboardGet(keuanganUser($role));

    foreach (dashboardWidgets() as $key => $class) {
        if (in_array($key, $allowed, true)) {
            $response->assertSeeLivewire($class);
        } else {
            $response->assertDontSeeLivewire($class);
        }
    }
})->with(fn () => collect(dashboardMatrix())->map(fn (array $allowed, string $role): array => [$role, $allowed])->values()->all());

it('AC-1: shows the unique heading of each allowed widget to super-admin', function () {
    $text = dashboardText(keuanganUser('super-admin'));

    expect($text)->toContain('Keuangan Oktober 2026')
        ->and($text)->toContain(__('pondok.dashboard.students.heading'))
        ->and($text)->toContain(__('pondok.dashboard.psb.heading'))
        ->and($text)->toContain(__('pondok.dashboard.documents.heading'))
        ->and($text)->toContain(__('pondok.dashboard.achievements.heading'))
        ->and($text)->toContain(__('pondok.dashboard.system.heading'));
});

it('AC-2: follows permissions rather than roles', function () {
    $user = dashboardUserWith(['view-dashboard', 'view-finance-metrics']);

    $response = dashboardGet($user);

    foreach (dashboardWidgets() as $key => $class) {
        if ($key === 'finance') {
            $response->assertSeeLivewire($class);
        } else {
            $response->assertDontSeeLivewire($class);
        }
    }
});

it('AC-2: hides :key from users without its metric permission and from guests', function (string $key) {
    $class = dashboardWidgets()[$key];
    $permission = dashboardPermissions()[$key];

    expect(class_exists($class))->toBeTrue("Kelas widget {$class} belum ada");

    $this->actingAs(dashboardUserWith(['view-dashboard']));
    expect($class::canView())->toBeFalse();

    $others = collect(dashboardPermissions())->reject(fn (string $p): bool => $p === $permission)->unique()->values()->all();
    $this->actingAs(dashboardUserWith(['view-dashboard', ...$others]));
    expect($class::canView())->toBeFalse();

    $this->actingAs(dashboardUserWith(['view-dashboard', $permission]));
    expect($class::canView())->toBeTrue();

    Auth::logout();
    expect($class::canView())->toBeFalse();
})->with(array_keys(dashboardWidgets()));

it('AC-2: gives wali no access to the dashboard widgets', function () {
    $this->actingAs(keuanganUser('wali'))->get('/admin')->assertForbidden();
});

it('AC-16: renders widgets eagerly without polling', function () {
    foreach (dashboardWidgets() as $class) {
        expect(class_exists($class))->toBeTrue("Kelas widget {$class} belum ada")
            ->and($class::isLazy())->toBeFalse();

        if (is_subclass_of($class, StatsOverviewWidget::class)) {
            $widget = new $class;
            $method = new ReflectionMethod($widget, 'getPollingInterval');

            expect($method->invoke($widget))->toBeNull();
        }
    }

    Setting::set('modules.psb.enabled', true);
    $response = dashboardGet(keuanganUser('super-admin'));

    foreach (dashboardWidgets() as $class) {
        $response->assertSeeLivewire($class);
    }

    $response->assertDontSee('wire:poll', false);
    $response->assertDontSee('x-intersect', false);
    expect(bayarHtmlText($response->getContent()))->toContain('Keuangan Oktober 2026');
});

it('AC-18: has no raw translation keys', function () {
    $html = dashboardGet(keuanganUser('super-admin'))->getContent();

    expect($html)->not->toContain('pondok.dashboard.')
        ->and(__('pondok.dashboard.module_unavailable'))->toBe('Modul belum tersedia.');
});
