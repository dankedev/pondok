@if ($letter->isCancelled())
    <section class="cancelled">
        <strong>{{ __('pondok.letter.cancelled') }}</strong>
        @if ($letter->cancelled_at)
            <p>{{ __('pondok.letter.cancelled_on', ['datetime' => $letter->cancelled_at->timezone('Asia/Jakarta')->locale('id')->translatedFormat('j F Y, H.i')]) }}</p>
        @endif
        <p>{{ __('pondok.letter.cancelled_reason', ['reason' => $letter->cancel_reason]) }}</p>
    </section>
@endif
