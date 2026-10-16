@php
    $sectionLink = 'inline-flex min-h-11 items-center gap-1 rounded-lg text-sm font-semibold text-primary-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500';
@endphp

<x-layouts::public :canonical="route('portal.home')" :bare="true">
    <section class="border-b border-gray-200 bg-white">
        <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6 sm:py-16">
            <div class="max-w-3xl space-y-4">
                @if ($psbOpen)
                    <x-ui.badge color="accent">{{ __('pondok.portal.home.psb_open_badge') }}</x-ui.badge>
                @endif
                @if ($school['arabic_name'])
                    <p lang="ar" dir="rtl" class="text-2xl text-gray-900 sm:text-3xl">{{ $school['arabic_name'] }}</p>
                @endif
                @if ($school['hero']['greeting'])
                    <p class="text-base font-semibold text-primary-700">{{ $school['hero']['greeting'] }}</p>
                @endif
                <h1 class="text-3xl font-semibold text-gray-900 sm:text-4xl">{{ $school['hero']['title'] ?? $school['name'] }}</h1>
                @if ($school['hero']['subtitle'] ?? $school['tagline'])
                    <p class="whitespace-pre-line text-lg text-gray-600">{{ $school['hero']['subtitle'] ?? $school['tagline'] }}</p>
                @endif
                @if ($psbOpen)
                    <div class="pt-2">
                        <x-ui.button :href="route('psb.register')">{{ __('pondok.portal.home.psb_cta') }}</x-ui.button>
                    </div>
                @elseif ($contentEnabled && $latestNews->isNotEmpty())
                    <div class="pt-2">
                        <x-ui.button :href="route('portal.posts.index')">{{ __('pondok.portal.home.read_news') }}</x-ui.button>
                    </div>
                @endif
            </div>
        </div>
    </section>

    @if ($heroAlbum)
        <section class="mx-auto max-w-6xl px-4 pt-12 sm:px-6">
            {{-- Slider tanpa JavaScript: geser (sentuh) atau panah kiri/kanan setelah kontainer difokus. --}}
            <div role="region" tabindex="0" aria-label="{{ $heroAlbum['title'] }}"
                 class="flex snap-x snap-mandatory gap-4 overflow-x-auto rounded-lg pb-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                @foreach ($heroAlbum['photos'] as $photo)
                    <img src="{{ $photo->url() }}"
                         alt="{{ $photo->caption ?: __('pondok.portal.albums.photo_alt', ['album' => $heroAlbum['title'], 'number' => $loop->iteration]) }}"
                         loading="lazy"
                         class="aspect-video w-[85%] shrink-0 snap-start rounded-lg bg-gray-100 object-cover sm:w-[60%] lg:w-[45%]">
                @endforeach
            </div>
        </section>
    @endif

    @if ($school['highlights'] !== [])
        <section class="mx-auto max-w-6xl px-4 pt-12 sm:px-6" @if ($school['highlights_heading']) aria-labelledby="seksi-keunggulan" @endif>
            @if ($school['highlights_heading'])
                <h2 id="seksi-keunggulan" class="mb-6 text-2xl font-semibold text-gray-900">{{ $school['highlights_heading'] }}</h2>
            @endif
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($school['highlights'] as $highlight)
                    <li class="rounded-lg border border-gray-200 bg-white p-5">
                        <p class="text-lg font-semibold text-gray-900">{{ $highlight['title'] }}</p>
                        @if ($highlight['description'])
                            <p class="mt-2 whitespace-pre-line text-base text-gray-700">{{ $highlight['description'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($profileVideo || $psbVideo)
        <section class="mx-auto max-w-6xl px-4 pt-12 sm:px-6">
            <div class="grid gap-6 md:grid-cols-2">
                @foreach (array_filter([$profileVideo, $psbVideo]) as $video)
                    <div class="space-y-2">
                        <h3 class="text-lg font-semibold text-gray-900">{{ $video->title }}</h3>
                        <x-portal.video-embed :youtube-id="$video->youtube_id" :title="$video->title" />
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($profileSummary && $profileTitle)
        <section class="mx-auto max-w-6xl px-4 pt-12 sm:px-6" aria-labelledby="seksi-profil">
            <div class="max-w-3xl space-y-3">
                <h2 id="seksi-profil" class="text-2xl font-semibold text-gray-900">{{ $profileTitle }}</h2>
                <p class="text-base text-gray-700">{{ $profileSummary }}</p>
                <a href="{{ route('portal.pages.show', 'profil') }}" class="{{ $sectionLink }}">
                    {{ __('pondok.portal.home.read_profile') }}
                    <x-heroicon-o-arrow-right class="size-4" aria-hidden="true" />
                </a>
            </div>
        </section>
    @endif

    @if ($latestNews->isNotEmpty())
        <section class="mx-auto max-w-6xl px-4 pt-12 sm:px-6" aria-labelledby="seksi-kabar">
            <div class="mb-6 flex flex-wrap items-end justify-between gap-2">
                <h2 id="seksi-kabar" class="text-2xl font-semibold text-gray-900">{{ __('pondok.portal.home.latest_news') }}</h2>
                <a href="{{ route('portal.posts.index') }}" class="{{ $sectionLink }}" aria-label="{{ __('pondok.portal.home.see_all_label', ['section' => __('pondok.portal.home.latest_news')]) }}">{{ __('pondok.portal.home.see_all') }}</a>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($latestNews as $post)
                    <x-portal.post-card :post="$post" />
                @endforeach
            </div>
        </section>
    @endif

    @if ($latestAchievements->isNotEmpty())
        <section class="mx-auto max-w-6xl px-4 pt-12 sm:px-6" aria-labelledby="seksi-prestasi">
            <div class="mb-6 flex flex-wrap items-end justify-between gap-2">
                <h2 id="seksi-prestasi" class="text-2xl font-semibold text-gray-900">{{ __('pondok.portal.home.latest_achievements') }}</h2>
                <a href="{{ route('portal.posts.index', ['jenis' => \App\Models\Post::TYPE_ACHIEVEMENT]) }}" class="{{ $sectionLink }}" aria-label="{{ __('pondok.portal.home.see_all_label', ['section' => __('pondok.portal.home.latest_achievements')]) }}">{{ __('pondok.portal.home.see_all') }}</a>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($latestAchievements as $post)
                    <x-portal.post-card :post="$post" />
                @endforeach
            </div>
        </section>
    @endif

    @if ($featuredVideos->isNotEmpty())
        <section class="mx-auto max-w-6xl px-4 pt-12 sm:px-6" aria-labelledby="seksi-video">
            <div class="mb-6 flex flex-wrap items-end justify-between gap-2">
                <h2 id="seksi-video" class="text-2xl font-semibold text-gray-900">{{ __('pondok.portal.home.featured_videos') }}</h2>
                <a href="{{ route('portal.videos.index') }}" class="{{ $sectionLink }}" aria-label="{{ __('pondok.portal.home.see_all_label', ['section' => __('pondok.portal.home.featured_videos')]) }}">{{ __('pondok.portal.home.see_all') }}</a>
            </div>
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($featuredVideos as $video)
                    <div class="space-y-2">
                        <x-portal.video-embed :youtube-id="$video->youtube_id" :title="$video->title" />
                        <h3 class="text-lg font-semibold text-gray-900">{{ $video->title }}</h3>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($latestAlbums->isNotEmpty())
        <section class="mx-auto max-w-6xl px-4 pt-12 sm:px-6" aria-labelledby="seksi-galeri">
            <div class="mb-6 flex flex-wrap items-end justify-between gap-2">
                <h2 id="seksi-galeri" class="text-2xl font-semibold text-gray-900">{{ __('pondok.portal.home.latest_albums') }}</h2>
                <a href="{{ route('portal.albums.index') }}" class="{{ $sectionLink }}" aria-label="{{ __('pondok.portal.home.see_all_label', ['section' => __('pondok.portal.home.latest_albums')]) }}">{{ __('pondok.portal.home.see_all') }}</a>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($latestAlbums as $album)
                    <x-portal.album-card :album="$album" />
                @endforeach
            </div>
        </section>
    @endif
</x-layouts::public>
