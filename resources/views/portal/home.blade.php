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
                <h1 class="text-3xl font-semibold text-gray-900 sm:text-4xl">{{ $school['name'] }}</h1>
                @if ($school['tagline'])
                    <p class="text-lg text-gray-600">{{ $school['tagline'] }}</p>
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

    @if ($profileSummary)
        <section class="mx-auto max-w-6xl px-4 pt-12 sm:px-6" aria-labelledby="seksi-profil">
            <div class="max-w-3xl space-y-3">
                <h2 id="seksi-profil" class="text-2xl font-semibold text-gray-900">{{ __('pondok.portal.home.profile_title') }}</h2>
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
