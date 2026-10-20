{{--
    Info PSB publik (F038). Urutan: gelombang (dengan tombol Daftar bila ada yang dibuka), isi halaman admin,
    syarat berkas, biaya, kontak. Bagian tanpa data tidak dirender; semuanya kosong = empty state.
--}}
@php
    use App\Actions\Konten\GetPsbInfo;
    use App\Models\Payment;

    $title = $page?->title ?? __('pondok.portal.nav.psb_info');
    $description = $bodyHtml ? app(\App\Actions\Konten\SummarizeHtml::class)->handle($bodyHtml->toHtml(), 160) : null;
    $breadcrumbs = [
        ['label' => __('pondok.portal.nav.home'), 'url' => route('portal.home')],
        ['label' => $title],
    ];
    $openWave = collect($waves)->firstWhere('status', GetPsbInfo::WAVE_OPEN);
    $isEmpty = ! $bodyHtml && $waves === [] && $requirements === [] && $fees === [] && $contacts === [];
    $sectionClass = 'mt-12';
    $headingClass = 'text-2xl font-bold tracking-tight text-gray-900';
@endphp

<x-layouts::public :title="$title" :description="$description" :canonical="route('psb.info')" :bare="true">
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:py-20">
        <x-portal.breadcrumb :items="$breadcrumbs" class="mb-3" />
        <h1 class="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">{{ $title }}</h1>

        @if ($waves !== [])
            <section class="mt-8" aria-labelledby="psb-waves">
                <h2 id="psb-waves" class="{{ $headingClass }}">{{ __('pondok.portal.psb_info.waves_title') }}</h2>
                <ul class="mt-4 space-y-3">
                    @foreach ($waves as $wave)
                        @php($isOpen = $wave['status'] === GetPsbInfo::WAVE_OPEN)
                        <li class="rounded-xl border border-gray-100 bg-white p-5 shadow-card">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-lg font-semibold text-gray-900">{{ $wave['name'] }}</p>
                                <x-ui.badge :color="$isOpen ? 'success' : 'info'">
                                    {{ __($isOpen ? 'pondok.portal.psb_info.wave_open' : 'pondok.portal.psb_info.wave_upcoming') }}
                                </x-ui.badge>
                            </div>
                            <p class="mt-1 text-base text-gray-700">
                                {{ __('pondok.portal.psb_info.wave_period', ['opens' => $wave['opens_on']->translatedFormat('j F Y'), 'closes' => $wave['closes_on']->translatedFormat('j F Y')]) }}
                            </p>
                            <p class="text-sm text-gray-600">{{ __('pondok.portal.psb_info.wave_academic_year', ['year' => $wave['academic_year']]) }}</p>
                        </li>
                    @endforeach
                </ul>

                @if ($openWave)
                    <div class="mt-6">
                        <x-ui.button :href="route('psb.register')">{{ __('pondok.portal.psb_info.register') }}</x-ui.button>
                    </div>
                @endif
            </section>
        @endif

        @if ($bodyHtml)
            <div class="portal-prose isolate overflow-hidden break-words {{ $waves === [] ? 'mt-8' : $sectionClass }}">{!! $bodyHtml !!}</div>
        @endif

        @if ($requirements !== [])
            <section class="{{ $sectionClass }}" aria-labelledby="psb-requirements">
                <h2 id="psb-requirements" class="{{ $headingClass }}">{{ __('pondok.portal.psb_info.requirements_title') }}</h2>
                <div class="mt-4 space-y-6">
                    @foreach ($requirements as $group)
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">{{ $group['unit'] ?? __('pondok.portal.psb_info.all_units') }}</h3>
                            <ul class="mt-2 divide-y divide-gray-100 rounded-xl border border-gray-100 bg-white shadow-card">
                                @foreach ($group['items'] as $item)
                                    <li class="flex items-start justify-between gap-3 px-5 py-3">
                                        <span class="min-w-0 break-words text-base text-gray-700">{{ $item['name'] }}</span>
                                        <x-ui.badge :color="$item['is_required'] ? 'primary' : 'gray'" class="shrink-0">
                                            {{ __($item['is_required'] ? 'pondok.portal.psb_info.required' : 'pondok.portal.psb_info.optional') }}
                                        </x-ui.badge>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($fees !== [])
            <section class="{{ $sectionClass }}" aria-labelledby="psb-fees">
                <h2 id="psb-fees" class="{{ $headingClass }}">{{ __('pondok.portal.psb_info.fees_title') }}</h2>
                @if ($openWave)
                    <p class="mt-2 text-base text-gray-600">{{ __('pondok.portal.psb_info.fees_note', ['wave' => $openWave['name'], 'year' => $openWave['academic_year']]) }}</p>
                @endif
                <div class="mt-4 space-y-6">
                    @foreach ($fees as $group)
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">{{ $group['unit'] }}</h3>
                            <dl class="mt-2 divide-y divide-gray-100 rounded-xl border border-gray-100 bg-white shadow-card">
                                @foreach ($group['items'] as $item)
                                    <div class="flex items-start justify-between gap-3 px-5 py-3">
                                        <dt class="min-w-0">
                                            <span class="block break-words text-base text-gray-700">{{ $item['name'] }}</span>
                                            <span class="block text-sm text-gray-600">{{ __('pondok.fee_type.frequencies.'.$item['frequency']) }}</span>
                                        </dt>
                                        <dd class="shrink-0 text-base font-semibold tabular-nums text-gray-900">{{ Payment::formatRupiah($item['amount']) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($contacts !== [])
            <section class="{{ $sectionClass }}" aria-labelledby="psb-contacts">
                <h2 id="psb-contacts" class="{{ $headingClass }}">{{ __('pondok.portal.psb_info.contacts_title') }}</h2>
                <div class="mt-4 rounded-xl border border-gray-100 bg-white p-5 shadow-card">
                    @include('portal.partials.contact-list', [
                        'school' => ['address' => null, 'email' => null, 'map_url' => null, 'contacts' => $contacts],
                        'phoneFormatter' => app(\App\Actions\Konten\FormatPhoneForDisplay::class),
                    ])
                </div>
            </section>
        @endif

        @if ($isEmpty)
            <x-ui.empty-state icon="heroicon-o-clipboard-document-list" class="mt-8">{{ __('pondok.portal.psb_info.empty') }}</x-ui.empty-state>
        @endif
    </div>
</x-layouts::public>
