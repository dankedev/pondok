<?php

use App\Actions\Dokumen\CancelIssuedLetter;
use App\Models\Setting;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->f = suratFixture();
    $this->f->admin->forceFill(['name' => 'Bu Admin Sekolah'])->save();
    $this->letter = suratIssue($this->f);
});

/**
 * HTML hasil verifikasi publik sebagai tamu (status HTTP harus `$status`).
 */
function suratVerifyHtml(string $code, int $status = 200): string
{
    return test()->get('/verifikasi-surat/'.$code)->assertStatus($status)->getContent();
}

/**
 * Pastikan halaman publik tanpa Vite, CDN, skrip luar, dan memuat meta robots noindex.
 */
function suratAssertStandalonePage(string $html): void
{
    expect($html)->toContain('<html lang="id"')
        ->toContain('<meta name="robots" content="noindex"')
        ->not->toContain('@vite')
        ->not->toContain('/build/')
        ->not->toContain('<script src')
        ->not->toContain('<link ')
        ->not->toContain('wire:');

    preg_match_all('#https?://([^/"\'\s)>]+)#i', $html, $matches);
    $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

    foreach ($matches[1] as $host) {
        expect($host)->toBe($appHost);
    }
}

it('AC-23: shows minimal data for a valid letter', function () {
    $letter = $this->letter;
    $response = $this->get('/verifikasi-surat/'.$letter->verification_code)->assertOk();
    $text = suratText($response->getContent());

    expect(__('pondok.letter_verification.valid_title'))->not->toBe('pondok.letter_verification.valid_title')
        ->and($text)->toContain(__('pondok.letter_verification.valid_title'))
        ->toContain("Ma'had Al-Khair")
        ->toContain('001/MAK/SKA/X/2026')
        ->toContain(__('pondok.issued_letter.types.keterangan_aktif'))
        ->toContain('15 Okt 2026')
        ->toContain('Muhammad F. A.')
        ->toContain('Ust. Hasan')
        ->toContain('Kepala Sekolah')
        ->not->toContain('Muhammad Fauzan Akbar')
        ->not->toContain('Fauzan')
        ->not->toContain('2026000123')
        ->not->toContain('0098765432')
        ->not->toContain('Bandung')
        ->not->toContain('2014')
        ->not->toContain('Persyaratan beasiswa')
        ->not->toContain(__('pondok.letter.classroom'))
        ->not->toContain('Bu Admin Sekolah')
        ->not->toContain(__('pondok.letter_verification.cancelled_title'))
        ->not->toMatch('/pondok\.(letter|issued_letter|letter_verification)\b/');
});

it('AC-23: accepts lower case and dashed codes', function (string $format) {
    $code = $this->letter->verification_code;
    $input = match ($format) {
        'lower' => strtolower($code),
        'dashed' => $this->letter->formattedVerificationCode(),
        'lower dashed' => strtolower($this->letter->formattedVerificationCode()),
    };

    expect(suratText(suratVerifyHtml($input)))->toContain('001/MAK/SKA/X/2026')->toContain('Muhammad F. A.');
})->with(['lower', 'dashed', 'lower dashed']);

it('AC-23: masks the name from the snapshot even after the student changed', function () {
    $this->f->s1->forceFill(['name' => 'Zainal Abidin'])->save();

    $text = suratText(suratVerifyHtml($this->letter->verification_code));

    expect($text)->toContain('Muhammad F. A.')->not->toContain('Zainal');
});

it('AC-23: still verifies a letter of an archived student', function () {
    $this->f->s1->delete();

    expect(suratText(suratVerifyHtml($this->letter->verification_code)))->toContain('Muhammad F. A.')->toContain('001/MAK/SKA/X/2026');
});

it('AC-23: shows no student status for ordinary letters', function () {
    expect(suratText(suratVerifyHtml($this->letter->verification_code)))->not->toContain(__('pondok.letter_verification.student_status'));
});

it('AC-24: reports cancelled letters', function () {
    $f = $this->f;
    $this->travelTo(CarbonImmutable::parse('2026-10-20 14:30', 'Asia/Jakarta'));
    app(CancelIssuedLetter::class)->handle($this->letter, $f->admin, 'Salah keperluan');

    $text = suratText(suratVerifyHtml($this->letter->verification_code));

    expect(__('pondok.letter_verification.cancelled_title'))->not->toBe('pondok.letter_verification.cancelled_title')
        ->and($text)->toContain(__('pondok.letter_verification.cancelled_title'))
        ->toContain('20 Okt 2026')
        ->toContain('001/MAK/SKA/X/2026')
        ->not->toContain('Salah keperluan')
        ->not->toContain('Muhammad')
        ->not->toContain('Fauzan')
        ->not->toContain(__('pondok.letter_verification.valid_title'))
        ->not->toContain('Bu Admin Sekolah')
        ->not->toContain('2026000123');
});

