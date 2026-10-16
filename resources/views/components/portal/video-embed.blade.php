@props(['youtubeId', 'title'])

<div {{ $attributes->class('space-y-2') }}>
    <iframe src="{{ \App\Models\Video::embedUrlFor($youtubeId) }}"
            title="{{ $title }}"
            loading="lazy"
            allow="accelerometer; encrypted-media; gyroscope; picture-in-picture"
            allowfullscreen
            referrerpolicy="strict-origin-when-cross-origin"
            class="aspect-video w-full rounded-lg bg-gray-100"></iframe>
    <a href="{{ \App\Actions\Konten\ParseYoutubeId::watchUrl($youtubeId) }}" rel="noopener"
       class="inline-flex min-h-11 items-center gap-2 rounded-lg text-sm font-semibold text-primary-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
        <x-heroicon-o-film class="size-5" aria-hidden="true" />
        {{ __('pondok.portal.videos.watch_on_youtube') }}
    </a>
</div>
