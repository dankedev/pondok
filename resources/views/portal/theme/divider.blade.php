{{-- Komponen `divider`: hiasan (garis dan belah ketupat berlatar aksen, bukan teks sehingga kontras tak bergantung tone); aria-hidden. Satu bentuk. --}}
<div{{ $pt('divider') }} aria-hidden="true" class="{{ $area === 'home' ? $node['class'] : \App\Actions\Konten\ThemeStyleMap::classes($node['style'] ?? []) }} flex items-center justify-center gap-3 py-2">
    <span{{ $pt(part: 'line') }} class="h-px w-16 bg-accent-500"></span>
    <span{{ $pt(part: 'diamond') }} class="size-2 rotate-45 bg-accent-500"></span>
    <span{{ $pt(part: 'line') }} class="h-px w-16 bg-accent-500"></span>
</div>
