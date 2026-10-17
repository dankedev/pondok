@php
    $sectionLink = 'inline-flex min-h-11 items-center gap-1 rounded-lg text-sm font-semibold text-primary-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500';
    $sectionHeading = 'text-3xl font-bold tracking-tight text-gray-900 lg:text-4xl';
    // Latar section setelah hero berselang-seling. Hanya dipanggil oleh section yang dirender,
    // sehingga section kosong (tidak dirender) tidak membuat dua latar sama berdampingan.
    $bandIndex = 0;
    $band = function () use (&$bandIndex): string {
        return ['bg-gray-50', 'bg-white'][$bandIndex++ % 2];
    };
@endphp

<x-layouts::public :canonical="route('portal.home')" :bare="true">
    <section class="relative isolate overflow-x-clip bg-white">
        <div aria-hidden="true" class="portal-decor-dots pointer-events-none absolute right-0 top-12 -z-10 hidden h-72 w-56 lg:block xl:w-72"></div>
        <div @class(['mx-auto max-w-6xl px-4 pt-16 sm:px-6 lg:pt-24', 'pb-10 lg:pb-14' => $heroAlbum, 'pb-16 lg:pb-24' => ! $heroAlbum])>
            <div class="max-w-3xl space-y-6">
                @if ($psbOpen)
                    <x-ui.badge color="accent">{{ __('pondok.portal.home.psb_open_badge') }}</x-ui.badge>
                @endif
                <hgroup class="space-y-4">
                    @if ($school['arabic_name'])
                        <p class="font-arabic text-2xl leading-loose text-primary-950 sm:text-3xl"><span lang="ar" dir="rtl">{{ $school['arabic_name'] }}</span></p>
                    @endif
                    @if ($school['hero']['greeting'])
                        <p class="text-base font-semibold text-primary-700 lg:text-lg">{{ $school['hero']['greeting'] }}</p>
                    @endif
                    <h1 class="text-balance break-words text-4xl font-bold tracking-tight text-primary-950 sm:text-5xl lg:text-6xl">{{ $school['hero']['title'] ?? $school['name'] }}</h1>
                    @if ($school['hero']['subtitle'] ?? $school['tagline'])
                        <p class="whitespace-pre-line text-lg leading-relaxed text-gray-600 lg:text-xl">{{ $school['hero']['subtitle'] ?? $school['tagline'] }}</p>
                    @endif
                </hgroup>
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
        {{-- Kelanjutan hero (putih). Region slider harus tetap di dalam section ini (helper test F036 `profilSlider()`). --}}
        <section class="relative isolate overflow-x-clip bg-white pb-16 lg:pb-24">
            <div class="relative mx-auto max-w-6xl px-4 sm:px-6">
                <div aria-hidden="true" class="portal-decor-ring pointer-events-none absolute -bottom-12 left-0 -z-10 hidden size-40 sm:block lg:size-48"></div>
                <div aria-hidden="true" class="portal-decor-dots pointer-events-none absolute -top-8 right-0 -z-10 hidden h-40 w-56 sm:block"></div>
                {{-- Slider tanpa JavaScript: geser (sentuh) atau panah kiri/kanan setelah kontainer difokus. --}}
                <div role="region" tabindex="0" aria-label="{{ $heroAlbum['title'] }}"
                     class="flex snap-x snap-mandatory gap-4 overflow-x-auto rounded-xl pb-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2">
                    @foreach ($heroAlbum['photos'] as $photo)
                        <img src="{{ $photo->url() }}"
                             alt="{{ $photo->caption ?: __('pondok.portal.albums.photo_alt', ['album' => $heroAlbum['title'], 'number' => $loop->iteration]) }}"
                             loading="lazy"
                             class="aspect-video w-5/6 shrink-0 snap-start rounded-xl bg-gray-100 object-cover shadow-card sm:w-3/5 lg:w-5/12">
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($school['highlights'] !== [])
        <section class="{{ $band() }} py-16 lg:py-24" @if ($school['highlights_heading']) aria-labelledby="seksi-keunggulan" @endif>
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                @if ($school['highlights_heading'])
                    <h2 id="seksi-keunggulan" class="mb-8 {{ $sectionHeading }} lg:mb-10">{{ $school['highlights_heading'] }}</h2>
                @endif
                <ul class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($school['highlights'] as $highlight)
                        <li class="rounded-xl border border-gray-100 bg-white p-6 shadow-card">
                            <p class="text-lg font-semibold text-gray-900">{{ $highlight['title'] }}</p>
                            @if ($highlight['description'])
                                <p class="mt-2 whitespace-pre-line text-base leading-relaxed text-gray-700">{{ $highlight['description'] }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    @if ($profileVideo || $psbVideo)
        <section class="{{ $band() }} py-16 lg:py-24">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <div class="grid gap-6 md:grid-cols-2">
                    @foreach (array_filter([$profileVideo, $psbVideo]) as $video)
                        <div class="space-y-2">
                            <h3 class="text-lg font-semibold text-gray-900">{{ $video->title }}</h3>
                            <x-portal.video-embed :youtube-id="$video->youtube_id" :title="$video->title" />
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($profileSummary && $profileTitle)
        <section class="{{ $band() }} py-16 lg:py-24" aria-labelledby="seksi-profil">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <div class="max-w-3xl space-y-4">
                    <h2 id="seksi-profil" class="{{ $sectionHeading }}">{{ $profileTitle }}</h2>
                    <p class="text-base leading-relaxed text-gray-700 lg:text-lg">{{ $profileSummary }}</p>
                    <a href="{{ route('portal.pages.show', 'profil') }}" class="{{ $sectionLink }}">
                        {{ __('pondok.portal.home.read_profile') }}
                        <x-heroicon-o-arrow-right class="size-4" aria-hidden="true" />
                    </a>
                </div>
            </div>
        </section>
    @endif

    @if ($latestNews->isNotEmpty())
        <section class="{{ $band() }} py-16 lg:py-24" aria-labelledby="seksi-kabar">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <div class="mb-8 flex flex-wrap items-end justify-between gap-2 lg:mb-10">
                    <h2 id="seksi-kabar" class="{{ $sectionHeading }}">{{ __('pondok.portal.home.latest_news') }}</h2>
                    <a href="{{ route('portal.posts.index') }}" class="{{ $sectionLink }}" aria-label="{{ __('pondok.portal.home.see_all_label', ['section' => __('pondok.portal.home.latest_news')]) }}">{{ __('pondok.portal.home.see_all') }}</a>
                </div>
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($latestNews as $post)
                        <x-portal.post-card :post="$post" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($latestAchievements->isNotEmpty())
        <section class="{{ $band() }} py-16 lg:py-24" aria-labelledby="seksi-prestasi">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <div class="mb-8 flex flex-wrap items-end justify-between gap-2 lg:mb-10">
                    <h2 id="seksi-prestasi" class="{{ $sectionHeading }}">{{ __('pondok.portal.home.latest_achievements') }}</h2>
                    <a href="{{ route('portal.posts.index', ['jenis' => \App\Models\Post::TYPE_ACHIEVEMENT]) }}" class="{{ $sectionLink }}" aria-label="{{ __('pondok.portal.home.see_all_label', ['section' => __('pondok.portal.home.latest_achievements')]) }}">{{ __('pondok.portal.home.see_all') }}</a>
                </div>
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($latestAchievements as $post)
                        <x-portal.post-card :post="$post" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($featuredVideos->isNotEmpty())
        <section class="{{ $band() }} py-16 lg:py-24" aria-labelledby="seksi-video">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <div class="mb-8 flex flex-wrap items-end justify-between gap-2 lg:mb-10">
                    <h2 id="seksi-video" class="{{ $sectionHeading }}">{{ __('pondok.portal.home.featured_videos') }}</h2>
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
            </div>
        </section>
    @endif

    @if ($latestAlbums->isNotEmpty())
        <section class="{{ $band() }} py-16 lg:py-24" aria-labelledby="seksi-galeri">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <div class="mb-8 flex flex-wrap items-end justify-between gap-2 lg:mb-10">
                    <h2 id="seksi-galeri" class="{{ $sectionHeading }}">{{ __('pondok.portal.home.latest_albums') }}</h2>
                    <a href="{{ route('portal.albums.index') }}" class="{{ $sectionLink }}" aria-label="{{ __('pondok.portal.home.see_all_label', ['section' => __('pondok.portal.home.latest_albums')]) }}">{{ __('pondok.portal.home.see_all') }}</a>
                </div>
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($latestAlbums as $album)
                        <x-portal.album-card :album="$album" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</x-layouts::public>
