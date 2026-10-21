@php
    $student = $snapshot['student'] ?? [];
    $school = $snapshot['school'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('pondok.letter.card_title') }} {{ $letter->letter_no }}</title>
    <style>
        @page { size: 85.6mm 54mm; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 16px; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; font-size: 8pt; line-height: 1.3; color: CanvasText; background: Canvas; }
        .card { width: 85.6mm; height: 54mm; margin: 0 auto; padding: 3mm; border: 1px solid currentColor; border-radius: 3mm; display: flex; flex-direction: column; gap: 1.5mm; overflow: hidden; }
        .card header { display: flex; gap: 2mm; align-items: center; border-bottom: 1px solid currentColor; padding-bottom: 1mm; }
        .card header img { width: 8mm; height: 8mm; object-fit: contain; }
        .card h1 { margin: 0; font-size: 8pt; }
        .card h2 { margin: 0; font-size: 9pt; letter-spacing: 0.1em; }
        .body { display: flex; gap: 2mm; flex: 1; }
        .photo { width: 20mm; height: 26mm; border: 1px dashed currentColor; display: flex; align-items: center; justify-content: center; text-align: center; font-size: 6pt; flex: none; }
        .details { flex: 1; min-width: 0; }
        .details p { margin: 0; overflow-wrap: anywhere; }
        .name { font-weight: 700; font-size: 9pt; }
        .number { font-size: 6pt; font-variant-numeric: tabular-nums; }
        .qr svg { width: 16mm; height: 16mm; }
        .qr { flex: none; text-align: center; font-size: 5pt; }
        .cancelled { padding: 1mm; border: 2px solid currentColor; text-align: center; }
        .cancelled strong { letter-spacing: 0.1em; }
        .cancelled p { margin: 0; font-size: 6pt; }
        .redacted { margin: 0; padding: 1mm; border: 1px dashed currentColor; text-align: center; font-size: 6pt; }
        .actions { margin: 0 auto 12px; max-width: 85.6mm; text-align: right; }
        .actions button { font: inherit; font-size: 12px; min-height: 44px; padding: 6px 16px; border: 1px solid currentColor; border-radius: 6px; background: transparent; color: inherit; cursor: pointer; }
        @media print {
            body { padding: 0; }
            .actions { display: none; }
            .card { border-radius: 0; }
        }
    </style>
</head>
<body>
    <div class="actions">
        <button type="button" onclick="window.print()">{{ __('pondok.letter.print_button') }}</button>
    </div>

    <main class="card">
        <header>
            @if ($logo)
                <img src="{{ $logo }}" alt="">
            @endif
            <div>
                <h1>{{ $school['name'] ?? config('app.name') }}</h1>
                <h2>{{ __('pondok.letter.card_title') }}</h2>
            </div>
        </header>

        @if ($letter->isCancelled())
            <section class="cancelled">
                <strong>{{ __('pondok.letter.cancelled') }}</strong>
                <p>{{ __('pondok.letter.cancelled_reason', ['reason' => $letter->cancel_reason]) }}</p>
            </section>
        @endif

        @if ($redactedAt)
            <p class="redacted">{{ __('pondok.letter.redacted_note') }}</p>
        @endif

        <div class="body">
            <div class="photo">{{ __('pondok.letter.photo_frame') }}</div>
            <div class="details">
                <p class="name">{{ $student['name'] ?? '' }}</p>
                <p>{{ __('pondok.letter.nis') }} {{ $student['nis'] ?? '' }}</p>
                @if (filled($student['unit_name'] ?? null))
                    <p>{{ $student['unit_name'] }}</p>
                @endif
                <p class="number">{{ $letter->letter_no }}</p>
                <p>{{ $letter->signerNames() }}</p>
            </div>
            <div class="qr">
                @if ($qr)
                    {!! $qr !!}
                @endif
                <p>{{ $letter->formattedVerificationCode() }}</p>
            </div>
        </div>
    </main>
</body>
</html>
