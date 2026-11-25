{{-- F115: tautan ke halaman panduan pondok.org untuk halaman admin ini (peta di config pondok.guides). --}}
<x-filament::link
    :href="config('pondok.guides.base_url').$slug.'/'"
    target="_blank"
    rel="noopener"
    icon="heroicon-o-book-open"
    size="sm"
>
    {{ __('pondok.guides.link') }}
</x-filament::link>
