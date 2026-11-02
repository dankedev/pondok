{{-- Komponen `copyright`: hak cipta dari teks portal (`pondok.portal.footer.rights`), bukan dari tema. --}}
<p class="{{ \App\Actions\Konten\ThemeStyleMap::classes($node['style'] ?? []) }} pt-6 text-sm">{{ __('pondok.portal.footer.rights', ['year' => now()->year, 'name' => $school['name']]) }}</p>
