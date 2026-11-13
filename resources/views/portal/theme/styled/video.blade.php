{{-- Video: judul dan pemutar tanpa JavaScript (`x-portal.video-embed`); kelas akar dari `ResolveHomeLayout`. --}}
<section{{ $pt('video') }} class="{{ $sectionClass }} flex flex-col" aria-labelledby="{{ $headingId }}">
    <div class="max-w-4xl space-y-6">
        <h2{{ $pt(part: 'heading') }} id="{{ $headingId }}" class="text-3xl font-bold tracking-tight lg:text-4xl">{{ $data['heading'] ?? $block['video']->title }}</h2>
        <x-portal.video-embed :youtube-id="$block['video']->youtube_id" :title="$block['video']->title" :data-pt-part="$ptActive ? 'player' : null" />
    </div>
</section>
