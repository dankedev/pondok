@php
    $sectionLink = 'inline-flex min-h-11 items-center gap-1 rounded-lg text-sm font-semibold text-primary-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500';
    $sectionHeading = 'text-3xl font-bold tracking-tight text-gray-900 lg:text-4xl';
    // Latar blok setelah hero berselang-seling. Hanya dipanggil oleh blok yang dirender
    // (blok kosong sudah dibuang `GetPortalHomeData`), sehingga dua latar sama tidak berdampingan.
    $bandIndex = 0;
    $band = function () use (&$bandIndex): string {
        return ['bg-gray-50', 'bg-white'][$bandIndex++ % 2];
    };
@endphp

<x-layouts::public :canonical="route('portal.home')" :bare="true">
    @unless ($hasHero)
        {{-- Tanpa blok hero: pita sederhana nama lembaga sebagai satu-satunya h1. --}}
        <section class="bg-white">
            <div class="mx-auto max-w-6xl px-4 pb-12 pt-16 sm:px-6 lg:pb-16 lg:pt-24">
                <hgroup class="max-w-3xl space-y-4">
                    <h1 class="text-balance break-words text-3xl font-bold tracking-tight text-primary-950 sm:text-4xl">{{ $school['name'] }}</h1>
                    @if ($school['tagline'])
                        <p class="whitespace-pre-line text-lg leading-relaxed text-gray-600 lg:text-xl">{{ $school['tagline'] }}</p>
                    @endif
                </hgroup>
            </div>
        </section>
    @endunless

    @foreach ($blocks as $block)
        @include($block['view'], ['block' => $block, 'data' => $block['data'], 'headingId' => 'blok-'.$loop->iteration])
    @endforeach
</x-layouts::public>
