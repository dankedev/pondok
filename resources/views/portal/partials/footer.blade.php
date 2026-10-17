{{--
    Footer portal generik dari profil lembaga dan navigasi footer (`GetPortalNavigation`); bagian kosong tidak dirender.
    Penuh: logo, nama, deskripsi, kontak dan media sosial (sesuai saklar), kolom Tautan, teks pendek, hak cipta.
    Ringkas ($compact, route `psb.*` dan 404): nama, navigasi footer sebaris, hak cipta.
--}}
@php
    $compact ??= false;
    $footer = $school['footer'];
    $footerLinks = $navigation['footer'];
    // Logo footer di atas tile putih agar logo gelap tetap terbaca di latar navy: logo mobile, lalu desktop.
    $footerLogo = $school['logo_mobile_url'] ?? $school['logo_desktop_url'];
    $showContacts = $footer['show_contacts'];
    $showSocial = $footer['show_social'] && $school['social_links'] !== [];
    $shortText = $footer['text'];
    // Footer navy (satu-satunya section gelap): teks putih/primary-100/primary-200, fokus emas (11.96:1 terhadap navy).
    $linkClass = 'inline-flex min-h-11 items-center rounded-lg text-base text-primary-100 underline-offset-4 hover:text-white hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-400';
    $rights = __('pondok.portal.footer.rights', ['year' => now()->year, 'name' => $school['name']]);
@endphp

<footer class="border-t-4 border-accent-500 bg-primary-950">
    @if ($compact)
        <div class="mx-auto max-w-6xl space-y-3 px-4 py-8 sm:px-6">
            <p class="text-base font-bold text-white">{{ $school['name'] }}</p>
            @if ($footerLinks !== [])
                <ul class="flex flex-wrap gap-x-6" aria-label="{{ __('pondok.portal.footer.links') }}">
                    @foreach ($footerLinks as $link)
                        <li><a href="{{ $link['url'] }}" class="{{ $linkClass }}">{{ $link['label'] }}</a></li>
                    @endforeach
                </ul>
            @endif
            <p class="text-sm text-primary-200">{{ $rights }}</p>
        </div>
    @else
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-2 lg:grid-cols-3 lg:py-16">
            <div class="min-w-0 space-y-3">
                @if ($footerLogo)
                    <span class="inline-flex rounded-xl bg-white p-2">
                        <img src="{{ $footerLogo }}" alt="" class="h-12 w-auto max-w-48 object-contain">
                    </span>
                @endif
                <p class="text-xl font-bold text-white">{{ $school['name'] }}</p>
                @if ($footer['description'])
                    <p class="whitespace-pre-line text-base leading-relaxed text-primary-100">{{ $footer['description'] }}</p>
                @endif
                @if ($showContacts)
                    @include('portal.partials.contact-list', ['school' => $school, 'phoneFormatter' => app(\App\Actions\Konten\FormatPhoneForDisplay::class), 'onDark' => true])
                @endif
                @if ($showSocial)
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

            @if ($footerLinks !== [])
                <div>
                    <p class="text-sm font-semibold text-white">{{ __('pondok.portal.footer.links') }}</p>
                    <ul class="mt-2">
                        @foreach ($footerLinks as $link)
                            <li><a href="{{ $link['url'] }}" class="{{ $linkClass }}">{{ $link['label'] }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($shortText)
                <div class="min-w-0 space-y-2">
                    @if (filled($shortText['title']))
                        <p class="text-sm font-semibold text-white">{{ $shortText['title'] }}</p>
                    @endif
                    <p class="whitespace-pre-line text-base leading-relaxed text-primary-100">{{ $shortText['body'] }}</p>
                </div>
            @endif
        </div>
        <div class="border-t border-white/10">
            <p class="mx-auto max-w-6xl px-4 py-5 text-sm text-primary-200 sm:px-6">{{ $rights }}</p>
        </div>
    @endif
</footer>
