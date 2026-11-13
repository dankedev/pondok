{{-- Hero rata tengah (varian bawaan): teks di tengah, media lebar di bawahnya. Level judul (h1 hanya untuk hero pertama) dari `ResolveHomeLayout`. --}}
@php
    $hasMedia = $block['album'] !== null || $block['image'] !== null;
@endphp

<section{{ $pt('hero', 'center') }} class="{{ $sectionClass }} flex flex-col" aria-labelledby="{{ $headingId }}">
    <div{{ $pt(part: 'content') }} class="mx-auto max-w-3xl space-y-6 text-center">
        @if ($block['psbOpen'])
            <x-ui.badge color="accent" :data-pt-part="$ptActive ? 'badge' : null">{{ __('pondok.portal.home.psb_open_badge') }}</x-ui.badge>
        @endif
        <hgroup{{ $pt(part: 'hgroup') }} class="space-y-4">
            @if ($data['eyebrow'])
                <p{{ $pt(part: 'eyebrow') }} class="text-base font-semibold lg:text-lg">{{ $data['eyebrow'] }}</p>
            @endif
            <h{{ $node['level'] }}{{ $pt(part: 'title') }} id="{{ $headingId }}" class="text-balance break-words text-4xl font-bold tracking-tight sm:text-5xl lg:text-6xl">{{ $data['title'] ?? $school['name'] }}</h{{ $node['level'] }}>
            @if ($data['text'])
                <p{{ $pt(part: 'text') }} class="whitespace-pre-line text-lg leading-relaxed lg:text-xl">{{ $data['text'] }}</p>
            @endif
        </hgroup>
        @if ($block['buttons'] !== [])
            <div{{ $pt(part: 'actions') }} class="flex flex-wrap justify-center gap-3 pt-2">
                @foreach ($block['buttons'] as $button)
                    <x-ui.button :href="$button['url']" :variant="$buttons[$loop->first ? 0 : 1]" :data-pt="$ptActive ? 'button' : null" :data-pt-variant="$ptActive ? $buttons[$loop->first ? 0 : 1] : null">{{ $button['label'] }}</x-ui.button>
                @endforeach
            </div>
        @endif
    </div>

    @if ($hasMedia)
        <div{{ $pt(part: 'media') }} class="mt-10 w-full max-w-4xl self-center">
            @include('portal.theme.styled.hero-media')
        </div>
    @endif
</section>
