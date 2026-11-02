{{--
    Komponen `container`. Header/footer: `tone` dan `border` di pembungkus penuh lebar; padding, gap, dan width di kontainer
    dalam; header berderet (merek kiri, menu kanan), footer bertumpuk. Beranda: penyusun vertikal berkelas dari `ResolveHomeLayout`;
    level atas = latar penuh lebar, isi dibatasi 72rem oleh pembungkus (bukan `portal-gutter`/padding bagian: itu milik isi
    level atas); bersarang = hanya gaya sendiri. Anak dirender rekursif.
--}}
@if ($area === 'home')
    <div class="{{ $node['class'] }}">
        @if ($node['top'])<div class="mx-auto max-w-6xl px-4 sm:px-6">@endif
        <div class="{{ $node['innerClass'] ?? '' }} flex flex-col">
            @foreach ($node['children'] as $child)
                <x-portal.theme-node :node="$child" :area="$area" :school="$school" />
            @endforeach
        </div>
        @if ($node['top'])</div>@endif
    </div>
@else
    <div class="{{ \App\Actions\Konten\ThemeStyleMap::classes(\Illuminate\Support\Arr::only($node['style'] ?? [], ['tone', 'border'])) }}">
        <div class="{{ \App\Actions\Konten\ThemeStyleMap::classes(\Illuminate\Support\Arr::except($node['style'] ?? [], ['tone', 'border'])) }} flex px-4 sm:px-6 {{ $area === 'header' ? 'flex-wrap items-center justify-between' : 'flex-col' }}">
            @foreach ($node['children'] ?? [] as $child)
                <x-portal.theme-node :node="$child" :area="$area" :school="$school" :navigation="$navigation" :footer="$footer" :compact="$compact" />
            @endforeach
        </div>
    </div>
@endif
