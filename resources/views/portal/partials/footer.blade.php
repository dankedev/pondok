{{-- Footer portal: identitas dan kontak sekolah dari `settings`; baris kosong tidak dirender. --}}
@php
    $phoneFormatter = app(\App\Actions\Konten\FormatPhoneForDisplay::class);
    $contentEnabled = \App\Models\Setting::moduleEnabled('konten');
    $psbStatusUrl = \App\Models\Setting::moduleEnabled('psb') && \Illuminate\Support\Facades\Route::has('psb.status') ? route('psb.status') : null;
    $verifyLetterUrl = \App\Models\Setting::moduleEnabled('dokumen') && \Illuminate\Support\Facades\Route::has('dokumen.letters.verify-form') ? route('dokumen.letters.verify-form') : null;
    $socials = array_filter([
        __('pondok.portal.contact.instagram') => $school['instagram_url'],
        __('pondok.portal.contact.facebook') => $school['facebook_url'],
        __('pondok.portal.contact.youtube') => $school['youtube_url'],
    ], fn (?string $url): bool => filled($url) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true));
    $linkClass = 'inline-flex min-h-11 items-center rounded-lg text-base text-gray-700 underline-offset-4 hover:text-primary-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500';
@endphp

<footer class="mt-12 border-t border-gray-200 bg-white">
    <div class="mx-auto grid max-w-6xl gap-8 px-4 py-12 sm:px-6 md:grid-cols-2">
        <div class="space-y-3">
            <p class="text-lg font-semibold text-gray-900">{{ $school['name'] }}</p>
            @include('portal.partials.contact-list', ['school' => $school, 'phoneFormatter' => $phoneFormatter])

            @if ($socials !== [])
                <ul class="flex flex-wrap gap-x-4" aria-label="{{ __('pondok.portal.contact.social') }}">
                    @foreach ($socials as $label => $url)
                        <li><a href="{{ $url }}" rel="noopener" class="{{ $linkClass }}">{{ $label }}</a></li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($contentEnabled || $psbStatusUrl || $verifyLetterUrl)
            <div>
                <p class="text-sm font-semibold text-gray-900">{{ __('pondok.portal.footer.links') }}</p>
                <ul class="mt-2">
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
