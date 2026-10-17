@php
    $canonical = $page > 1 ? route('portal.albums.show', ['slug' => $album->slug, 'page' => $page]) : route('portal.albums.show', $album->slug);
    $firstNumber = $photos->firstItem() ?? 1;
@endphp

<x-layouts::public :title="$album->title" :description="\Illuminate\Support\Str::limit((string) $album->description, 160, '…')" :canonical="$canonical" :og-image="$photos->first()?->url()" :bare="true">
    <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6 lg:py-20">
        <h1 class="break-words text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">{{ $album->title }}</h1>
        @if ($album->category)
            <p class="mt-2 text-sm text-gray-600">{{ $album->category->name }}</p>
        @endif
        @if (filled($album->description))
            <p class="mt-4 max-w-3xl whitespace-pre-line text-base leading-relaxed text-gray-700 lg:text-lg">{{ $album->description }}</p>
        @endif

        <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($photos as $photo)
                <figure class="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-card">
                    <a href="{{ $photo->url() }}" class="block focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                        <img src="{{ $photo->url() }}"
                             alt="{{ filled($photo->caption) ? $photo->caption : __('pondok.portal.albums.photo_alt', ['album' => $album->title, 'number' => $firstNumber + $loop->index]) }}"
                             loading="lazy" class="aspect-video w-full object-cover">
                    </a>
                    @if (filled($photo->caption))
                        <figcaption class="p-3 text-sm text-gray-700">{{ $photo->caption }}</figcaption>
                    @endif
                </figure>
            @endforeach
        </div>

        {{ $photos->onEachSide(1)->links('portal.partials.pagination') }}

        <a href="{{ route('portal.albums.index') }}" class="mt-10 inline-flex min-h-11 items-center gap-2 rounded-lg text-sm font-semibold text-primary-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
            <x-heroicon-o-arrow-left class="size-4" aria-hidden="true" />
            {{ __('pondok.portal.albums.back') }}
        </a>
    </div>
</x-layouts::public>
