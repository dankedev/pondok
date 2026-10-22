<?php

use App\Actions\Dokumen\CancelIssuedLetter;
use App\Models\AcademicYear;
use App\Models\Setting;
use App\Services\QrCodeRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

beforeEach(function () {
    $this->f = suratFixture();
    $this->f->admin->forceFill(['name' => 'Bu Admin Sekolah'])->save();
    $this->letter = suratIssue($this->f);
});

/**
 * HTML halaman cetak surat sebagai admin-sekolah (200 wajib).
 */
function suratPrintHtml(object $f, int $id): string
{
    return test()->actingAs($f->admin)->get("/admin/letters/{$id}/print")->assertOk()->getContent();
}

/**
 * Pola kunci lang mentah yang tidak boleh tampil di halaman.
 */
function suratRawKeyPattern(): string
{
    return '/pondok\.(letter|issued_letter|letter_verification|letter_numbering|dokumen)\b/';
}

it('AC-18: prints a letter from its snapshot', function () {
    $f = $this->f;
    $letter = $this->letter;
    $response = $this->actingAs($f->admin)->get("/admin/letters/{$letter->id}/print")->assertOk();
    $text = suratText($response->getContent());

    expect(__('pondok.letter.titles.keterangan_aktif'))->not->toBe('pondok.letter.titles.keterangan_aktif')
        ->and($response->headers->get('Content-Type'))->toContain('text/html')
        ->and($text)->toContain("Ma'had Al-Kitab")
        ->toContain(__('pondok.letter.titles.keterangan_aktif'))
        ->toContain('001/MAK/SKA/X/2026')
        ->toContain('15 Oktober 2026')
        ->toContain('Muhammad Fauzan Akbar')
        ->toContain('2026000123')
        ->toContain('4A')
        ->toContain('Persyaratan beasiswa')
        ->toContain('Ust. Hasan')
        ->toContain('Kepala Sekolah')
        ->toContain($letter->verificationUrl())
        ->toContain($letter->formattedVerificationCode())
        ->toContain('Bu Admin Sekolah')
        ->not->toMatch(suratRawKeyPattern())
        ->not->toContain('DIBATALKAN');
});

it('AC-18: renders the qr code through the renderer with the verification url and a label with the letter number', function () {
    $letter = $this->letter;
    $url = $letter->verificationUrl();

    $this->mock(QrCodeRenderer::class, function (MockInterface $mock) use ($url, $letter): void {
        $mock->shouldReceive('svg')->once()
            ->withArgs(fn (string $data, string $label): bool => $data === $url && str_contains($label, $letter->letter_no))
            ->andReturn('<svg data-test="qr"></svg>');
    });

    expect(suratPrintHtml($this->f, $letter->id))->toContain('<svg data-test="qr"></svg>');
});

it('AC-18: prints the letter date in the long format and the signer block with the nip', function () {
    $text = suratText(suratPrintHtml($this->f, $this->letter->id));

    expect($text)->toContain('15 Oktober 2026')->toContain('1987');
});

it('AC-19: stays free of external assets', function () {
    $html = suratPrintHtml($this->f, $this->letter->id);

    expect($html)->toContain('<html lang="id"')
        ->toContain('<svg')
        ->toContain('role="img"')
        ->not->toContain('<?xml')
        ->not->toContain('@vite')
        ->not->toContain('/build/')
        ->not->toContain('<link ')
        ->not->toContain('<script src')
        ->not->toContain('wire:')
        ->and(substr_count($html, '<h1'))->toBe(1);

    preg_match_all('#https?://([^/"\'\s)>]+)#i', $html, $matches);
    $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $host) {
        expect($host)->toBe($appHost);
    }
});

it('AC-19: sizes the page for A4 and uses system colours only', function () {
    $html = suratPrintHtml($this->f, $this->letter->id);

    preg_match('#<style\b[^>]*>(.*?)</style>#is', $html, $style);

    expect($style[1] ?? '')->toContain('@page')->toContain('A4')
        ->not->toMatch('/#[0-9a-fA-F]{3,8}\b/')
        ->not->toMatch('/rgba?\(/i')
        ->not->toMatch('/hsla?\(/i');
});

