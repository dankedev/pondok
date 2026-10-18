@php
    // Halaman kontak hanya sampai di sini bila profil punya data kontak (aturan Kontak F037).
    $phoneFormatter = $isContact ? app(\App\Actions\Konten\FormatPhoneForDisplay::class) : null;
    $description = $bodyHtml ? app(\App\Actions\Konten\SummarizeHtml::class)->handle($bodyHtml->toHtml(), 160) : null;
    $breadcrumbs = [
        ['label' => __('pondok.portal.nav.home'), 'url' => route('portal.home')],
        ['label' => $page->title],
    ];
@endphp

<x-layouts::public :title="$page->title" :description="$description" :canonical="route('portal.pages.show', $page->slug)" :bare="true">
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:py-20">
        <x-portal.breadcrumb :items="$breadcrumbs" class="mb-3" />
        <h1 class="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">{{ $page->title }}</h1>

        @if ($bodyHtml)
            <div class="portal-prose isolate overflow-hidden break-words mt-8">{!! $bodyHtml !!}</div>
        @endif

        @if ($isContact)
            <x-ui.card :title="__('pondok.portal.contact.title')" class="mt-8">
                @include('portal.partials.contact-list', ['school' => $school, 'phoneFormatter' => $phoneFormatter])
            </x-ui.card>
        @endif

        @if (! $bodyHtml && ! $isContact)
            <x-ui.empty-state icon="heroicon-o-document-text" class="mt-8">{{ __('pondok.portal.pages.empty') }}</x-ui.empty-state>
        @endif
    </div>
</x-layouts::public>
