{{--
    Komponen `columns`: grid dengan satu sel per anak. Beranda: sel kosong sudah dibuang `ResolveHomeLayout`, kolom =
    min(`style.columns`, sel tampil) atau satu di layar kecil dan sebanyak sel pada md; level atas dibungkus seperti `container`.
    Header/footer: semua anak adalah sel. Sel `min-w-0` agar isi panjang tidak melebarkan grid (tanpa overflow-x di 360px).
--}}
@php
    $chrome = $area !== 'home';
    $children = $node['children'];
    $gridClass = $chrome
        ? \App\Actions\Konten\ThemeStyleMap::columns($node['style'] ?? [], count($children)).' '.\App\Actions\Konten\ThemeStyleMap::classes(\Illuminate\Support\Arr::except($node['style'] ?? [], ['columns']))
        : ($node['innerClass'] ?? $node['class']).' '.$node['columnsClass'];
@endphp
@if (! $chrome && $node['top'])<div class="{{ $node['class'] }}"><div class="mx-auto max-w-6xl px-4 sm:px-6">@endif
<div @class([$gridClass, 'grid', 'items-center' => $chrome, 'mx-auto max-w-6xl px-4 sm:px-6' => $chrome && $root])>
    @foreach ($children as $child)
        <div class="min-w-0">
            <x-portal.theme-node :node="$child" :area="$area" :school="$school" :navigation="$navigation" :footer="$footer" :compact="$compact" />
        </div>
    @endforeach
</div>
@if (! $chrome && $node['top'])</div></div>@endif
