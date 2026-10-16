{{-- Daftar kontak sekolah (footer dan halaman kontak). Butuh $school dan $phoneFormatter. --}}
@php
    $rows = array_filter([
        $school['address'] ? ['icon' => 'heroicon-o-map-pin', 'label' => __('pondok.portal.contact.address'), 'text' => $school['address'], 'url' => null] : null,
        $school['phone'] ? ['icon' => 'heroicon-o-phone', 'label' => __('pondok.portal.contact.phone'), 'text' => $phoneFormatter->handle($school['phone']), 'url' => 'tel:'.$school['phone']] : null,
        $school['whatsapp'] ? ['icon' => 'heroicon-o-chat-bubble-left-right', 'label' => __('pondok.portal.contact.whatsapp'), 'text' => $phoneFormatter->handle($school['whatsapp']), 'url' => $phoneFormatter->whatsappUrl($school['whatsapp'])] : null,
        $school['email'] ? ['icon' => 'heroicon-o-envelope', 'label' => __('pondok.portal.contact.email'), 'text' => $school['email'], 'url' => 'mailto:'.$school['email']] : null,
    ]);
@endphp

@if ($rows !== [])
    <ul class="space-y-1 text-base text-gray-700">
        @foreach ($rows as $row)
            <li class="flex items-start gap-3">
                <x-dynamic-component :component="$row['icon']" class="mt-3 size-5 shrink-0 text-gray-500" aria-hidden="true" />
                <span class="sr-only">{{ $row['label'] }}:</span>
                @if ($row['url'])
                    <a href="{{ $row['url'] }}" class="inline-flex min-h-11 items-center break-all underline-offset-4 hover:text-primary-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">{{ $row['text'] }}</a>
                @else
                    <span class="whitespace-pre-line py-2.5">{{ $row['text'] }}</span>
                @endif
            </li>
        @endforeach
    </ul>
@endif