it('AC-25: answers unknown codes with a 404 page that has the form', function (string $code) {
    $response = $this->get('/verifikasi-surat/'.$code)->assertNotFound();
    $html = $response->getContent();
    $text = suratText($html);

    expect(__('pondok.letter_verification.not_found'))->not->toBe('pondok.letter_verification.not_found')
        ->and($text)->toContain(__('pondok.letter_verification.not_found'))
        ->and($html)->toContain('<form')
        ->not->toContain('<script>alert(1)')
        ->not->toContain('Whoops');

    suratAssertPrivacyHeaders($response);
})->with([
    'valid alphabet, unknown' => ['ZZZZZZZZZZZZ'],
    'too short' => ['ABCDEFG'],
    'outside the alphabet' => ['ABCDEFGHJK0O'],
    'encoded space and symbol' => ['ABCD%20EFGH!JKMN'],
    'script tag' => ['%3Cscript%3Ealert(1)%3C%2Fscript%3E'],
    '200 characters' => [str_repeat('A', 200)],
    'dashes only' => ['------------'],
]);

it('AC-25: does not echo the input without escaping', function () {
    $html = $this->get('/verifikasi-surat/'.rawurlencode('"><img src=x onerror=alert(1)>'))->assertNotFound()->getContent();

    expect($html)->not->toContain('<img src=x');
});

it('AC-25: never answers a malformed code with a server error', function (string $code) {
    expect($this->get('/verifikasi-surat/'.$code)->getStatusCode())->toBe(404);
})->with(['%00', '%FF%FE', str_repeat('%E2%80%A6', 40), '..%2F..%2Fetc', '%27%20OR%201%3D1']);

it('AC-26: shows the current status of a student card holder', function () {
    $f = $this->f;
    $card = suratIssue($f, ['type' => 'kartu_santri']);
    $pattern = fn (string $value): string => '/'.preg_quote(__('pondok.letter_verification.student_status'), '/').'\W*'.preg_quote($value, '/').'/u';

    $active = suratText(suratVerifyHtml($card->verification_code));

    expect(__('pondok.letter_verification.student_active'))->toBe('Aktif')
        ->and(__('pondok.letter_verification.student_inactive'))->toBe('Tidak aktif')
        ->and($active)->toMatch($pattern('Aktif'))->not->toContain('Tidak aktif');

    $f->s1->forceFill(['status' => 'keluar'])->save();

    expect(suratText(suratVerifyHtml($card->verification_code)))->toMatch($pattern('Tidak aktif'));

    $f->s1->forceFill(['status' => 'aktif'])->save();
    $f->s1->delete();

    expect(suratText(suratVerifyHtml($card->verification_code)))->toMatch($pattern('Tidak aktif'));
});

it('AC-27: ignores the unit scope of a logged-in user', function () {
    $this->actingAs($this->f->scoped);

    $this->get('/verifikasi-surat/'.$this->letter->verification_code)->assertOk();
});

it('AC-28: redirects the code form', function () {
    $response = $this->get('/verifikasi-surat?kode='.rawurlencode('abcd efgh jkmn'))->assertStatus(302);

    expect($response->headers->get('Location'))->toEndWith('/verifikasi-surat/ABCDEFGHJKMN');
});

it('AC-28: shows the form with a labelled input', function () {
    $html = $this->get('/verifikasi-surat')->assertOk()->getContent();

    expect($html)->toContain('<form')->toContain('autocomplete="off"')
        ->and(suratText($html))->toContain(__('pondok.letter_verification.code_label'))->toContain(__('pondok.letter_verification.submit'))
        ->and($html)->toMatch('/<label\b[^>]*\bfor="[^"]+"/');
});

it('AC-28: asks for a code when the code is empty', function () {
    $text = suratText($this->get('/verifikasi-surat?kode=')->assertOk()->getContent());

    expect(__('pondok.letter_verification.code_required'))->not->toBe('pondok.letter_verification.code_required')
        ->and($text)->toContain(__('pondok.letter_verification.code_required'));
});

it('AC-28: finds the letter after the form redirect', function () {
    $this->followingRedirects()->get('/verifikasi-surat?kode='.rawurlencode($this->letter->formattedVerificationCode()))->assertOk()->assertSee('001/MAK/SKA/X/2026');
});

