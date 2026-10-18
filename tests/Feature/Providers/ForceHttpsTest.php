<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;

afterEach(fn () => URL::forceScheme(null));

it('forces https asset and page URLs in production when APP_URL is https', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['app.url' => 'https://pondok.sch.id']);

    (new AppServiceProvider(app()))->boot();

    expect(asset('build/assets/app.css'))->toStartWith('https://')
        ->and(url('/admin'))->toStartWith('https://');
});

it('keeps the request scheme when APP_URL is http', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['app.url' => 'http://pondok.test']);

    (new AppServiceProvider(app()))->boot();

    expect(url('/admin'))->toStartWith('http://');
});

it('keeps the request scheme outside production', function () {
    config(['app.url' => 'https://pondok.test']);

    (new AppServiceProvider(app()))->boot();

    expect(url('/admin'))->toStartWith('http://');
});
