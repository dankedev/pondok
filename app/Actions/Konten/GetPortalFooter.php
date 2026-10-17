<?php

namespace App\Actions\Konten;

/**
 * Footer portal (F046) untuk layout penuh dan ringkas, dihitung dari widget tersimpan
 * (`portal.footer_widgets`, dibaca dan disanitasi lewat `ParseContentBlocks` area `footer`).
 *
 * `widgets`: hanya widget tampil yang punya isi, urutan tersimpan. Widget kosong: teks tanpa isi
 * dan tanpa logo, tautan tanpa tujuan tersedia, kontak tanpa data kontak, sosial tanpa akun.
 * Tautan semua widget diselesaikan dengan satu panggilan `GetPortalNavigation::resolveItems()`.
 * `compactLinks`: tautan widget `links` pertama yang punya tautan tersedia (footer ringkas).
 */
class GetPortalFooter
{
    public function __construct(
        private readonly ParseContentBlocks $parse,
        private readonly GetPortalNavigation $navigation,
    ) {}

    /**
     * @param  array<string, mixed>  $profile  keluaran `GetSchoolProfile`
     * @return array{widgets: list<array<string, mixed>>, compactLinks: list<array{label: string, url: string, active: bool}>}
     */
    public function handle(array $profile): array
    {
        $stored = $this->parse->handle('footer', $profile['footer_widgets'] ?? null);
        $items = array_filter(array_map(fn (array $widget): ?array => $widget['type'] === 'links' ? $widget['data']['items'] : null, $stored), 'is_array');
        $links = $items === [] ? [] : $this->navigation->resolveItems($items, null);
        $widgets = [];

        foreach ($stored as $index => $widget) {
            $data = $widget['data'];
            $base = ['type' => $widget['type'], 'heading' => $data['heading']];

            $widgets[] = match ($widget['type']) {
                'text' => $data['body'] === null && ! $data['show_logo'] ? null : [...$base, 'body' => $data['body'], 'show_logo' => $data['show_logo']],
                'links' => $links[$index] === [] ? null : [...$base, 'links' => $links[$index]],
                'social' => $profile['social_links'] === [] ? null : $base,
                'contacts' => GetSchoolProfile::hasContactData($profile) ? $base : null,
            };
        }

        $widgets = array_values(array_filter($widgets));
        $firstLinks = array_values(array_filter($widgets, fn (array $widget): bool => $widget['type'] === 'links'))[0] ?? null;

        return ['widgets' => $widgets, 'compactLinks' => $firstLinks['links'] ?? []];
    }
}
