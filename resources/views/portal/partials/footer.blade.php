{{-- Footer portal: kutipan, identitas, kontak, media sosial, dan tautan dari profil lembaga; bagian kosong tidak dirender. --}}
@php
    $phoneFormatter = app(\App\Actions\Konten\FormatPhoneForDisplay::class);
    $psbStatusUrl = \App\Models\Setting::moduleEnabled('psb') && \Illuminate\Support\Facades\Route::has('psb.status') ? route('psb.status') : null;
    $verifyLetterUrl = \App\Models\Setting::moduleEnabled('dokumen') && \Illuminate\Support\Facades\Route::has('dokumen.letters.verify-form') ? route('dokumen.letters.verify-form') : null;
    $footer = $school['footer'];
    $hasQuote = $footer['quote_arabic'] || $footer['quote_translation'];
    // Tautan cepat sudah di-resolve `GetPortalNavigation` (tautan halaman mengikuti slug dan status).
    $quickLinks = $footerLinks;
    // Footer navy (satu-satunya section gelap): teks putih/primary-100/primary-200, fokus emas (11.96:1 terhadap navy).
    $linkClass = 'inline-flex min-h-11 items-center rounded-lg text-base text-primary-100 underline-offset-4 hover:text-white hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-400';
@endphp

<footer class="border-t-4 border-accent-500 bg-primary-950">
    @if ($hasQuote)
        <div class="border-b border-white/10">
            <figure class="mx-auto max-w-3xl space-y-3 px-4 py-12 text-center sm:px-6 lg:py-16">
                <blockquote class="space-y-3">
                    @if ($footer['quote_arabic'])
                        <p lang="ar" dir="rtl" class="whitespace-pre-line font-arabic text-2xl leading-loose text-white sm:text-3xl">{{ $footer['quote_arabic'] }}</p>
                    @endif
                    @if ($footer['quote_translation'])
                        <p class="whitespace-pre-line text-base leading-relaxed text-primary-100 lg:text-lg">{{ $footer['quote_translation'] }}</p>
                    @endif
                </blockquote>
                @if ($footer['quote_source'])
                    <figcaption class="text-sm text-primary-200">{{ $footer['quote_source'] }}</figcaption>
                @endif
            </figure>
        </div>
    @endif

    <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-2 lg:py-16">
        <div class="min-w-0 space-y-3">
            <p class="text-xl font-bold text-white">{{ $school['name'] }}</p>
            @if ($footer['description'])
                <p class="whitespace-pre-line text-base leading-relaxed text-primary-100">{{ $footer['description'] }}</p>
            @endif
            @include('portal.partials.contact-list', ['school' => $school, 'phoneFormatter' => $phoneFormatter, 'onDark' => true])

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

        @if ($quickLinks !== [] || $psbStatusUrl || $verifyLetterUrl)
            <div>
                <p class="text-sm font-semibold text-white">{{ __('pondok.portal.footer.links') }}</p>
                <ul class="mt-2">
                    @foreach ($quickLinks as $link)
                        <li><a href="{{ $link['url'] }}" class="{{ $linkClass }}">{{ $link['label'] }}</a></li>
                    @endforeach
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
    <div class="border-t border-white/10">
        <p class="mx-auto max-w-6xl px-4 py-5 text-sm text-primary-200 sm:px-6">
            {{ __('pondok.portal.footer.rights', ['year' => now()->year, 'name' => $school['name']]) }}
        </p>
    </div>
</footer>
