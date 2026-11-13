{{--
    Renderer node tema portal (F102): satu node `theme.json` (rekursif lewat `container`/`columns`) menjadi HTML.
    Node beranda sudah dianotasi `ResolveHomeLayout` (blok terikat, kelas, heading); node header/footer apa adanya.
    Pemetaan komponen ke view ada di `portal.theme.node` (include berliteral, tidak pernah nama view dari JSON).

    Gagal render: bagian beranda dirender ulang tanpa gaya dan varian (komponen bawaan inti), header/footer diganti partial
    inti (`portal.partials.*`), keduanya dengan `Log::warning`. Node anak chrome melempar ulang supaya akar yang memutuskan cadangannya.
--}}
@props([
    'node',
    'area',
    'root' => false,
    'school' => null,
    'navigation' => null,
    'footer' => null,
    'compact' => false,
])

@php
    $context = ['node' => $node, 'area' => $area, 'root' => $root, 'school' => $school, 'navigation' => $navigation, 'footer' => $footer, 'compact' => $compact];
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
            default => view('portal.theme.node', [...$context, 'node' => \Illuminate\Support\Arr::except($node, ['style', 'variant', 'class'])])->render(),
        };
    }
@endphp
@if ($root && ! $fallback)
    <{{ $area }}>{!! $html !!}</{{ $area }}>
@else
    {!! $html !!}
@endif
