@php
    // Susunan beranda dari tema aktif (F102): blok terikat, node kosong, heading, dan latar selang-seling dihitung di `ResolveHomeLayout`.
    $layout = app(\App\Actions\Konten\ResolveHomeLayout::class)->handle($blocks, app(\App\Actions\Konten\ResolveActiveTheme::class)->handle());
@endphp

<x-layouts::public :canonical="route('portal.home')" :bare="true">
    @if ($layout['banner'])
        {{-- Preset tanpa blok hero: pita sederhana nama lembaga sebagai satu-satunya h1. --}}
        <section class="bg-white">
            <div class="mx-auto max-w-6xl px-4 pb-12 pt-16 sm:px-6 lg:pb-16 lg:pt-24">
                <hgroup class="max-w-3xl space-y-4">
                    <h1 class="text-balance break-words text-3xl font-bold tracking-tight text-brand-text sm:text-4xl">{{ $school['name'] }}</h1>
                    @if ($school['tagline'])
                        <p class="whitespace-pre-line text-lg leading-relaxed text-gray-600 lg:text-xl">{{ $school['tagline'] }}</p>
                    @endif
                </hgroup>
            </div>
        </section>
    @endif

    @if ($layout['srH1'])
        {{-- Susunan bebas tanpa hero di posisi h1: judul halaman tetap ada untuk pembaca layar. --}}
        <h1 class="sr-only">{{ $school['name'] }}</h1>
    @endif

    @foreach ($layout['nodes'] as $node)
        <x-portal.theme-node :node="$node" area="home" :school="$school" />
    @endforeach
</x-layouts::public>
