@props(['title' => null])

<section {{ $attributes->class('rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-6') }}>
    @if ($title)
        <h2 class="mb-4 text-lg font-semibold text-gray-900">{{ $title }}</h2>
    @endif

    {{ $slot }}
</section>
