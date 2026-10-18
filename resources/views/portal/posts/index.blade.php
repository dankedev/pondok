@php
    $heading = __('pondok.portal.posts.titles.all');
    $listUrl = fn (array $overrides): string => route('portal.posts.index', array_filter([...$filters, ...$overrides]));
    $categoryChips = [['label' => __('pondok.portal.posts.all_categories'), 'url' => $listUrl(['kategori' => null]), 'active' => $category === null]];
    foreach ($categories as $item) {
        $categoryChips[] = ['label' => $item->name, 'url' => $listUrl(['kategori' => $item->slug]), 'active' => $category?->is($item) ?? false];
    }
    $unitChips = [['label' => __('pondok.portal.posts.all_units'), 'url' => $listUrl(['jenjang' => null]), 'active' => $unit === null]];
    foreach ($units as $item) {
        $unitChips[] = ['label' => $item->code, 'url' => $listUrl(['jenjang' => \Illuminate\Support\Str::lower($item->code)]), 'active' => $unit?->is($item) ?? false];
    }
    $canonical = route('portal.posts.index', array_filter([...$filters, 'page' => $page > 1 ? $page : null]));
    $breadcrumbs = [
        ['label' => __('pondok.portal.nav.home'), 'url' => route('portal.home')],
        ['label' => __('pondok.portal.nav.news')],
    ];
@endphp

<x-layouts::public :title="$heading" :canonical="$canonical" :bare="true">
    <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6 lg:py-20">
        <x-portal.breadcrumb :items="$breadcrumbs" class="mb-3" />
        <h1 class="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">{{ $heading }}</h1>

        <div class="mt-6 space-y-3">
            @if ($categories->isNotEmpty() || $category)
                <x-portal.filter-chips :items="$categoryChips" :label="__('pondok.portal.posts.filter_categories_label')" />
            @endif
            @if ($units->isNotEmpty())
                <x-portal.filter-chips :items="$unitChips" :label="__('pondok.portal.posts.filter_units_label')" />
            @endif
        </div>

        @if ($posts->isEmpty())
            <x-ui.empty-state icon="heroicon-o-newspaper" class="mt-8">{{ __('pondok.portal.posts.empty') }}</x-ui.empty-state>
        @else
            <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($posts as $post)
                    <x-portal.post-card :post="$post" :heading-level="2" />
                @endforeach
            </div>

            {{ $posts->onEachSide(1)->links('portal.partials.pagination') }}
        @endif
    </div>
</x-layouts::public>
