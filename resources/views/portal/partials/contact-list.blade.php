{{-- Kontak lembaga (footer dan halaman kontak): alamat, telepon kantor, email, tautan peta, lalu daftar kontak berlabel. Butuh $school dan $phoneFormatter; $onDark untuk footer navy. --}}
@php
    $onDark ??= false;
    $linkClass = 'inline-flex min-h-11 items-center break-all rounded-lg underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 '
        .($onDark ? 'hover:text-white focus-visible:ring-accent-400' : 'hover:text-primary-700 focus-visible:ring-primary-500');
    $iconClass = 'mt-3 size-5 shrink-0 '.($onDark ? 'text-primary-200' : 'text-gray-500');
    $rows = array_filter([
        $school['address'] ? ['icon' => 'heroicon-o-map-pin', 'label' => __('pondok.portal.contact.address'), 'text' => $school['address'], 'url' => null] : null,
        $school['phone'] ? ['icon' => 'heroicon-o-phone', 'label' => __('pondok.portal.contact.phone'), 'text' => $phoneFormatter->handle($school['phone']), 'url' => 'tel:'.$school['phone']] : null,
        $school['email'] ? ['icon' => 'heroicon-o-envelope', 'label' => __('pondok.portal.contact.email'), 'text' => $school['email'], 'url' => 'mailto:'.$school['email']] : null,
    ]);
@endphp

@if ($rows !== [] || $school['map_url'] || $school['contacts'] !== [])
    <ul @class(['space-y-1 text-base', 'text-primary-100' => $onDark, 'text-gray-700' => ! $onDark])>
        @foreach ($rows as $row)
            <li class="flex items-start gap-3">
                <x-dynamic-component :component="$row['icon']" class="{{ $iconClass }}" aria-hidden="true" />
                <span class="sr-only">{{ $row['label'] }}:</span>
                @if ($row['url'])
                    <a href="{{ $row['url'] }}" class="{{ $linkClass }}">{{ $row['text'] }}</a>
                @else
                    <span class="whitespace-pre-line py-2.5">{{ $row['text'] }}</span>
                @endif
            </li>
        @endforeach

        @if ($school['map_url'])
            <li class="flex items-start gap-3">
                <x-heroicon-o-map class="{{ $iconClass }}" aria-hidden="true" />
                <a href="{{ $school['map_url'] }}" target="_blank" rel="noopener noreferrer" class="{{ $linkClass }} font-semibold {{ $onDark ? 'text-white' : 'text-primary-700' }}">
                    {{ __('pondok.portal.contact.map_link') }}<span class="sr-only"> {{ __('pondok.portal.contact.opens_new_tab') }}</span>
                </a>
            </li>
        @endif

        @foreach ($school['contacts'] as $contact)
            <li class="flex items-start gap-3">
                <x-dynamic-component :component="$contact['is_whatsapp'] ? 'heroicon-o-chat-bubble-left-right' : 'heroicon-o-phone'" class="{{ $iconClass }}" aria-hidden="true" />
                <div class="min-w-0 py-2.5">
                    <p @class(['font-semibold', 'text-white' => $onDark, 'text-gray-900' => ! $onDark])>{{ $contact['label'] }}</p>
                    @if ($contact['name'])
                        <p @class(['text-sm', 'text-primary-200' => $onDark, 'text-gray-600' => ! $onDark])>{{ $contact['name'] }}</p>
                    @endif
                    <a href="{{ $contact['is_whatsapp'] ? $phoneFormatter->whatsappUrl($contact['phone']) : 'tel:'.$contact['phone'] }}" class="{{ $linkClass }}">
                        <span class="sr-only">{{ $contact['is_whatsapp'] ? __('pondok.portal.contact.whatsapp_label') : __('pondok.portal.contact.call_label') }}:</span>
                        {{ $phoneFormatter->handle($contact['phone']) }}
                    </a>
                </div>
            </li>
        @endforeach
    </ul>
@endif
