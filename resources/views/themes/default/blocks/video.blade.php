<section class="{{ $band() }} py-16 lg:py-24" aria-labelledby="{{ $headingId }}">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <div class="max-w-4xl space-y-6">
            <h2 id="{{ $headingId }}" class="{{ $sectionHeading }}">{{ $data['heading'] ?? $block['video']->title }}</h2>
            <x-portal.video-embed :youtube-id="$block['video']->youtube_id" :title="$block['video']->title" />
        </div>
    </div>
</section>
