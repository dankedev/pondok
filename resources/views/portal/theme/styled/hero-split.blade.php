{{-- Hero dua kolom: teks kiri, media kanan di >= 1024px; di layar kecil media di atas teks. Level judul dari `ResolveHomeLayout`. --}}
@php
    $hasMedia = $block['album'] !== null || $block['image'] !== null;
@endphp

<section{{ $pt('hero', 'split') }} class="{{ $sectionClass }}" aria-labelledby="{{ $headingId }}">
    <div @class(['grid gap-10 lg:grid-cols-2 lg:items-center lg:gap-12' => $hasMedia])>
        <div @class(['min-w-0 space-y-6', 'max-w-3xl' => ! $hasMedia])>
            @if ($block['psbOpen'])
                <x-ui.badge color="accent">{{ __('pondok.portal.home.psb_open_badge') }}</x-ui.badge>
            @endif
            <hgroup class="space-y-4">
                @if ($data['eyebrow'])
                    <p{{ $pt(part: 'eyebrow') }} class="text-base font-semibold lg:text-lg">{{ $data['eyebrow'] }}</p>
                @endif
                <h{{ $node['level'] }}{{ $pt(part: 'title') }} id="{{ $headingId }}" class="text-balance break-words text-4xl font-bold tracking-tight sm:text-5xl lg:text-6xl">{{ $data['title'] ?? $school['name'] }}</h{{ $node['level'] }}>
                @if ($data['text'])
                    <p{{ $pt(part: 'text') }} class="whitespace-pre-line text-lg leading-relaxed lg:text-xl">{{ $data['text'] }}</p>
                @endif
            </hgroup>
            @if ($block['buttons'] !== [])
                <div{{ $pt(part: 'actions') }} class="flex flex-wrap gap-3 pt-2">
                    @foreach ($block['buttons'] as $button)
                        <x-ui.button :href="$button['url']" :variant="$buttons[$loop->first ? 0 : 1]">{{ $button['label'] }}</x-ui.button>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($hasMedia)
            <div{{ $pt(part: 'media') }} class="order-first min-w-0 lg:order-none">
                @include('portal.theme.styled.hero-media')
            </div>
        @endif
    </div>
</section>
