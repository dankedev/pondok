@props(['post', 'headingLevel' => 3])

@php
    $url = route('portal.posts.show', $post->slug);
    $summary = filled($post->excerpt) ? $post->excerpt : app(\App\Actions\Konten\SummarizeHtml::class)->handle($post->body, 160);
    $cover = $post->coverUrl();
    $heading = $headingLevel === 2 ? 'h2' : 'h3';
@endphp

<article {{ $attributes->class('flex flex-col overflow-hidden rounded-xl border border-gray-100 bg-white shadow-card') }}>
    @if ($cover)
        <img src="{{ $cover }}" alt="" loading="lazy" class="aspect-video w-full object-cover">
    @else
        <div class="flex aspect-video w-full items-center justify-center bg-gray-100">
            <x-heroicon-o-newspaper class="size-10 text-gray-400" aria-hidden="true" />
        </div>
    @endif
    <div class="flex flex-1 flex-col gap-2 p-5">
        <div class="flex flex-wrap items-center gap-2 text-sm text-gray-600">
            <x-ui.badge color="gray">{{ __('pondok.post.types.'.$post->type) }}</x-ui.badge>
            @if ($post->published_at)
                <time datetime="{{ $post->published_at->toAtomString() }}">{{ $post->published_at->translatedFormat('j M Y') }}</time>
            @endif
        </div>
        <{{ $heading }} class="text-lg font-semibold text-gray-900">
            <a href="{{ $url }}" class="rounded-lg underline-offset-4 hover:text-primary-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">{{ $post->title }}</a>
        </{{ $heading }}>
        @if ($summary !== '')
            <p class="text-base text-gray-600">{{ $summary }}</p>
        @endif
    </div>
</article>
