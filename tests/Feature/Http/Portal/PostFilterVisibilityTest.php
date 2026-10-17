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

it('has no type chip row, even with several published posts', function () {
    portalPost(['unit_id' => null]);
    portalPost(['title' => 'Kegiatan lain']);

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
