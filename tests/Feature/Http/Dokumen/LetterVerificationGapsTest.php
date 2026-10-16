<?php

use App\Models\Setting;

beforeEach(function () {
    Setting::set('modules.dokumen.enabled', true);
});

it('VERIFY: never answers an array code query with a server error', function (string $query) {
    $response = test()->get('/verifikasi-surat?'.$query);

    expect($response->getStatusCode())->toBeLessThan(500);
})->with(['kode[]=abc', 'kode[a]=b&kode[c][]=d', 'kode[]=']);
