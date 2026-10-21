{{-- Pratinjau warna merek portal (F071). `$brand` = GetPortalTheme::brandTokens() atau null bila portal menolak warna; hanya hex hasil server yang masuk ke `style`. --}}
<div class="flex flex-col gap-3">
    <p class="text-sm font-medium text-gray-950 dark:text-white">{{ __('pondok.school_profile.theme.brand_preview.heading') }}</p>

    @if ($brand === null)
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('pondok.school_profile.theme.brand_preview.rejected') }}</p>
    @else
        <div class="flex flex-wrap items-center gap-x-6 gap-y-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10">
            <p class="text-2xl font-bold tracking-tight" style="color: {{ $brand['brand_text'] }}">{{ __('pondok.school_profile.theme.brand_preview.title_sample') }}</p>
            <span class="inline-flex min-h-11 items-center rounded-full px-6 text-base font-semibold" style="background-color: {{ $brand['brand'] }}; color: {{ $brand['on_brand'] }}">{{ __('pondok.school_profile.theme.brand_preview.button_sample') }}</span>
        </div>

        <ul class="flex flex-col gap-1 text-sm text-gray-600 dark:text-gray-400">
            <li>{{ __('pondok.school_profile.theme.brand_preview.button_uses', ['color' => $brand['brand']]) }}</li>
            @if ($brand['fill_adjusted'])
                <li>{{ __('pondok.school_profile.theme.brand_preview.adjusted_button') }}</li>
            @endif
            <li>{{ __('pondok.school_profile.theme.brand_preview.title_uses', ['color' => $brand['brand_text']]) }}</li>
            @if ($brand['text_adjusted'])
                <li>{{ __('pondok.school_profile.theme.brand_preview.adjusted_title') }}</li>
            @endif
        </ul>
    @endif
</div>
