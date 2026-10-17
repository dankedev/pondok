@props([
    'steps' => [],
    'current' => 1,
])

<nav aria-label="{{ __('pondok.psb_public.step_counter', ['current' => $current, 'total' => count($steps)]) }}" {{ $attributes }}>
    <p class="mb-2 text-sm text-gray-600 sm:hidden">
        {{ __('pondok.psb_public.step_counter', ['current' => $current, 'total' => count($steps)]) }}:
        <span class="font-semibold text-gray-900">{{ $steps[$current - 1] ?? '' }}</span>
    </p>
    <ol class="grid grid-cols-4 gap-2">
        @foreach ($steps as $index => $label)
            @php($number = $index + 1)
            <li @if ($number === $current) aria-current="step" @endif class="flex flex-col gap-1">
                <span @class([
                    'h-1.5 rounded-full',
                    'bg-primary-900' => $number <= $current,
                    'bg-gray-200' => $number > $current,
                ])></span>
                <span @class([
                    'hidden text-sm sm:block',
                    'font-semibold text-gray-900' => $number === $current,
                    'text-gray-600' => $number !== $current,
                ])>{{ $label }}</span>
            </li>
        @endforeach
    </ol>
</nav>
