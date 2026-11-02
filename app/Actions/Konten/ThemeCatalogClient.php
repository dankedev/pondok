<?php

namespace App\Actions\Konten;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Satu-satunya pintu jaringan aplikasi untuk katalog tema resmi (F103): unduh `themes.json` dan `theme.json`.
 *
 * Hanya `https` port 443 tanpa userinfo, tanpa IP literal, dan hanya ke host `config('pondok.theme_catalog_url')`.
 * Redirect diikuti manual (maks. 3 hop) dan tiap hop divalidasi ulang dari awal, termasuk `Location` relatif.
 * Tubuh dibaca per potongan dan dihentikan saat melewati batas; `Content-Length` yang lebih besar ditolak sebelum dibaca.
 * Semua kegagalan menjadi `RuntimeException` berpesan generik (isi respons tak pernah ikut ke pesan).
 */
class ThemeCatalogClient
{
    private const MAX_REDIRECTS = 3;

    private const CHUNK_BYTES = 8192;

    /**
     * @throws RuntimeException
     */
    public function get(string $url, int $maxBytes): string
    {
        $host = $this->allowedHost();

        for ($redirects = 0; ; $redirects++) {
            $this->assertAllowed($url, $host);

            $response = $this->request($url);
            $status = $response->getStatusCode();

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $response->getHeaderLine('Location');

                if ($location === '' || $redirects >= self::MAX_REDIRECTS) {
                    $this->fail();
                }

                $url = $this->resolve($url, $location);

                continue;
            }

            if ($status !== 200) {
                $this->fail();
            }

            $length = $response->getHeaderLine('Content-Length');

            if ($length !== '' && (! ctype_digit($length) || (int) $length > $maxBytes)) {
                $this->fail();
            }

            return $this->read($response->getBody(), $maxBytes);
        }
    }

    private function request(string $url): ResponseInterface
    {
        try {
            return Http::withOptions(['allow_redirects' => false, 'stream' => true])
                ->connectTimeout(5)->timeout(15)
                ->get($url)->toPsrResponse();
        } catch (ConnectionException) {
            $this->fail();
        }
    }

    private function read(StreamInterface $stream, int $maxBytes): string
    {
        $body = '';

        while (! $stream->eof()) {
            $body .= $stream->read(self::CHUNK_BYTES);

            if (strlen($body) > $maxBytes) {
                $stream->close();

                $this->fail();
            }
        }

        $stream->close();

        return $body;
    }

    private function allowedHost(): string
    {
        $host = parse_url((string) config('pondok.theme_catalog_url'), PHP_URL_HOST);

        if (! is_string($host) || $host === '' || $this->isIpLiteral($host)) {
            $this->fail();
        }

        return strtolower($host);
    }

    private function assertAllowed(string $url, string $host): void
    {
        $parts = preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1 ? false : parse_url($url);

        if ($parts === false
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== $host
            || isset($parts['user']) || isset($parts['pass'])
            || ($parts['port'] ?? 443) !== 443
            || $this->isIpLiteral((string) $parts['host'])) {
            $this->fail();
        }
    }

    private function isIpLiteral(string $host): bool
    {
        return str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Selesaikan `Location` terhadap URL sekarang (hasilnya tetap divalidasi ulang oleh pemanggil).
     */
    private function resolve(string $base, string $location): string
    {
        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $location) === 1) {
            return $location;
        }

        if (str_starts_with($location, '//')) {
            return "https:{$location}";
        }

        $parts = parse_url($base);
        $origin = "https://{$parts['host']}";

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $directory = substr((string) ($parts['path'] ?? '/'), 0, (int) strrpos((string) ($parts['path'] ?? '/'), '/') + 1);

        return $origin.$this->removeDotSegments($directory.$location);
    }

    private function removeDotSegments(string $path): string
    {
        $output = [];

        foreach (explode('/', $path) as $segment) {
            match ($segment) {
                '.' => null,
                '..' => count($output) > 1 ? array_pop($output) : null,
                default => $output[] = $segment,
            };
        }

        return implode('/', $output);
    }

    private function fail(): never
    {
        throw new RuntimeException(__('pondok.theme_install.errors.download_failed'));
    }
}
