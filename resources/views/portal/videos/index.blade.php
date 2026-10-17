@php
    $canonical = route('portal.videos.index', array_filter(['page' => $page > 1 ? $page : null]));
@endphp

<x-layouts::public :title="__('pondok.portal.videos.title')" :canonical="$canonical" :bare="true">
    <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6 lg:py-20">
        <h1 class="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">{{ __('pondok.portal.videos.title') }}</h1>

        @if ($videos->isEmpty())
            <x-ui.empty-state icon="heroicon-o-film" class="mt-8">{{ __('pondok.portal.videos.empty') }}</x-ui.empty-state>
        @else
            <div class="mt-8 grid gap-8 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($videos as $video)
                    <article class="space-y-2">
                        <x-portal.video-embed :youtube-id="$video->youtube_id" :title="$video->title" />
                        <h2 class="text-lg font-semibold text-gray-900">{{ $video->title }}</h2>
                        @if (filled($video->description))
                            <p class="whitespace-pre-line text-base text-gray-600">{{ $video->description }}</p>
                        @endif
                    </article>
                @endforeach
            </div>

            {{ $videos->onEachSide(1)->links('portal.partials.pagination') }}
        @endif
    </div>
</x-layouts::public>
