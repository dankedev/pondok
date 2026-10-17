{{-- Widget Media sosial: semua akun dari halaman Kontak & sosial (teks, tanpa ikon). --}}
<ul class="flex flex-wrap gap-x-4" aria-label="{{ __('pondok.portal.contact.social') }}">
    @foreach ($school['social_links'] as $link)
        <li>
            <a href="{{ $link['url'] }}" rel="noopener" class="{{ $linkClass }}">
                {{ $link['platform'] === 'lainnya' ? $link['label'] : __('pondok.portal.social.'.$link['platform']) }}
            </a>
        </li>
    @endforeach
</ul>
