<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F046: pindahkan footer F043 (deskripsi, teks pendek, saklar kontak/sosial, navigasi footer)
 * ke daftar widget `portal.footer_widgets` (data saja).
 *
 * Kandidat berurutan seperti tata letak lama: teks deskripsi dengan logo (selalu), kontak
 * (bila saklarnya tidak `false`), media sosial (idem), tautan berjudul "Tautan" (navigasi
 * footer; key tidak ada = bawaan `psb.status` dan `letters.verify`, `[]` = tanpa widget), lalu
 * teks pendek. Lebih dari 4 kandidat: teks pendek digabung ke akhir widget teks pertama, jadi
 * tidak ada data yang hilang. Teks di-escape dengan pengodean `SanitizeBlockText` agar widget
 * tidak berubah saat disimpan ulang.
 *
 * Dilewati bila `portal.footer_widgets` sudah ada (idempoten) atau DB benar-benar kosong
 * (instalasi baru). Key lama hanya dihapus bila migrasi menulis widget. `down()` hanya
 * menghapus `portal.footer_widgets`; isi key lama tidak dipulihkan.
 */
return new class extends Migration
{
    private const LEGACY_KEYS = [
        'portal.footer_description',
        'portal.footer_text',
        'portal.footer_show_contacts',
        'portal.footer_show_social',
        'portal.navigation_footer',
    ];

    /** Pengodean teks Symfony HtmlSanitizer (tanpa `=`, yang dikembalikan `SanitizeBlockText`). */
    private const ENTITY_REPLACEMENTS = [
        '&quot;' => '&#34;', '+' => '&#43;', '@' => '&#64;', '`' => '&#96;',
        '＜' => '&#xFF1C;', '＞' => '&#xFF1E;', '＋' => '&#xFF0B;', '＝' => '&#xFF1D;', '＠' => '&#xFF20;', '｀' => '&#xFF40;',
    ];

    private const DEFAULT_LINKS = ['psb.status', 'letters.verify'];

    private const MAX_WIDGETS = 4;

    public function up(): void
    {
        if ($this->has('portal.footer_widgets') || ! $this->isExistingInstall()) {
            return;
        }

        $description = $this->text($this->read('portal.footer_description'));
        $widgets = [$this->widget('text', ['heading' => null, 'body' => $description === null ? null : '<p>'.$this->escape($description).'</p>', 'show_logo' => true])];

        foreach (['contacts' => 'portal.footer_show_contacts', 'social' => 'portal.footer_show_social'] as $type => $key) {
            if ($this->read($key) !== false) {
                $widgets[] = $this->widget($type, ['heading' => null]);
            }
        }

        $links = $this->links();

        if ($links !== []) {
            $widgets[] = $this->widget('links', ['heading' => 'Tautan', 'items' => $links]);
        }

        $short = $this->read('portal.footer_text');
        $title = is_array($short) ? $this->text($short['title'] ?? null) : null;
        $body = is_array($short) ? $this->text($short['body'] ?? null) : null;

        if ($body !== null) {
            $bodyHtml = '<p>'.implode('<br>', array_map($this->escape(...), preg_split('/\R/u', $body) ?: [])).'</p>';

            if (count($widgets) < self::MAX_WIDGETS) {
                $widgets[] = $this->widget('text', ['heading' => $title, 'body' => $bodyHtml, 'show_logo' => false]);
            } else {
                $titleHtml = $title === null ? '' : '<p><strong>'.$this->escape($title).'</strong></p>';
                $widgets[0]['data']['body'] = ($widgets[0]['data']['body'] ?? '').$titleHtml.$bodyHtml;
            }
        }

        DB::transaction(function () use ($widgets): void {
            $now = now();
            DB::table('settings')->insert([
                'key' => 'portal.footer_widgets',
                'value' => json_encode($widgets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('settings')->whereIn('key', self::LEGACY_KEYS)->delete();
        });
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'portal.footer_widgets')->delete();
    }

    /**
     * Item navigasi footer lama (sudah tervalidasi F043); key tidak ada = bawaan.
     *
     * @return list<array<string, mixed>>
     */
    private function links(): array
    {
        if (! $this->has('portal.navigation_footer')) {
            return array_map(fn (string $type): array => ['type' => $type, 'label' => null], self::DEFAULT_LINKS);
        }

        $items = $this->read('portal.navigation_footer');

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    private function isExistingInstall(): bool
    {
        foreach (['settings', 'pages', 'posts', 'albums', 'videos'] as $table) {
            if (DB::table($table)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: string, visible: bool, data: array<string, mixed>}
     */
    private function widget(string $type, array $data): array
    {
        return ['type' => $type, 'visible' => true, 'data' => $data];
    }

    private function escape(string $text): string
    {
        return strtr(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), self::ENTITY_REPLACEMENTS);
    }

    private function read(string $key): mixed
    {
        $value = DB::table('settings')->where('key', $key)->value('value');

        return is_string($value) ? json_decode($value, true) : null;
    }

    private function has(string $key): bool
    {
        return DB::table('settings')->where('key', $key)->exists();
    }

    private function text(mixed $value): ?string
    {
        return is_scalar($value) && ! is_bool($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
};
