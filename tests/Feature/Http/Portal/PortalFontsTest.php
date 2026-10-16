<?php

use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;

// Layout publik wajib memanggil @fonts agar Instrument Sans (self-host lewat Vite) termuat;
// tanpa itu portal jatuh ke font sistem (design guide §3).

it('loads the self-hosted fonts in the public layout', function () {
    portalBoot();

    app()->instance(Vite::class, new class
    {
        public function __invoke($entrypoints, $buildDirectory = null): HtmlString
        {
            return new HtmlString('');
        }

        public function fonts($aliases = null): HtmlString
        {
            return new HtmlString('<style data-portal-fonts></style>');
        }

        public function __call($method, $parameters): string
        {
            return '';
        }
    });

    $this->get(route('portal.home'))
        ->assertOk()
        ->assertSee('<style data-portal-fonts></style>', escape: false);
});
