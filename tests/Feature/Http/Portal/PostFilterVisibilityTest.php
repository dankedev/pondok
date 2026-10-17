<?php

beforeEach(function () {
    $this->units = portalBoot();
});

/**
 * Teks label chip dalam satu baris filter (nav) berdasarkan aria-label.
 */
function postFilterChips(string $html, string $navLabel): ?array
{
    if (! preg_match('/<nav aria-label="'.preg_quote($navLabel, '/').'"[^>]*>(.*?)<\/nav>/s', $html, $nav)) {
        return null;
    }
    preg_match_all('/<a [^>]*>([^<]*)<\/a>/', $nav[1], $chips);

    return array_map('trim', $chips[1]);
}

it('hides type chips without published posts and the whole row when empty', function () {
    portalPost(['type' => 'berita', 'unit_id' => null]);
    portalPost(['type' => 'kegiatan', 'status' => 'draf', 'published_at' => null]);

    $html = $this->get('/berita')->assertOk()->getContent();
    $chips = postFilterChips($html, 'Saring menurut jenis');

    expect($chips)->toHaveCount(2)->and($chips)->not->toContain(__('pondok.post.types.kegiatan'));
});

it('omits the type row when there are no published posts', function () {
    $this->get('/berita')->assertOk()->assertDontSee('Saring menurut jenis');
});

it('hides the unit row when only one unit has published posts', function () {
    portalPost(['unit_id' => $this->units->sd->id]);

    $this->get('/berita')->assertOk()->assertDontSee('Saring menurut jenjang');
});

it('shows only units with published posts when at least two have them', function () {
    portalPost(['unit_id' => $this->units->sd->id]);
    portalPost(['unit_id' => $this->units->slta->id]);

    $chips = postFilterChips($this->get('/berita')->assertOk()->getContent(), 'Saring menurut jenjang');

    expect($chips)->toContain('SD', 'SLTA')->and($chips)->not->toContain('SLTP');
});
