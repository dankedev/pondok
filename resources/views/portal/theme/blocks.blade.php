{{--
    Komponen `blocks` (preset `default` dan `centered`): semua blok aktif sesuai urutan admin lewat view legacy
    `themes/<preset>/blocks/*`, HTML identik sebelum tema JSON (golden). `variant` `centered` hanya mengganti hero, teks + gambar,
    keunggulan, dan kutipan; sisanya memakai tampilan bawaan. Tanpa pembungkus (golden): kait kontrak `data-pt="blocks"` + varian + `data-pt-part` (jenis seksi)
    dipasang pada elemen `<section>` tiap seksi lewat `$pt` dan `$blocksVariant`. Latar selang-seling lama sudah dihitung `ResolveHomeLayout`.
--}}
@php
    $sectionLink = 'inline-flex min-h-11 items-center gap-1 rounded-lg text-sm font-semibold text-primary-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500';
    $sectionHeading = 'text-3xl font-bold tracking-tight text-gray-900 lg:text-4xl';
    $blocksVariant = $node['variant'] ?? 'default';
    $centered = $blocksVariant === 'centered';
@endphp
@foreach ($node['sections'] as $section)
    @php
        $block = $section['block'];
        $data = $block['data'];
        $headingId = $section['headingId'];
        $band = $section['band'];
    @endphp
    @switch($section['component'])
        @case('hero')
            @if ($centered)
                @include('themes.centered.blocks.hero')
            @else
                @include('themes.default.blocks.hero')
            @endif
            @break
        @case('text_image')
            @if ($centered)
                @include('themes.centered.blocks.text_image')
            @else
                @include('themes.default.blocks.text_image')
            @endif
            @break
        @case('feature_grid')
            @if ($centered)
                @include('themes.centered.blocks.features')
            @else
                @include('themes.default.blocks.features')
            @endif
            @break
        @case('quote')
            @if ($centered)
                @include('themes.centered.blocks.quote')
            @else
                @include('themes.default.blocks.quote')
            @endif
            @break
        @case('cta')
            @include('themes.default.blocks.cta')
            @break
        @case('video')
            @include('themes.default.blocks.video')
            @break
        @case('feed_list')
            @include('themes.default.blocks.feed')
            @break
    @endswitch
@endforeach
