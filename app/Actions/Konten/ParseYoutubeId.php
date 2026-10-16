<?php

namespace App\Actions\Konten;

use InvalidArgumentException;

/**
 * Ambil ID video (11 karakter) dari ID mentah atau tautan YouTube.
 *
 * Diterima: `youtube.com` (www, m, music) `/watch?v=`, `/shorts/`, `/embed/`, `/live/`;
 * `youtu.be/<ID>`; `youtube-nocookie.com/embed/<ID>`. Host dibandingkan persis.
 * Masukan kosong menghasilkan null; selain itu melempar `InvalidArgumentException`.
 */
class ParseYoutubeId
{
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    private const YOUTUBE_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'];

    private const SHORT_HOSTS = ['youtu.be', 'www.youtu.be'];

    private const NOCOOKIE_HOSTS = ['youtube-nocookie.com', 'www.youtube-nocookie.com'];

    public function handle(?string $input): ?string
    {
        $input = trim((string) $input);

        if ($input === '') {
            return null;
        }

        if (preg_match(self::ID_PATTERN, $input) === 1) {
            return $input;
        }

        $id = $this->idFromUrl($input);

        if ($id === null || preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException('Bukan tautan atau ID video YouTube.');
        }

        return $id;
    }

    public static function watchUrl(string $id): string
    {
        return "https://www.youtube.com/watch?v={$id}";
    }

    private function idFromUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');
        $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), fn (string $segment): bool => $segment !== ''));

        if (in_array($host, self::SHORT_HOSTS, true)) {
            return count($segments) === 1 ? $segments[0] : null;
        }

        if (in_array($host, self::NOCOOKIE_HOSTS, true)) {
            return count($segments) === 2 && $segments[0] === 'embed' ? $segments[1] : null;
        }

        if (! in_array($host, self::YOUTUBE_HOSTS, true)) {
            return null;
        }

        if ($segments === ['watch']) {
            parse_str($parts['query'] ?? '', $query);

            return is_string($query['v'] ?? null) ? $query['v'] : null;
        }

        if (count($segments) === 2 && in_array($segments[0], ['shorts', 'embed', 'live'], true)) {
            return $segments[1];
        }

        return null;
    }
}
