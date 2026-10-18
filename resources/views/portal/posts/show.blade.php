@php
    $summary = filled($post->excerpt) ? $post->excerpt : app(\App\Actions\Konten\SummarizeHtml::class)->handle($post->body, 160);
    $cover = $post->coverUrl();
    $youtubeId = $post->youtubeId();
    $metaLink = 'rounded-lg text-primary-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500';
    $breadcrumbs = [
        ['label' => __('pondok.portal.nav.home'), 'url' => route('portal.home')],
        ['label' => __('pondok.portal.nav.news'), 'url' => route('portal.posts.index')],
        ['label' => $post->title],
    ];
@endphp

<x-layouts::public :title="$post->title" :description="$summary" :canonical="route('portal.posts.show', $post->slug)" og-type="article" :og-image="$cover" :bare="true">
    <article class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:py-20">
        <x-portal.breadcrumb :items="$breadcrumbs" class="mb-4" />

        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-gray-600">
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

        <h1 class="mt-4 break-words text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">{{ $post->title }}</h1>

        @if (filled($post->excerpt))
            <p class="mt-4 text-lg leading-relaxed text-gray-600 lg:text-xl">{{ $post->excerpt }}</p>
        @endif

        @if ($cover)
            <img src="{{ $cover }}" alt="{{ $post->title }}" class="mt-8 aspect-video w-full rounded-xl object-cover shadow-card">
        @endif

        @if ($youtubeId)
            <x-portal.video-embed :youtube-id="$youtubeId" :title="$post->title" class="mt-6" />
        @endif

        <div class="portal-prose isolate overflow-hidden break-words mt-8">{!! $bodyHtml !!}</div>

        <a href="{{ route('portal.posts.index') }}" class="mt-10 inline-flex min-h-11 items-center gap-2 rounded-lg text-sm font-semibold text-primary-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
            <x-heroicon-o-arrow-left class="size-4" aria-hidden="true" />
            {{ __('pondok.portal.posts.back') }}
        </a>
    </article>
</x-layouts::public>
