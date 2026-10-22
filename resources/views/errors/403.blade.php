{{--
    F082: wali yang tersesat ke halaman yang bukan haknya (mis. /admin) diberi jalan ke portal wali.
    Dicek dalam rescue(): halaman error tetap tampil walau sesi atau database bermasalah.
--}}
@php
    $portalWaliUrl = rescue(function (): ?string {
        $user = auth()->user();

        return $user?->is_active && $user->hasRole('wali') && \App\Enums\Module::PortalWali->isEnabled()
            ? route('portal-wali.home')
            : null;
    }, null, false);
@endphp
@include('errors.layout', ['portalWaliUrl' => $portalWaliUrl])
