{{-- Kerangka sementara F038 Paket 1; bagian gelombang, syarat, biaya, dan kontak menyusul di Paket 2. --}}
@php
    $title = $page?->title ?? __('pondok.portal.nav.psb_info');
@endphp

<x-layouts::public :title="$title" :canonical="route('psb.info')" :bare="true">
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:py-20">
        <h1 class="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">{{ $title }}</h1>

        @if ($bodyHtml)
            <div class="portal-prose isolate overflow-hidden break-words mt-8">{!! $bodyHtml !!}</div>
        @endif
    </div>
</x-layouts::public>