it('AC-20: prints every letter type', function (string $type) {
    $f = $this->f;
    $letter = suratIssue($f, ['type' => $type, 'purpose' => 'Lomba tahfidz']);
    $html = suratPrintHtml($f, $letter->id);
    $text = suratText($html);
    $heading = $type === 'kartu_santri' ? 'pondok.letter.card_title' : "pondok.letter.titles.{$type}";

    expect(__($heading))->not->toBe($heading)
        ->and($text)->toContain(__($heading))
        ->toContain($letter->letter_no)
        ->toContain('Muhammad Fauzan Akbar')
        ->not->toMatch(suratRawKeyPattern());

    match ($type) {
        'kartu_santri' => expect($html)->toContain('85.6mm 54mm')
            ->and($text)->toContain('2026000123')->toContain($f->sd->name)->not->toContain('Lomba tahfidz')
            ->and($html)->not->toMatch('/size:\s*A4/'),
        'syahadah' => expect($text)->toContain('Lomba tahfidz')->toContain('2026000123'),
        default => expect($text)->toContain('Lomba tahfidz')->and($html)->toMatch('/size:\s*A4/')->not->toContain('85.6mm'),
    };
})->with(['keterangan_aktif', 'pindah', 'rekomendasi', 'syahadah', 'kartu_santri']);

it('AC-20: titles every letter type differently', function () {
    $titles = collect(['keterangan_aktif', 'pindah', 'rekomendasi', 'syahadah'])->map(fn (string $type): string => __("pondok.letter.titles.{$type}"));

    expect($titles->unique()->count())->toBe(4)
        ->and($titles->contains(fn (string $title): bool => str_starts_with($title, 'pondok.')))->toBeFalse();
});

it('AC-21: marks cancelled letters', function () {
    $f = $this->f;
    $this->travelTo(CarbonImmutable::parse('2026-10-20 14:30', 'Asia/Jakarta'));
    app(CancelIssuedLetter::class)->handle($this->letter, $f->admin, 'Salah keperluan');

    $text = suratText(suratPrintHtml($f, $this->letter->id));

    expect(__('pondok.letter.cancelled'))->not->toBe('pondok.letter.cancelled')
        ->and($text)->toContain(__('pondok.letter.cancelled'))
        ->toContain('Salah keperluan')
        ->toContain('001/MAK/SKA/X/2026')
        ->toMatch('/20 (Oktober|Okt) 2026/')
        ->toMatch('/14[.:]30/');
});

it('AC-21: marks a cancelled student card', function () {
    $f = $this->f;
    $card = suratIssue($f, ['type' => 'kartu_santri']);
    app(CancelIssuedLetter::class)->handle($card, $f->admin, 'Kartu hilang');

    $text = suratText(suratPrintHtml($f, $card->id));

    expect($text)->toContain(__('pondok.letter.cancelled'))->toContain('Kartu hilang');
});

it('AC-21: survives a qr failure', function () {
    $letter = $this->letter;
    $this->mock(QrCodeRenderer::class, fn (MockInterface $mock) => $mock->shouldReceive('svg')->andThrow(new RuntimeException('boom')));
    Log::spy();

    $html = suratPrintHtml($this->f, $letter->id);
    $text = suratText($html);

    expect($html)->not->toContain('<svg')
        ->and($text)->toContain($letter->verificationUrl())->toContain($letter->formattedVerificationCode())->toContain('001/MAK/SKA/X/2026');

    Log::shouldHaveReceived('warning')->once();
});

it('AC-22: guards the print route', function (string $case) {
    $f = $this->f;
    $letter = $this->letter;

    match ($case) {
        'guest' => $this->get("/admin/letters/{$letter->id}/print")->assertRedirect(route('filament.admin.auth.login')),
        'other unit' => $this->actingAs($f->scoped)->get("/admin/letters/{$letter->id}/print")->assertNotFound(),
        'unknown id' => $this->actingAs($f->admin)->get('/admin/letters/999999/print')->assertNotFound(),
        'module off' => (function () use ($f, $letter) {
            Setting::set('modules.dokumen.enabled', false);
            $this->actingAs($f->admin)->get("/admin/letters/{$letter->id}/print")->assertForbidden();
            $this->actingAs($f->admin)->get('/admin/letters/999999/print')->assertForbidden();
        })(),
        default => $this->actingAs(suratUser($case))->get("/admin/letters/{$letter->id}/print")->assertForbidden(),
    };
})->with(['guest', 'other unit', 'unknown id', 'module off', 'bendahara', 'kesantrian', 'admin-psb', 'admin-konten', 'musyrif', 'ustadz-tahfidz', 'petugas-pos']);

it('AC-22: lets a unit-scoped admin and super-admin print letters in reach', function () {
    $f = $this->f;
    $own = suratIssue($f, student: $f->s2);

    $this->actingAs($f->scoped)->get("/admin/letters/{$own->id}/print")->assertOk();
    $this->actingAs(suratUser('super-admin'))->get("/admin/letters/{$this->letter->id}/print")->assertOk();
});

it('AC-22: prints a letter of an archived student', function () {
    $f = $this->f;
    $own = suratIssue($f, student: $f->s2);
    $f->s2->delete();

    $this->actingAs($f->scoped)->get("/admin/letters/{$own->id}/print")->assertOk();
});

