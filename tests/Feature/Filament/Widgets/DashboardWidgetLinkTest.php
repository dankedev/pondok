<?php

beforeEach(function () {
    $this->f = dashboardBoot();
});

it('AC-15: returns null for unregistered routes', function () {
    $class = dashboardWidgets()['students'];

    expect(class_exists($class))->toBeTrue("Kelas widget {$class} belum ada")
        ->and($class::urlIfRouteAvailable('filament.admin.resources.no-such-things.index'))->toBeNull()
        ->and($class::urlIfRouteAvailable('filament.admin.resources.no-such-things.index', true))->toBeNull();
});

it('AC-15: returns null when not authorized', function () {
    $class = dashboardWidgets()['students'];

    expect($class::urlIfRouteAvailable('filament.admin.resources.students.index', false))->toBeNull();
});

it('AC-15: returns the route url when available', function () {
    $class = dashboardWidgets()['students'];

    expect($class::urlIfRouteAvailable('filament.admin.resources.students.index'))
        ->toBe(route('filament.admin.resources.students.index'))
        ->and($class::urlIfRouteAvailable('filament.admin.resources.students.index', true))
        ->toBe(route('filament.admin.resources.students.index'));
});
