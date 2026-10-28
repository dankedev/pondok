<?php

namespace App\Actions\Core;

use App\Services\SystemProbe;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pemeriksaan kesehatan server untuk `pondok:doctor`, `pondok:install`, dan
 * `pondok:upgrade`. Setiap hasil berupa key, status (`ok`, `warning`,
 * `failure`, `skipped`), dan pesan bahasa Indonesia.
 */
class CheckSystemHealth
{
    /** Ekstensi PHP wajib: semua `ext-*` di composer.lock (paket non-dev) ditambah PDO. */
    public const REQUIRED_EXTENSIONS = [
        'ctype', 'dom', 'fileinfo', 'filter', 'hash', 'iconv', 'intl', 'json', 'libxml',
        'mbstring', 'openssl', 'pcre', 'session', 'tokenizer', 'xmlreader', 'zip', 'pdo',
    ];

    /** Urutan pemeriksaan yang ditampilkan. */
    public const CHECKS = [
        'php_version', 'extensions', 'app_key', 'app_env', 'app_url', 'database', 'database_version',
        'migrations', 'writable_paths', 'assets', 'public_storage', 'private_disk', 'persistent_storage', 'app_exposure',
        'drivers', 'scheduler', 'queue', 'php_functions', 'php_limits', 'mail', 'version',
    ];

    public const MIN_PHP_VERSION = '8.3.0';

    public const MIN_UPLOAD_SIZE = '5M';

    public const MIN_MEMORY_LIMIT = '128M';

    /** Segmen path yang lazim menjadi folder web di hosting. */
    private const WEB_ROOT_SEGMENTS = ['public_html', 'www', 'htdocs', 'httpdocs'];

    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    private ?bool $databaseReachable = null;

    public function __construct(private SystemProbe $probe) {}

    /**
     * @return list<array{key: string, status: string, message: string}>
     */
    public function handle(): array
    {
        return $this->only(self::CHECKS);
    }

    /**
     * Menjalankan sebagian pemeriksaan (urutan mengikuti CHECKS).
     *
     * @param  list<string>  $keys
     * @return list<array{key: string, status: string, message: string}>
     */
    public function only(array $keys): array
    {
        $this->databaseReachable = null;

        $results = [];

        foreach (self::CHECKS as $key) {
            if (in_array($key, $keys, true)) {
                [$status, $message] = $this->{'check'.str_replace('_', '', ucwords($key, '_'))}();

                $results[] = ['key' => $key, 'status' => $status, 'message' => $message];
            }
        }

        return $results;
    }

    /**
     * @param  list<array{key: string, status: string, message: string}>  $checks
     */
    public function hasFailures(array $checks): bool
    {
        return collect($checks)->contains('status', 'failure');
    }

    /**
     * Versi minimum: MySQL 8.0 atau MariaDB 10.6. MariaDB dikenali dari string versinya.
     */
    public static function databaseVersionSupported(string $driver, string $version): bool
    {
        $isMariaDb = $driver === 'mariadb' || stripos($version, 'mariadb') !== false;

        if (preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $version, $matches) !== 1) {
            return false;
        }

