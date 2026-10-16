@props(['icon' => 'heroicon-o-inbox'])

<div {{ $attributes->class('flex flex-col items-center gap-3 rounded-lg border border-dashed border-gray-300 bg-white px-4 py-12 text-center') }}>
    <x-dynamic-component :component="$icon" class="size-10 text-gray-400" aria-hidden="true" />
    <p class="text-base text-gray-600">{{ $slot }}</p>
    @isset($action)
        <div class="mt-1">{{ $action }}</div>
    @endisset
</div>
