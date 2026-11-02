{{-- Satu baris daftar (varian list dan magazine): judul bertautan dan keterangan singkat; warna mewarisi latar, tautan bergaris bawah. Butuh `$item` dan `$data`. --}}
@php
    $entryLink = 'text-lg font-semibold underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current rounded-lg';
@endphp
<li class="min-w-0 space-y-1 border-b border-current/20 pb-4">
    @if ($data['source'] === 'albums')
        <h3 class="break-words"><a href="{{ route('portal.albums.show', $item->slug) }}" class="{{ $entryLink }}">{{ $item->title }}</a></h3>
        <p class="text-sm">{{ trans_choice('pondok.portal.albums.photos_count', $item->photos_count, ['count' => $item->photos_count]) }}</p>
    @elseif ($data['source'] === 'videos')
        <h3 class="break-words"><a href="{{ \App\Actions\Konten\ParseYoutubeId::watchUrl($item->youtube_id) }}" rel="noopener" class="{{ $entryLink }}">{{ $item->title }}</a></h3>
        <p class="text-sm">{{ __('pondok.portal.videos.watch_on_youtube') }}</p>
    @else
        <h3 class="break-words"><a href="{{ route('portal.posts.show', $item->slug) }}" class="{{ $entryLink }}">{{ $item->title }}</a></h3>
        @if ($item->published_at)
            <p class="text-sm"><time datetime="{{ $item->published_at->toAtomString() }}">{{ $item->published_at->translatedFormat('j M Y') }}</time></p>
        @endif
    @endif
</li>