it('AC-29: sends privacy headers on every kind of response', function () {
    $code = $this->letter->verification_code;

    foreach ([
        'form' => $this->get('/verifikasi-surat')->assertOk(),
        'result' => $this->get("/verifikasi-surat/{$code}")->assertOk(),
        'redirect' => $this->get('/verifikasi-surat?kode=abcd')->assertStatus(302),
        'not found' => $this->get('/verifikasi-surat/ZZZZZZZZZZZZ')->assertNotFound(),
    ] as $response) {
        suratAssertPrivacyHeaders($response);
    }

    Setting::set('modules.dokumen.enabled', false);

    suratAssertPrivacyHeaders($this->get("/verifikasi-surat/{$code}")->assertForbidden());
    suratAssertPrivacyHeaders($this->get('/verifikasi-surat')->assertForbidden());
});

it('AC-29: sends privacy headers on a rate limited response', function () {
    $server = ['REMOTE_ADDR' => '203.0.113.50'];

    foreach (range(1, 30) as $ignored) {
        $this->withServerVariables($server)->get('/verifikasi-surat');
    }

    suratAssertPrivacyHeaders($this->withServerVariables($server)->get('/verifikasi-surat')->assertStatus(429));
});

it('AC-29: keeps the pages standalone and noindex', function () {
    suratAssertStandalonePage($this->get('/verifikasi-surat')->assertOk()->getContent());
    suratAssertStandalonePage(suratVerifyHtml($this->letter->verification_code));
    suratAssertStandalonePage(suratVerifyHtml('ZZZZZZZZZZZZ', 404));

    app(CancelIssuedLetter::class)->handle($this->letter, $this->f->admin, 'Salah');

    suratAssertStandalonePage(suratVerifyHtml($this->letter->verification_code));
});

it('AC-30: rate limits verification', function () {
    $first = ['REMOTE_ADDR' => '203.0.113.10'];
    $second = ['REMOTE_ADDR' => '203.0.113.11'];
    $code = $this->letter->verification_code;

    foreach (range(1, 30) as $ignored) {
        expect($this->withServerVariables($first)->get("/verifikasi-surat/{$code}")->getStatusCode())->not->toBe(429);
    }

    $this->withServerVariables($first)->get("/verifikasi-surat/{$code}")->assertStatus(429);
    $this->withServerVariables($second)->get("/verifikasi-surat/{$code}")->assertOk();

    $this->travel(61)->seconds();

    $this->withServerVariables($first)->get("/verifikasi-surat/{$code}")->assertOk();
});

it('AC-30: rate limits the code form and shares the limit with the result page', function () {
    $server = ['REMOTE_ADDR' => '203.0.113.20'];
    $code = $this->letter->verification_code;

    foreach (range(1, 15) as $ignored) {
        $this->withServerVariables($server)->get('/verifikasi-surat')->assertOk();
        $this->withServerVariables($server)->get("/verifikasi-surat/{$code}")->assertOk();
    }

    $this->withServerVariables($server)->get('/verifikasi-surat')->assertStatus(429);
    $this->withServerVariables($server)->get("/verifikasi-surat/{$code}")->assertStatus(429);
});

it('AC-30: counts unknown codes against the limit too', function () {
    $server = ['REMOTE_ADDR' => '203.0.113.30'];

    foreach (range(1, 30) as $ignored) {
        $this->withServerVariables($server)->get('/verifikasi-surat/ZZZZZZZZZZZZ')->assertNotFound();
    }

    $this->withServerVariables($server)->get('/verifikasi-surat/ZZZZZZZZZZZZ')->assertStatus(429);
});

it('AC-31: closes verification when the module is off', function () {
    $code = $this->letter->verification_code;
    Setting::set('modules.dokumen.enabled', false);

    $this->get('/verifikasi-surat')->assertForbidden();
    $this->get("/verifikasi-surat/{$code}")->assertForbidden();
    $this->get('/verifikasi-surat/ZZZZZZZZZZZZ')->assertForbidden();
    $this->get('/verifikasi-surat?kode=abcd')->assertForbidden();
});

it('AC-31: opens verification again when the module is switched back on', function () {
    $code = $this->letter->verification_code;
    Setting::set('modules.dokumen.enabled', false);
    $this->get("/verifikasi-surat/{$code}")->assertForbidden();

    Setting::set('modules.dokumen.enabled', true);

    $this->get("/verifikasi-surat/{$code}")->assertOk();
});

it('VERIFY: escapes the masked name on the public page', function () {
    $f = $this->f;
    $student = suratStudent($f, attributes: ['name' => '<script>alert(1)</script>']);
    $letter = suratIssue($f, student: $student);

    expect(suratVerifyHtml($letter->verification_code))->not->toContain('<script>alert(1)');
});

it('VERIFY: never reveals the letter type details of other letters through a near-miss code', function () {
    $code = $this->letter->verification_code;
    $near = substr($code, 0, 11).($code[11] === '2' ? '3' : '2');

    expect(suratText(suratVerifyHtml($near, 404)))->not->toContain('001/MAK/SKA/X/2026');
});
