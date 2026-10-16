{{-- Footer portal: kutipan, identitas, kontak, media sosial, dan tautan dari profil lembaga; bagian kosong tidak dirender. --}}
@php
    $phoneFormatter = app(\App\Actions\Konten\FormatPhoneForDisplay::class);
    $contentEnabled = \App\Models\Setting::moduleEnabled('konten');
    $psbStatusUrl = \App\Models\Setting::moduleEnabled('psb') && \Illuminate\Support\Facades\Route::has('psb.status') ? route('psb.status') : null;
    $verifyLetterUrl = \App\Models\Setting::moduleEnabled('dokumen') && \Illuminate\Support\Facades\Route::has('dokumen.letters.verify-form') ? route('dokumen.letters.verify-form') : null;
    $footer = $school['footer'];
    $hasQuote = $footer['quote_arabic'] || $footer['quote_translation'];
    $quickLinks = $footer['links'];
    $linkClass = 'inline-flex min-h-11 items-center rounded-lg text-base text-gray-700 underline-offset-4 hover:text-primary-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500';
@endphp

<footer class="mt-12 border-t border-gray-200 bg-white">
    @if ($hasQuote)
        <div class="border-b border-gray-200">
            <figure class="mx-auto max-w-3xl space-y-3 px-4 py-10 text-center sm:px-6">
                <blockquote class="space-y-3">
                    @if ($footer['quote_arabic'])
                        <p lang="ar" dir="rtl" class="whitespace-pre-line text-2xl leading-loose text-gray-900">{{ $footer['quote_arabic'] }}</p>
                    @endif
                    @if ($footer['quote_translation'])
                        <p class="whitespace-pre-line text-base text-gray-700">{{ $footer['quote_translation'] }}</p>
                    @endif
                </blockquote>
                @if ($footer['quote_source'])
                    <figcaption class="text-sm text-gray-600">{{ $footer['quote_source'] }}</figcaption>
                @endif
            </figure>
        </div>
    @endif

    <div class="mx-auto grid max-w-6xl gap-8 px-4 py-12 sm:px-6 md:grid-cols-2">
        <div class="min-w-0 space-y-3">
            <p class="text-lg font-semibold text-gray-900">{{ $school['name'] }}</p>
            @if ($footer['description'])
                <p class="whitespace-pre-line text-base text-gray-700">{{ $footer['description'] }}</p>
            @endif
            @include('portal.partials.contact-list', ['school' => $school, 'phoneFormatter' => $phoneFormatter])

            @if ($school['social_links'] !== [])
                <ul class="flex flex-wrap gap-x-4" aria-label="{{ __('pondok.portal.contact.social') }}">
                    @foreach ($school['social_links'] as $link)
                        <li>
                            <a href="{{ $link['url'] }}" rel="noopener" class="{{ $linkClass }}">
                                {{ $link['platform'] === 'lainnya' ? $link['label'] : __('pondok.portal.social.'.$link['platform']) }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($quickLinks !== [] || $contentEnabled || $psbStatusUrl || $verifyLetterUrl)
            <div>
                <p class="text-sm font-semibold text-gray-900">{{ __('pondok.portal.footer.links') }}</p>
                <ul class="mt-2">
                    @foreach ($quickLinks as $link)
                        <li><a href="{{ $link['url'] }}" class="{{ $linkClass }}">{{ $link['label'] }}</a></li>
                    @endforeach
                    @if ($contentEnabled)
                        <li><a href="{{ route('portal.pages.show', 'visi-misi') }}" class="{{ $linkClass }}">{{ __('pondok.portal.nav.vision') }}</a></li>
                    @endif
                    @if ($psbStatusUrl)
                        <li><a href="{{ $psbStatusUrl }}" class="{{ $linkClass }}">{{ __('pondok.portal.footer.psb_status') }}</a></li>
                    @endif
                    @if ($verifyLetterUrl)
                        <li><a href="{{ $verifyLetterUrl }}" class="{{ $linkClass }}">{{ __('pondok.portal.footer.verify_letter') }}</a></li>
                    @endif
                </ul>
            </div>
        @endif
    </div>
    <div class="border-t border-gray-200">
        <p class="mx-auto max-w-6xl px-4 py-4 text-sm text-gray-600 sm:px-6">
            {{ __('pondok.portal.footer.rights', ['year' => now()->year, 'name' => $school['name']]) }}
        </p>
    </div>
</footer>
