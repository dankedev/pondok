@props([
    'label',
    'for',
    'error' => null,
    'hint' => null,
])

<div {{ $attributes->class('flex flex-col gap-1') }}>
    <label for="{{ $for }}" class="text-sm font-semibold text-gray-900">{{ $label }}</label>

    {{ $slot }}

    @if ($hint)
        <p id="{{ $for }}-hint" class="text-sm text-gray-600">{{ $hint }}</p>
    @endif

    @error($error ?? $for)
        <p id="{{ $for }}-error" class="text-sm text-danger-700">{{ $message }}</p>
    @enderror
</div>