it('AC-40: falls back when the school profile is empty', function () {
    $f = $this->f;
    Setting::query()->where('key', 'like', 'school.%')->get()->each->delete();
    $letter = suratIssue($f);

    $html = suratPrintHtml($f, $letter->id);
    $text = suratText($html);

    expect($text)->toContain(config('app.name'))
        ->not->toContain(__('pondok.letter.phone'))
        ->and($html)->not->toContain('<img')
        ->and($text)->not->toMatch(suratRawKeyPattern());
});

it('AC-40: prints no logo when the file is missing and a logo when it exists', function () {
    $f = $this->f;
    Setting::set('school.logo_path', 'school/hilang.png');

    expect(suratPrintHtml($f, $this->letter->id))->not->toContain('<img');

    Storage::disk('public')->put('school/logo.png', 'png');
    Setting::set('school.logo_path', 'school/logo.png');

    expect(suratPrintHtml($f, $this->letter->id))->toContain('<img');
});

it('AC-40: prints the frozen address and phone of the school', function () {
    $f = $this->f;
    Setting::set('school.address', 'Jl. Pesantren No. 1');
    Setting::set('school.contacts', [profilContact(['phone' => '0812-0000-1111', 'is_print' => true])]);
    $letter = suratIssue($f);
    Setting::set('school.address', 'Jl. Baru No. 9');

    $text = suratText(suratPrintHtml($f, $letter->id));

    expect($text)->toContain('Jl. Pesantren No. 1')->toContain('0812-0000-1111')->not->toContain('Jl. Baru');
});

it('AC-41: omits missing classroom and academic year', function (string $case) {
    $f = $this->f;
    $student = suratStudent($f, attributes: ['name' => 'Santri Tanpa Rombel', 'nis' => '5550001'], enrolled: false);

    if ($case === 'no active year') {
        AcademicYear::query()->update(['is_active' => false]);
        $student = $f->s1;
    }

    $letter = suratIssue($f, student: $student);
    $text = suratText(suratPrintHtml($f, $letter->id));

    expect($text)->toContain($student->name)->toContain($student->nis)
        ->not->toContain(__('pondok.letter.classroom'))
        ->not->toContain(__('pondok.letter.academic_year'))
        ->not->toContain(': -')
        ->not->toContain(':  ')
        ->not->toMatch(suratRawKeyPattern());
})->with(['no classroom', 'no active year']);

it('AC-41: prints the classroom and academic year when they exist', function () {
    $text = suratText(suratPrintHtml($this->f, $this->letter->id));

    expect(__('pondok.letter.classroom'))->not->toBe('pondok.letter.classroom')
        ->and($text)->toContain(__('pondok.letter.classroom'))->toContain(__('pondok.letter.academic_year'))->toContain('2026/2027');
});

it('VERIFY: escapes user supplied text on the printed letter', function () {
    $f = $this->f;
    $student = suratStudent($f, attributes: ['name' => '<script>alert(1)</script>']);
    $letter = suratIssue($f, ['purpose' => '<img src=x onerror=alert(2)>'], $student);

    $html = suratPrintHtml($f, $letter->id);

    expect($html)->not->toContain('<script>alert(1)')->not->toContain('<img src=x');
});

it('AC-53: prints the print logo and never the desktop or mobile logo on a letter', function () {
    foreach (['cetak', 'desktop', 'mobile'] as $name) {
        Storage::disk('public')->put("school/{$name}.png", $name);
        Setting::set($name === 'cetak' ? 'school.logo_path' : "school.logo_{$name}_path", "school/{$name}.png");
    }
    Setting::set('school.address', 'Jl. Pesantren No. 1');
    Setting::set('school.contacts', [profilContact(['phone' => '0812-0000-1111', 'is_print' => true])]);
    $letter = suratIssue($this->f);

    $html = suratPrintHtml($this->f, $letter->id);

    expect($html)->toContain(Storage::disk('public')->url('school/cetak.png'))
        ->not->toContain('school/desktop.png')->not->toContain('school/mobile.png')
        ->and(suratText($html))->toContain('Jl. Pesantren No. 1')->toContain('0812-0000-1111');
});

it('AC-2 (F044): prints the same frozen identity after the school.phone migration and omits the phone without a print contact', function () {
    $f = $this->f;
    profilRawSet('school.address', 'Jl. Pesantren No. 1');
    profilRawSet('school.phone', '0812-0000-1111');
    (require database_path('migrations/2026_10_19_000001_move_school_phone_to_contacts.php'))->up();
    $letter = suratIssue($f);

    expect(suratText(suratPrintHtml($f, $letter->id)))->toContain('Jl. Pesantren No. 1')->toContain('0812-0000-1111');

    Setting::set('school.contacts', [profilContact(['phone' => '081200007777'])]);
    $other = suratIssue($f);

    expect(suratText(suratPrintHtml($f, $other->id)))->not->toContain('081200007777')->not->toContain('Telp.');
});
