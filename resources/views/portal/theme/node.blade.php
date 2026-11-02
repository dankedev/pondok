{{--
    Pemetaan komponen tema -> view inti (F102). Hanya include berliteral: `component` dari JSON sudah divalidasi
    (`ValidatePortalTheme`/`ThemeComponents`) dan di sini hanya dipakai sebagai kunci `@switch`, tidak pernah menjadi nama view.
    Varian (`variant`, yang pertama = bawaan) memilih berkas tampilan; kelas elemen akar sudah jadi di `$node['class']`
    (`ResolveHomeLayout` untuk beranda, `ThemeStyleMap` di view chrome).
--}}
@php
    $variant = $node['variant'] ?? null;
    $block = $node['block'] ?? null;
    $data = $block['data'] ?? null;
    $headingId = $node['headingId'] ?? null;
    $sectionClass = $node['class'] ?? '';
    $buttons = ($node['inverse'] ?? false) ? ['inverse', 'inverse-secondary'] : ['primary', 'secondary'];
@endphp
@switch($node['component'])
    @case('container')
        @include('portal.theme.container')
        @break
    @case('columns')
        @include('portal.theme.columns')
        @break
    @case('divider')
        @include('portal.theme.divider')
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
    @case('blocks')
        @include('portal.theme.blocks')
        @break
    @case('hero')
        @if ($variant === 'split')
            @include('portal.theme.styled.hero-split')
        @else
            @include('portal.theme.styled.hero-center')
        @endif
        @break
    @case('text_image')
        @include('portal.theme.styled.text_image')
        @break
    @case('feature_grid')
        @if ($variant === 'numbered')
            @include('portal.theme.styled.feature_grid-numbered')
        @else
            @include('portal.theme.styled.feature_grid-cards')
        @endif
        @break
    @case('quote')
        @if ($variant === 'framed')
            @include('portal.theme.styled.quote-framed')
        @else
            @include('portal.theme.styled.quote-plain')
        @endif
        @break
    @case('cta')
        @if ($variant === 'card')
            @include('portal.theme.styled.cta-card')
        @else
            @include('portal.theme.styled.cta-band')
        @endif
        @break
    @case('video')
        @include('portal.theme.styled.video')
        @break
    @case('feed_list')
        @if ($variant === 'list')
            @include('portal.theme.styled.feed_list-list')
        @elseif ($variant === 'magazine')
            @include('portal.theme.styled.feed_list-magazine')
        @else
            @include('portal.theme.styled.feed_list-cards')
        @endif
        @break
@endswitch
