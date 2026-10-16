@php
    $chips = [['label' => __('pondok.portal.albums.all_categories'), 'url' => route('portal.albums.index'), 'active' => $category === null]];
    foreach ($categories as $item) {
        $chips[] = ['label' => $item->name, 'url' => route('portal.albums.index', ['kategori' => $item->slug]), 'active' => $category?->is($item) ?? false];
    }
    $canonical = route('portal.albums.index', array_filter(['kategori' => $category?->slug, 'page' => $page > 1 ? $page : null]));
@endphp

<x-layouts::public :title="__('pondok.portal.albums.title')" :canonical="$canonical" :bare="true">
    <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6">
        <h1 class="text-2xl font-semibold text-gray-900">{{ __('pondok.portal.albums.title') }}</h1>

        @if ($categories->isNotEmpty())
            <x-portal.filter-chips :items="$chips" :label="__('pondok.portal.albums.filter_label')" class="mt-6" />
        @endif

        @if ($albums->isEmpty())
            <x-ui.empty-state icon="heroicon-o-photo" class="mt-8">{{ __('pondok.portal.albums.empty') }}</x-ui.empty-state>
        @else
            <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($albums as $album)
                    <x-portal.album-card :album="$album" :heading-level="2" />
                @endforeach
            </div>

            {{ $albums->onEachSide(1)->links('portal.partials.pagination') }}
        @endif
    </div>
</x-layouts::public>
