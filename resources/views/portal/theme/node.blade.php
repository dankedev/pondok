{{--
    Pemetaan komponen tema -> view inti (F102). Hanya include berliteral: `component` dari JSON sudah divalidasi
    (`ValidatePortalTheme`/`ThemeComponents`) dan di sini hanya dipakai sebagai kunci `@switch`, tidak pernah menjadi nama view.
    Bagian beranda: `style` ada = view bergaya (satu-satunya yang memakai `ThemeStyleMap`); selain itu `variant`
    memilih tampilan bawaan (`split` = Standar, `centered` = Terpusat), HTML sama dengan sebelum tema JSON.
--}}
@php
    $sectionLink = 'inline-flex min-h-11 items-center gap-1 rounded-lg text-sm font-semibold text-primary-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500';
    $sectionHeading = 'text-3xl font-bold tracking-tight text-gray-900 lg:text-4xl';
    $mode = \Illuminate\Support\Arr::has($node, 'style') ? 'styled' : ($node['variant'] ?? 'split');

    if ($mode === 'styled' && $area === 'home') {
        // Latar: selang-seling hitungan renderer (`tone: auto`) atau peta gaya; sisanya langsung dari peta gaya.
        $tone = \Illuminate\Support\Arr::get($node, 'style.tone');
        $sectionClass = trim('portal-gutter '.($band ?? \App\Actions\Konten\ThemeStyleMap::classes(\Illuminate\Support\Arr::only($node['style'], ['tone']))).' '.($band !== null ? 'text-gray-900 ' : '').\App\Actions\Konten\ThemeStyleMap::classes(\Illuminate\Support\Arr::except($node['style'], ['tone'])));
        $onInverse = in_array($tone, ['dark', 'brand'], true);
        $centered = \Illuminate\Support\Arr::get($node, 'style.align') === 'center';
    }
@endphp
@switch($node['component'])
    @case('container')
        @include('portal.theme.container')
        @break
    @case('brand')
        @include('portal.partials.brand')
        @break
    @case('nav_menu')
        @include('portal.partials.nav')
        @break
    @case('footer_widgets')
        @include('portal.theme.footer-widgets')
        @break
    @case('copyright')
        @include('portal.theme.copyright')
        @break
    @case('hero')
        @if ($mode === 'styled')
            @include('portal.theme.styled.hero')
        @elseif ($mode === 'centered')
            @include('themes.centered.blocks.hero')
        @else
            @include('themes.default.blocks.hero')
        @endif
        @break
    @case('text_image')
        @if ($mode === 'styled')
            @include('portal.theme.styled.text_image')
        @elseif ($mode === 'centered')
            @include('themes.centered.blocks.text_image')
        @else
            @include('themes.default.blocks.text_image')
        @endif
        @break
    @case('feature_grid')
        @if ($mode === 'styled')
            @include('portal.theme.styled.feature_grid')
        @elseif ($mode === 'centered')
            @include('themes.centered.blocks.features')
        @else
            @include('themes.default.blocks.features')
        @endif
        @break
    @case('quote')
        @if ($mode === 'styled')
            @include('portal.theme.styled.quote')
        @elseif ($mode === 'centered')
            @include('themes.centered.blocks.quote')
        @else
            @include('themes.default.blocks.quote')
        @endif
        @break
    @case('cta')
        @if ($mode === 'styled')
            @include('portal.theme.styled.cta')
        @else
            @include('themes.default.blocks.cta')
        @endif
        @break
    @case('video')
        @if ($mode === 'styled')
            @include('portal.theme.styled.video')
        @else
            @include('themes.default.blocks.video')
        @endif
        @break
    @case('feed_list')
        @if ($mode === 'styled')
            @include('portal.theme.styled.feed_list')
        @else
            @include('themes.default.blocks.feed')
        @endif
        @break
@endswitch
