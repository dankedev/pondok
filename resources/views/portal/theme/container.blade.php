{{--
    Komponen `container` (header/footer tema): `tone` dan `border` di pembungkus penuh lebar; padding, gap, dan width di
    kontainer dalam. Header berderet (merek kiri, menu kanan), footer bertumpuk. Anak dirender rekursif.
--}}
<div class="{{ \App\Actions\Konten\ThemeStyleMap::classes(\Illuminate\Support\Arr::only($node['style'] ?? [], ['tone', 'border'])) }}">
    <div class="{{ \App\Actions\Konten\ThemeStyleMap::classes(\Illuminate\Support\Arr::except($node['style'] ?? [], ['tone', 'border'])) }} flex px-4 sm:px-6 {{ $area === 'header' ? 'flex-wrap items-center justify-between' : 'flex-col' }}">
        @foreach ($node['children'] ?? [] as $child)
            <x-portal.theme-node :node="$child" :area="$area" :school="$school" :navigation="$navigation" :footer="$footer" :compact="$compact" />
        @endforeach
    </div>
</div>
