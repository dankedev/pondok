@props(['album', 'headingLevel' => 3])

@php
    $heading = $headingLevel === 2 ? 'h2' : 'h3';
@endphp

<article {{ $attributes->class('flex flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm') }}>
    @if ($album->coverPhoto)
        <img src="{{ $album->coverPhoto->url() }}" alt="" loading="lazy" class="aspect-video w-full object-cover">
    @else
        <div class="flex aspect-video w-full items-center justify-center bg-gray-100">
            <x-heroicon-o-photo class="size-10 text-gray-400" aria-hidden="true" />
        </div>
    @endif
    <div class="flex flex-1 flex-col gap-1 p-4">
        <{{ $heading }} class="text-lg font-semibold text-gray-900">
            <a href="{{ route('portal.albums.show', $album->slug) }}" class="rounded-lg underline-offset-4 hover:text-primary-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">{{ $album->title }}</a>
        </{{ $heading }}>
        <p class="flex flex-wrap gap-x-2 text-sm text-gray-600">
            @if ($album->relationLoaded('category') && $album->category)
                <span>{{ $album->category->name }}</span>
                <span aria-hidden="true">·</span>
            @endif
            <span>{{ trans_choice('pondok.portal.albums.photos_count', $album->photos_count, ['count' => $album->photos_count]) }}</span>
        </p>
    </div>
</article>
