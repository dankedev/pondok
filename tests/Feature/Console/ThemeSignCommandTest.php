<?php

use App\Actions\Konten\InstallPortalTheme;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
| F103 AC-13. Kontrak: `pondok:theme-sign {path} {--secret-file=} {--key-id=} {--url=}`. `path` = `<slug>/theme.json` (nama folder = slug).
| Berkas rahasia berisi base64 dari kunci rahasia Ed25519 64 byte (sama dengan keluaran `sodium_crypto_sign_secretkey`), tak pernah dari
| config/env/argumen. Sukses: exit 0 dan stdout hanya satu objek JSON entri katalog (`slug, name, description, version, schema_version, min_core,
| size, sha256, url, signature, key_id`; `url` dari `--url`). Gagal: exit 1, pesan Indonesia jelas, tanpa entri, tanpa trace atau pesan Sodium.
| Kunci publik pembanding = `config('pondok.theme_public_keys')[key_id]`.
*/

beforeEach(function () {
    kontenBoot();
    themeInstalledSandbox();
    $this->keys = themeTestKeys(['k1', 'k2']);
    $this->work = sys_get_temp_dir().'/pondok-f103-sign-'.Str::random(10);
    mkdir($this->work, 0777, true);
    $this->package = signedThemePackage('zz-uji', '1.2.0');
});

afterEach(fn () => File::deleteDirectory($this->work));

function tsTheme(string $slug = 'zz-uji', ?string $bytes = null): string
{
    mkdir(test()->work."/{$slug}", 0777, true);
    file_put_contents($path = test()->work."/{$slug}/theme.json", $bytes ?? test()->package['bytes']);

    return $path;
}

function tsSecret(string $content, string $name = 'rahasia.key'): string
{
    file_put_contents($path = test()->work."/{$name}", $content);

    return $path;
}

/**
 * @param  array<string, mixed>  $options
 * @return array{exit: int, output: string}
 */
function tsSign(string $path, array $options = []): array
{
    $exit = Artisan::call('pondok:theme-sign', ['path' => $path, '--url' => 'https://pondok.org/themes/zz-uji/1.2.0/theme.json', ...$options]);

    return ['exit' => $exit, 'output' => Artisan::output()];
}

it('AC-13: prints a catalog entry that InstallPortalTheme accepts, with the url from --url', function () {
    $path = tsTheme();
    $secret = tsSecret(base64_encode($this->keys['k1']['secret'])."\n");

    $run = tsSign($path, ['--secret-file' => $secret, '--key-id' => 'k1']);
    $entry = json_decode(trim($run['output']), true);

    expect($run['exit'])->toBe(0)
        ->and($entry)->toBeArray()
        ->and(array_keys($entry))->toEqualCanonicalizing(['slug', 'name', 'description', 'version', 'schema_version', 'min_core', 'size', 'sha256', 'url', 'signature', 'key_id'])
        ->and($entry)->toMatchArray([
            'slug' => 'zz-uji', 'version' => '1.2.0', 'schema_version' => 1, 'min_core' => '1.0.0', 'key_id' => 'k1',
            'size' => strlen($this->package['bytes']), 'sha256' => hash('sha256', $this->package['bytes']),
            'url' => 'https://pondok.org/themes/zz-uji/1.2.0/theme.json',
        ])
        ->and($entry['signature'])->toBe($this->package['entry']['signature']);

    app(InstallPortalTheme::class)->handle($entry, $this->package['bytes']);

    expect(themeInstalledFile('zz-uji'))->toBe($this->package['bytes']);
});

it('AC-13: signs with k2 when asked, and the entry verifies against k2 only', function () {
    $path = tsTheme();
    $secret = tsSecret(base64_encode($this->keys['k2']['secret']));

    $entry = json_decode(trim(tsSign($path, ['--secret-file' => $secret, '--key-id' => 'k2'])['output']), true);

    app(InstallPortalTheme::class)->handle($entry, $this->package['bytes']);

    expect($entry['key_id'])->toBe('k2')
        ->and(themeInstalledFile('zz-uji'))->toBe($this->package['bytes']);
});

it('AC-13: prints identical output on two runs', function () {
    $path = tsTheme();
    $secret = tsSecret(base64_encode($this->keys['k1']['secret']));
    $options = ['--secret-file' => $secret, '--key-id' => 'k1'];

    $first = tsSign($path, $options);
    $second = tsSign($path, $options);

    expect($first['exit'])->toBe(0)->and($second['output'])->toBe($first['output']);
});

it('AC-13: fails clearly, without an entry, for a bad secret, a key id mismatch, or an invalid theme', function () {
    $good = tsSecret(base64_encode($this->keys['k1']['secret']), 'baik.key');
    $outsider = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $path = tsTheme();
    $wrongFolder = tsTheme('zz-lain');
    $broken = tsTheme('zz-rusak', '{"schema_version":');
    $secretText = base64_encode($this->keys['k1']['secret']);

    $cases = [
        'secret file missing' => [$path, ['--secret-file' => $this->work.'/tidak-ada.key', '--key-id' => 'k1']],
        'secret not base64' => [$path, ['--secret-file' => tsSecret('bukan base64 !!', 'a.key'), '--key-id' => 'k1']],
        'secret of 32 bytes' => [$path, ['--secret-file' => tsSecret(base64_encode(random_bytes(32)), 'b.key'), '--key-id' => 'k1']],
        'secret of 65 bytes' => [$path, ['--secret-file' => tsSecret(base64_encode(random_bytes(65)), 'c.key'), '--key-id' => 'k1']],
        'secret file empty' => [$path, ['--secret-file' => tsSecret('', 'd.key'), '--key-id' => 'k1']],
        'secret does not match the public key of k1' => [$path, ['--secret-file' => tsSecret(base64_encode($outsider), 'e.key'), '--key-id' => 'k1']],
        'k1 secret declared as k2' => [$path, ['--secret-file' => $good, '--key-id' => 'k2']],
        'unknown key id' => [$path, ['--secret-file' => $good, '--key-id' => 'k9']],
        'folder name differs from the manifest slug' => [$wrongFolder, ['--secret-file' => $good, '--key-id' => 'k1']],
        'theme is not valid json' => [$broken, ['--secret-file' => $good, '--key-id' => 'k1']],
        'theme file missing' => [$this->work.'/zz-hilang/theme.json', ['--secret-file' => $good, '--key-id' => 'k1']],
    ];

    foreach ($cases as $label => [$themePath, $options]) {
        $run = tsSign($themePath, $options);

        expect($run['exit'])->toBe(1, $label)
            ->and(trim($run['output']))->not->toBe('', $label)
            ->and($run['output'])->not->toContain('"signature"', $label)
            ->and($run['output'])->not->toContain($secretText, $label)
            ->and(strtolower($run['output']))->not->toContain('sodium', $label)
            ->and($run['output'])->not->toContain('#0 ', $label);
    }
});
