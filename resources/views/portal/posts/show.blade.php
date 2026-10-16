@php
    $summary = filled($post->excerpt) ? $post->excerpt : app(\App\Actions\Konten\SummarizeHtml::class)->handle($post->body, 160);
    $cover = $post->coverUrl();
    $youtubeId = $post->youtubeId();
    $metaLink = 'rounded-lg text-primary-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500';
@endphp

<x-layouts::public :title="$post->title" :description="$summary" :canonical="route('portal.posts.show', $post->slug)" og-type="article" :og-image="$cover" :bare="true">
    <article class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-gray-600">
            <x-ui.badge color="gray">{{ __('pondok.post.types.'.$post->type) }}</x-ui.badge>
            <time datetime="{{ $post->published_at->toAtomString() }}">{{ $post->published_at->translatedFormat('j M Y') }}</time>
            @if ($post->category)
                <span>
                    <span class="sr-only">{{ __('pondok.portal.posts.category') }}:</span>
                    <a href="{{ route('portal.posts.index', ['kategori' => $post->category->slug]) }}" class="{{ $metaLink }}">{{ $post->category->name }}</a>
                </span>
            @endif
            @if ($post->unit)
                <span>
                    <span class="sr-only">{{ __('pondok.portal.posts.unit') }}:</span>
                    <a href="{{ route('portal.posts.index', ['jenjang' => \Illuminate\Support\Str::lower($post->unit->code)]) }}" class="{{ $metaLink }}" title="{{ $post->unit->name }}">{{ $post->unit->code }}</a>
                </span>
            @endif
        </div>

        <h1 class="mt-4 text-2xl font-semibold text-gray-900 sm:text-3xl">{{ $post->title }}</h1>

        @if (filled($post->excerpt))
            <p class="mt-4 text-lg text-gray-600">{{ $post->excerpt }}</p>
        @endif

        @if ($cover)
            <img src="{{ $cover }}" alt="{{ $post->title }}" class="mt-6 aspect-video w-full rounded-lg object-cover">
        @endif

        @if ($youtubeId)
            <x-portal.video-embed :youtube-id="$youtubeId" :title="$post->title" class="mt-6" />
        @endif

        <div class="portal-prose isolate overflow-hidden break-words mt-6">{!! $bodyHtml !!}</div>

        <a href="{{ route('portal.posts.index') }}" class="mt-10 inline-flex min-h-11 items-center gap-2 rounded-lg text-sm font-semibold text-primary-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
            <x-heroicon-o-arrow-left class="size-4" aria-hidden="true" />
            {{ __('pondok.portal.posts.back') }}
        </a>
    </article>
</x-layouts::public>
