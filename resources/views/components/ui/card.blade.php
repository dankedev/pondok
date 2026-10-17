@props(['title' => null])

<section {{ $attributes->class('rounded-xl border border-gray-100 bg-white p-5 shadow-card sm:p-8') }}>
    @if ($title)
        <h2 class="mb-4 text-lg font-semibold text-gray-900">{{ $title }}</h2>
    @endif

    {{ $slot }}
</section>
