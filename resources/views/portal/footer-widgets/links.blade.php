{{-- Widget Tautan: nav berlabel heading widget, atau label bawaan bila heading kosong. --}}
<nav @if ($widget['heading'] !== null) aria-labelledby="{{ $headingId }}" @else aria-label="{{ __('pondok.portal.footer.links') }}" @endif>
    <ul>
        @foreach ($widget['links'] as $link)
            <li><a href="{{ $link['url'] }}" class="{{ $linkClass }}">{{ $link['label'] }}</a></li>
        @endforeach
    </ul>
</nav>