        return version_compare($matches[1], $isMariaDb ? '10.6.0' : '8.0.0', '>=');
    }

    /**
     * Aplikasi berada di folder web (mis. `public_html`), sehingga `.env` dan
     * `storage` bisa terunduh bila `.htaccess` root tidak berlaku.
     */
    public static function isInsideWebRoot(string $path): bool
    {
        $segments = explode('/', str_replace('\\', '/', $path));

        if (array_intersect($segments, self::WEB_ROOT_SEGMENTS) !== []) {
            return true;
        }

        $documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');

        return $documentRoot !== '' && self::pathIsInside($path, $documentRoot);
    }

    /**
     * Konversi nilai ini seperti `8M`, `1G`, `512K`, `-1` ke byte (`-1` = tanpa batas).
     */
    public static function bytes(string|false $value): int
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkPhpVersion(): array
    {
        $version = $this->probe->phpVersion();

        return version_compare($version, self::MIN_PHP_VERSION, '>=')
            ? ['ok', __('pondok.doctor.messages.php_version_ok', ['version' => $version])]
            : ['failure', __('pondok.doctor.messages.php_version_old', ['version' => $version, 'minimum' => '8.3'])];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkExtensions(): array
    {
        $required = [...self::REQUIRED_EXTENSIONS, $this->pdoDriverExtension()];

        $missing = collect($required)->filter()->unique()
            ->reject(fn (string $extension): bool => $this->probe->extensionLoaded($extension))
            ->sort()->values()->all();

        return $missing === []
            ? ['ok', __('pondok.doctor.messages.extensions_ok')]
            : ['failure', __('pondok.doctor.messages.extensions_missing', ['extensions' => implode(', ', $missing)])];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkAppKey(): array
    {
        return filled(config('app.key'))
            ? ['ok', __('pondok.doctor.messages.app_key_ok')]
            : ['failure', __('pondok.doctor.messages.app_key_missing')];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkAppEnv(): array
    {
        if (! app()->environment('production')) {
            return ['warning', __('pondok.doctor.messages.app_env_not_production', ['env' => app()->environment()])];
        }

        return config('app.debug') === false
            ? ['ok', __('pondok.doctor.messages.app_env_ok')]
            : ['warning', __('pondok.doctor.messages.app_debug_on')];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkAppUrl(): array
    {
        $url = (string) config('app.url');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return ['failure', __('pondok.doctor.messages.app_url_invalid', ['url' => $url])];
        }

        if (! app()->environment('production')) {
            return ['ok', __('pondok.doctor.messages.app_url_ok', ['url' => $url])];
        }

        if (in_array($host, self::LOCAL_HOSTS, true) || preg_match('/\.(test|local|localhost)$/', $host) === 1) {
            return ['warning', __('pondok.doctor.messages.app_url_local', ['url' => $url])];
        }

        return $scheme === 'https'
            ? ['ok', __('pondok.doctor.messages.app_url_ok', ['url' => $url])]
            : ['warning', __('pondok.doctor.messages.app_url_http', ['url' => $url])];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkDatabase(): array
    {
        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");

        try {
            DB::connection()->getPdo();
            $this->databaseReachable = true;

            return ['ok', __('pondok.doctor.messages.database_ok', ['connection' => $connection, 'driver' => $driver])];
        } catch (Throwable $exception) {
            $this->databaseReachable = false;

            return ['failure', __('pondok.doctor.messages.database_failed', [
                'connection' => $connection,
                'driver' => $driver,
                'error' => self::firstLine($exception->getMessage()),
            ])];
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkDatabaseVersion(): array
    {
        $driver = (string) config('database.connections.'.config('database.default').'.driver');

        if (! in_array($driver, ['mysql', 'mariadb'], true) || ! $this->databaseIsReachable()) {
            return ['skipped', __('pondok.doctor.messages.database_version_skipped')];
        }

        try {
            $version = (string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            return ['skipped', __('pondok.doctor.messages.database_version_skipped')];
        }

        return self::databaseVersionSupported($driver, $version)
            ? ['ok', __('pondok.doctor.messages.database_version_ok', ['version' => $version])]
            : ['warning', __('pondok.doctor.messages.database_version_old', ['version' => $version])];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkMigrations(): array
    {
        if (! $this->databaseIsReachable()) {
            return ['skipped', __('pondok.doctor.messages.database_unavailable')];
        }

        try {
            /** @var Migrator $migrator */
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                return ['failure', __('pondok.doctor.messages.migrations_not_installed')];
            }

            $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
            $pending = count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
        } catch (Throwable $exception) {
            return ['failure', __('pondok.doctor.messages.migrations_error', ['error' => self::firstLine($exception->getMessage())])];
        }

        return $pending === 0
            ? ['ok', __('pondok.doctor.messages.migrations_ok')]
            : ['failure', __('pondok.doctor.messages.migrations_pending', ['count' => $pending])];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkWritablePaths(): array
    {
        $unwritable = collect($this->writablePaths())
            ->reject(fn (string $path): bool => $this->probe->isWritable($path))
            ->map(fn (string $path): string => self::relativeToBase($path))
            ->values()->all();

        return $unwritable === []
            ? ['ok', __('pondok.doctor.messages.writable_ok')]
            : ['failure', __('pondok.doctor.messages.writable_failed', ['paths' => implode(', ', $unwritable)])];
    }

    /**
     * Folder yang harus bisa ditulis PHP.
     *
     * @return list<string>
     */
    public function writablePaths(): array
    {
        return [
            storage_path(),
            storage_path('app/private'),
            storage_path('app/public'),
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkAssets(): array
    {
        $missing = [];

        if (! is_file(public_path('build/manifest.json'))) {
            $missing[] = 'public/build';
        }

        if (! is_dir(public_path('js/filament'))) {
            $missing[] = 'public/js/filament';
        }

        return $missing === []
            ? ['ok', __('pondok.doctor.messages.assets_ok')]
            : ['failure', __('pondok.doctor.messages.assets_missing', ['paths' => implode(', ', $missing)])];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkPublicStorage(): array
    {
        $root = (string) config('filesystems.disks.public.root');
        $link = public_path('storage');
        $realRoot = realpath($root);

        $linked = is_link($link) && $realRoot !== false && realpath($link) === $realRoot;
        $direct = $realRoot !== false && self::pathIsInside($realRoot, (string) realpath(public_path()));

        return $linked || $direct
            ? ['ok', __('pondok.doctor.messages.public_storage_ok')]
            : ['warning', __('pondok.doctor.messages.public_storage_missing')];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkPrivateDisk(): array
    {
        $root = (string) config('filesystems.disks.private.root');
        $realRoot = realpath($root) ?: $root;
        $public = realpath(public_path()) ?: public_path();

        return self::pathIsInside($realRoot, $public)
            ? ['failure', __('pondok.doctor.messages.private_disk_public')]
            : ['ok', __('pondok.doctor.messages.private_disk_ok')];
    }

    /**
     * Di Docker, storage tanpa volume hilang setiap rebuild (F099). Di luar
     * container (cPanel/Herd) storage ada di disk server, jadi dianggap aman.
     *
     * @return array{0: string, 1: string}
     */
    private function checkPersistentStorage(): array
    {
        if (! $this->probe->runningInContainer()) {
            return ['ok', __('pondok.doctor.messages.persistent_storage_not_container')];
        }

        if (config('filesystems.default') === 's3') {
            return ['ok', __('pondok.doctor.messages.persistent_storage_s3')];
        }

        return $this->probe->isMountPoint(storage_path())
            ? ['ok', __('pondok.doctor.messages.persistent_storage_ok')]
            : ['warning', __('pondok.doctor.messages.persistent_storage_missing', ['path' => storage_path()])];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkAppExposure(): array
    {
        return self::isInsideWebRoot(realpath(base_path()) ?: base_path())
            ? ['warning', __('pondok.doctor.messages.app_exposed', ['path' => base_path()])]
            : ['ok', __('pondok.doctor.messages.app_exposure_ok')];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkDrivers(): array
    {
        $problems = [];

        if (config('queue.default') !== 'database') {
            $problems[] = 'queue: '.config('queue.default');
        }

        if (! in_array(config('cache.default'), ['database', 'file'], true)) {
            $problems[] = 'cache: '.config('cache.default');
        }

        if (! in_array(config('session.driver'), ['database', 'file'], true)) {
            $problems[] = 'session: '.config('session.driver');
        }

        return $problems === []
            ? ['ok', __('pondok.doctor.messages.drivers_ok')]
            : ['warning', __('pondok.doctor.messages.drivers_unsupported', ['drivers' => implode(', ', $problems)])];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkScheduler(): array
    {
        $path = storage_path(RecordSchedulerHeartbeat::PATH);

        if (! is_file($path)) {
            return ['warning', __('pondok.doctor.messages.scheduler_never')];
        }

        $last = Carbon::createFromTimestamp((int) trim((string) file_get_contents($path)), config('app.timezone'));

        if ($last->greaterThanOrEqualTo(now()->subMinutes(5))) {
            return ['ok', __('pondok.doctor.messages.scheduler_ok')];
        }

        return ['warning', __('pondok.doctor.messages.scheduler_stale', [
            'time' => $last->locale('id')->translatedFormat('j M Y H:i'),
        ])];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkQueue(): array
    {
        if (! $this->databaseIsReachable()) {
            return ['skipped', __('pondok.doctor.messages.database_unavailable')];
        }

        try {
            $stuck = DB::table('jobs')
                ->whereNull('reserved_at')
                ->where('available_at', '<=', now()->subMinutes(10)->timestamp)
                ->count();
            $failed = DB::table('failed_jobs')->count();
        } catch (Throwable $exception) {
            return ['warning', __('pondok.doctor.messages.queue_error', ['error' => self::firstLine($exception->getMessage())])];
        }

        $problems = [];

        if ($stuck > 0) {
            $problems[] = __('pondok.doctor.messages.queue_stuck', ['count' => $stuck]);
        }

        if ($failed > 0) {
            $problems[] = __('pondok.doctor.messages.queue_failed', ['count' => $failed]);
        }

        return $problems === []
            ? ['ok', __('pondok.doctor.messages.queue_ok')]
            : ['warning', implode(' ', $problems)];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkPhpFunctions(): array
    {
        $problems = [];

        if (! $this->probe->functionEnabled('proc_open')) {
            $problems[] = __('pondok.doctor.messages.proc_open_disabled');
        }

        if (! $this->probe->functionEnabled('symlink')) {
            $problems[] = __('pondok.doctor.messages.symlink_disabled');
        }

        return $problems === []
            ? ['ok', __('pondok.doctor.messages.php_functions_ok')]
            : ['warning', implode(' ', $problems)];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkPhpLimits(): array
    {
        $upload = $this->probe->iniGet('upload_max_filesize');
        $post = $this->probe->iniGet('post_max_size');
        $memory = $this->probe->iniGet('memory_limit');

        $uploadBytes = self::bytes($upload);
        $postBytes = self::bytes($post);
        $memoryBytes = self::bytes($memory);

        $problems = [];

        if ($uploadBytes !== -1 && $uploadBytes < self::bytes(self::MIN_UPLOAD_SIZE)) {
            $problems[] = __('pondok.doctor.messages.upload_too_small', ['value' => $upload, 'minimum' => self::MIN_UPLOAD_SIZE]);
        }

        // post_max_size 0 berarti tanpa batas.
        if ($postBytes > 0 && $uploadBytes !== -1 && $postBytes < $uploadBytes) {
            $problems[] = __('pondok.doctor.messages.post_too_small', ['value' => $post, 'upload' => $upload]);
        }

        if ($memoryBytes !== -1 && $memoryBytes < self::bytes(self::MIN_MEMORY_LIMIT)) {
            $problems[] = __('pondok.doctor.messages.memory_too_small', ['value' => $memory, 'minimum' => self::MIN_MEMORY_LIMIT]);
        }

        return $problems === []
            ? ['ok', __('pondok.doctor.messages.php_limits_ok', ['upload' => $upload, 'post' => $post, 'memory' => $memory])]
            : ['warning', implode(' ', $problems).' '.__('pondok.doctor.messages.php_limits_cli_note')];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkMail(): array
    {
        $mailer = (string) config('mail.default');

        return app()->environment('production') && in_array($mailer, ['log', 'array'], true)
            ? ['warning', __('pondok.doctor.messages.mail_not_sent', ['mailer' => $mailer])]
            : ['ok', __('pondok.doctor.messages.mail_ok', ['mailer' => $mailer])];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkVersion(): array
    {
        $path = base_path('VERSION');
        $version = is_file($path) ? trim((string) file_get_contents($path)) : '';

        return ['ok', __('pondok.doctor.messages.version', [
            'version' => $version !== '' ? $version : 'dev',
            'php' => PHP_BINARY,
        ])];
    }

    private function pdoDriverExtension(): ?string
    {
        return match (config('database.connections.'.config('database.default').'.driver')) {
            'mysql', 'mariadb' => 'pdo_mysql',
            'sqlite' => 'pdo_sqlite',
            'pgsql' => 'pdo_pgsql',
            'sqlsrv' => 'pdo_sqlsrv',
            default => null,
        };
    }

    private function databaseIsReachable(): bool
    {
        if ($this->databaseReachable === null) {
            $this->checkDatabase();
        }

        return $this->databaseReachable;
    }

    private static function pathIsInside(string $path, string $directory): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $directory = rtrim(str_replace('\\', '/', $directory), '/');

        return $directory !== '' && ($path === $directory || str_starts_with($path, $directory.'/'));
    }

    private static function relativeToBase(string $path): string
    {
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private static function firstLine(string $message): string
    {
        return trim(strtok($message, "\n") ?: $message);
    }
}
