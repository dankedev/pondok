<?php

namespace App\Actions\Core;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dump data (tanpa skema) ke berkas SQL dengan PHP murni, karena shared
 * hosting tidak menyediakan `exec`/`mysqldump`. Skema dibuat ulang dengan
 * `php artisan migrate` pada versi kode yang sama sebelum berkas diimpor.
 *
 * Tiap tabel ditulis sebagai `DELETE FROM` lalu `INSERT` multi-baris, dibaca
 * ter-chunk dan langsung ditulis ke berkas agar dump tidak ditampung di memori.
 */
class DumpDatabase
{
    public const MAX_STATEMENT_BYTES = 1_000_000;

    public const ROWS_PER_STATEMENT = 500;

    /**
     * Tabel yang dibangun ulang (`migrations` oleh migrate, cache, sesi) atau
     * hanya berisi data sementara (antrean, diagnostik pekerjaan gagal).
     */
    public const SKIPPED_TABLES = ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'];

    /**
     * @return array<string, int> jumlah baris per tabel
     */
    public function handle(string $absolutePath): array
    {
        $connection = DB::connection();
        [$header, $footer] = $this->wrapper($connection->getDriverName());

        $stream = fopen($absolutePath, 'wb');

        if ($stream === false) {
            throw new RuntimeException(__('pondok.backup.write_failed', ['path' => $absolutePath]));
        }

        try {
            $this->write($stream, $header);

            // Satu transaksi baca agar InnoDB memberi snapshot yang konsisten.
            $counts = $connection->transaction(function () use ($connection, $stream): array {
                $counts = [];

                foreach ($this->tables($connection) as $table) {
                    $counts[$table] = $this->dumpTable($connection, $stream, $table);
                }

                return $counts;
            });

            $this->write($stream, $footer);
        } finally {
            fclose($stream);
        }

        return $counts;
    }

    /**
     * @return array{string, string}
     */
    private function wrapper(string $driver): array
    {
        return match ($driver) {
            'mysql', 'mariadb' => ["SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n", "SET FOREIGN_KEY_CHECKS=1;\n"],
            'sqlite' => ["PRAGMA foreign_keys=OFF;\n", "PRAGMA foreign_keys=ON;\n"],
            default => throw new RuntimeException(__('pondok.backup.driver_unsupported', ['driver' => $driver])),
        };
    }

    /**
     * @return list<string>
     */
    private function tables(Connection $connection): array
    {
        $schema = $connection->getSchemaBuilder();
        $names = array_column($schema->getTables($schema->getCurrentSchemaName()), 'name');

        return array_values(array_diff($names, self::SKIPPED_TABLES));
    }

    /**
     * @param  resource  $stream
     */
    private function dumpTable(Connection $connection, $stream, string $table): int
    {
        $grammar = $connection->getQueryGrammar();
        $pdo = $connection->getPdo();

        $columns = collect($connection->getSchemaBuilder()->getColumns($table))
            ->filter(fn (array $column): bool => empty($column['generation']))
            ->pluck('name')
            ->all();

        $wrappedTable = $grammar->wrapTable($table);
        $prefix = "INSERT INTO {$wrappedTable} (".implode(', ', array_map($grammar->wrap(...), $columns)).') VALUES ';

        $this->write($stream, "DELETE FROM {$wrappedTable};\n");

        $query = $connection->table($table)->select($columns);
        $rows = in_array('id', $columns, true) ? $query->lazyById(self::ROWS_PER_STATEMENT) : $query->cursor();

        $count = 0;
        $tuples = [];
        $bytes = strlen($prefix);

        foreach ($rows as $row) {
            $tuple = '('.implode(', ', array_map(fn (mixed $value): string => $this->literal($pdo, $value), array_values((array) $row))).')';
            $length = strlen($tuple) + 2; // pemisah ", " atau penutup ";\n"

            if ($tuples !== [] && (count($tuples) >= self::ROWS_PER_STATEMENT || $bytes + $length > self::MAX_STATEMENT_BYTES)) {
                $this->write($stream, $prefix.implode(', ', $tuples).";\n");
                $tuples = [];
                $bytes = strlen($prefix);
            }

            $tuples[] = $tuple;
            $bytes += $length;
            $count++;
        }

        if ($tuples !== []) {
            $this->write($stream, $prefix.implode(', ', $tuples).";\n");
        }

        return $count;
    }

    private function literal(\PDO $pdo, mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_int($value) => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            default => (string) $pdo->quote((string) $value),
        };
    }

    /**
     * @param  resource  $stream
     */
    private function write($stream, string $content): void
    {
        if (fwrite($stream, $content) !== strlen($content)) {
            throw new RuntimeException(__('pondok.backup.write_failed', ['path' => stream_get_meta_data($stream)['uri']]));
        }
    }
}
