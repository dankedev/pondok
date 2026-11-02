{{--
    Renderer node tema portal (F102): satu node `theme.json` (rekursif lewat `container`) menjadi HTML.
    Semua data dioper eksplisit; node tidak membawa teks atau URL. Pemetaan komponen ke view ada di `portal.theme.node`
    (include berliteral, tidak pernah nama view dari JSON).

    Gagal render: bagian beranda diganti komponen bawaan inti, header/footer diganti partial inti (`portal.partials.*`),
    keduanya dengan `Log::warning`. Node anak chrome melempar ulang supaya akar yang memutuskan cadangannya.
--}}
@props([
    'node',
    'area',
    'root' => false,
    'school' => null,
    'navigation' => null,
    'footer' => null,
    'compact' => false,
    'block' => null,
    'headingId' => null,
    'band' => null,
])

@php
    $context = ['node' => $node, 'area' => $area, 'school' => $school, 'navigation' => $navigation, 'footer' => $footer, 'compact' => $compact, 'block' => $block, 'data' => $block['data'] ?? null, 'headingId' => $headingId, 'band' => $band];
    $fallback = false;

    try {
        $html = view('portal.theme.node', $context)->render();
    } catch (\Throwable $e) {
        if ($area !== 'home' && ! $root) {
            throw $e;
        }

        $fallback = true;
        \Illuminate\Support\Facades\Log::warning('Portal theme component failed; using the core component.', ['area' => $area, 'component' => $node['component'], 'error' => $e->getMessage()]);

        $html = match ($area) {
            'header' => view('portal.partials.header', $context)->render(),
            'footer' => view('portal.partials.footer', $context)->render(),
            default => view('portal.theme.node', [...$context, 'node' => ['component' => $node['component'], 'variant' => 'split'], 'band' => $band ?? 'bg-white'])->render(),
        };
    }
@endphp
@if ($root && ! $fallback)
    <{{ $area }}>{!! $html !!}</{{ $area }}>
@else
    {!! $html !!}
@endif
