@php
    $isContact = $page->slug === 'kontak';
    $phoneFormatter = app(\App\Actions\Konten\FormatPhoneForDisplay::class);
    $hasContact = $isContact && collect(['address', 'phone', 'whatsapp', 'email'])->contains(fn (string $key): bool => $school[$key] !== null);
    $related = match ($page->slug) {
        'profil' => ['url' => route('portal.pages.show', 'visi-misi'), 'label' => __('pondok.portal.nav.vision')],
        'visi-misi' => ['url' => route('portal.pages.show', 'profil'), 'label' => __('pondok.portal.nav.profile')],
        default => null,
    };
    $description = $bodyHtml ? app(\App\Actions\Konten\SummarizeHtml::class)->handle($bodyHtml->toHtml(), 160) : null;
@endphp

<x-layouts::public :title="$page->title" :description="$description" :canonical="route('portal.pages.show', $page->slug)" :bare="true">
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
        <h1 class="text-2xl font-semibold text-gray-900 sm:text-3xl">{{ $page->title }}</h1>

        @if ($bodyHtml)
            <div class="portal-prose isolate overflow-hidden break-words mt-6">{!! $bodyHtml !!}</div>
        @endif

        @if ($hasContact)
            <x-ui.card :title="__('pondok.portal.contact.title')" class="mt-8">
                @include('portal.partials.contact-list', ['school' => $school, 'phoneFormatter' => $phoneFormatter])
            </x-ui.card>
        @endif

        @if (! $bodyHtml && ! $hasContact)
            <x-ui.empty-state icon="heroicon-o-document-text" class="mt-8">{{ __('pondok.portal.pages.empty') }}</x-ui.empty-state>
        @endif

        @if ($related)
            <a href="{{ $related['url'] }}" class="mt-10 inline-flex min-h-11 items-center gap-2 rounded-lg text-sm font-semibold text-primary-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                {{ $related['label'] }}
                <x-heroicon-o-arrow-right class="size-4" aria-hidden="true" />
            </a>
        @endif
    </div>
</x-layouts::public>
