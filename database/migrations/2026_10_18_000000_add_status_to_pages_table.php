<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F037: status halaman (`draft` | `terbit`) dan tautan Visi & misi di footer.
 *
 * Baris lama otomatis `terbit` lewat default kolom. Footer lama selalu menampilkan
 * tautan Visi & misi; tautan itu kini diatur admin, jadi migrasi menambahkannya
 * sebagai item `{label: null, page_id}` agar tampilan instalasi lama tidak berubah.
 * Ditulis dengan `DB::table` dan JSON manual (pola migrasi F036); data rusak tidak
 * memicu exception.
 */
return new class extends Migration
{
    private const FOOTER_KEY = 'portal.footer_links';

    private const MAX_FOOTER_LINKS = 8;

    public function up(): void
    {
        if (! Schema::hasColumn('pages', 'status')) {
            Schema::table('pages', function (Blueprint $table): void {
                $table->string('status', 10)->default('terbit');
            });
        }

        $visionId = DB::table('pages')->where('slug', 'visi-misi')->where('status', 'terbit')->value('id');

        if ($visionId === null) {
            return;
        }

        $stored = $this->read();
        $links = is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
        $linkedPages = array_map(fn (array $link): mixed => $link['page_id'] ?? null, $links);

        if (in_array((int) $visionId, array_map('intval', array_filter($linkedPages, 'is_numeric')), true)
            || count($links) >= self::MAX_FOOTER_LINKS) {
            return;
        }

        $links[] = ['label' => null, 'page_id' => (int) $visionId];
        $this->write($links);
    }

    public function down(): void
    {
        $stored = $this->read();

        if (is_array($stored)) {
            $links = array_values(array_filter($stored, fn (mixed $link): bool => is_array($link) && ! array_key_exists('page_id', $link)));

            if (count($links) !== count($stored)) {
                $this->write($links === [] ? null : $links);
            }
        }

        if (Schema::hasColumn('pages', 'status')) {
            Schema::table('pages', function (Blueprint $table): void {
                $table->dropColumn('status');
            });
        }
    }

    private function read(): mixed
    {
        $value = DB::table('settings')->where('key', self::FOOTER_KEY)->value('value');

        return is_string($value) ? json_decode($value, true) : null;
    }

    private function write(mixed $value): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (DB::table('settings')->where('key', self::FOOTER_KEY)->exists()) {
            DB::table('settings')->where('key', self::FOOTER_KEY)->update(['value' => $encoded, 'updated_at' => now()]);

            return;
        }

        DB::table('settings')->insert(['key' => self::FOOTER_KEY, 'value' => $encoded, 'created_at' => now(), 'updated_at' => now()]);
    }
};
