@php
    $student = $snapshot['student'] ?? [];
    $signers = $letter->signerList();
    $school = $snapshot['school'] ?? [];
    $birthDate = filled($student['birth_date'] ?? null) ? \Carbon\CarbonImmutable::parse($student['birth_date'])->locale('id')->translatedFormat('j F Y') : null;
    $birth = collect([$student['birth_place'] ?? null, $birthDate])->filter(fn ($value) => filled($value))->implode(', ');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __("pondok.letter.titles.{$letter->type}") }} {{ $letter->letter_no }}</title>
    <style>
        @page { size: A4; margin: 20mm; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 16px; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; font-size: 13px; line-height: 1.6; color: CanvasText; background: Canvas; }
        .sheet { max-width: 170mm; margin: 0 auto; }
        .letterhead { display: flex; gap: 12px; align-items: center; padding-bottom: 8px; border-bottom: 3px double currentColor; }
        .letterhead img { width: 64px; height: 64px; object-fit: contain; }
        .letterhead h1 { margin: 0; font-size: 18px; }
        .letterhead p { margin: 0; }
        .title { margin: 20px 0 16px; text-align: center; }
        .title h2 { margin: 0; font-size: 16px; letter-spacing: 0.06em; text-transform: uppercase; text-decoration: underline; }
        .title p { margin: 2px 0 0; font-variant-numeric: tabular-nums; }
        .cancelled { margin: 12px 0; padding: 8px 12px; border: 2px solid currentColor; }
        .cancelled strong { font-size: 18px; letter-spacing: 0.12em; }
        .cancelled p { margin: 0; }
        dl { display: grid; grid-template-columns: 38% 1fr; gap: 2px 12px; margin: 12px 0 12px 24px; }
        dt { font-weight: 600; }
        dd { margin: 0; }
        .closing { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 16px; margin-top: 24px; }
        .verify { display: flex; gap: 12px; align-items: center; max-width: 60%; font-size: 11px; }
        .verify svg { width: 28mm; height: 28mm; flex: none; }
        .verify p { margin: 0; overflow-wrap: anywhere; }
        .code { font-variant-numeric: tabular-nums; letter-spacing: 0.08em; font-weight: 600; }
        .signature { text-align: center; min-width: 35%; flex: 1 1 35%; }
        .signature .space { height: 64px; }
        .signature p { margin: 0; }
        .signature-row { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 12px 24px; }
        .signature-col { text-align: center; min-width: 40%; flex: 1 1 40%; max-width: 100%; }
        .signature-col p { margin: 0; }
        .signature-col .space { height: 64px; }
        .footer { margin-top: 24px; padding-top: 6px; border-top: 1px solid currentColor; font-size: 10px; display: flex; justify-content: space-between; gap: 12px; }
        .actions { margin: 0 auto 12px; max-width: 170mm; text-align: right; }
        .actions button { font: inherit; min-height: 44px; padding: 6px 16px; border: 1px solid currentColor; border-radius: 6px; background: transparent; color: inherit; cursor: pointer; }
        @media print {
            body { padding: 0; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="actions">
        <button type="button" onclick="window.print()">{{ __('pondok.letter.print_button') }}</button>
    </div>

    <main class="sheet">
        <header class="letterhead">
            @if ($logo)
                <img src="{{ $logo }}" alt="">
            @endif
            <div>
                <h1>{{ $school['name'] ?? config('app.name') }}</h1>
                @if (filled($school['address'] ?? null))
                    <p>{{ $school['address'] }}</p>
                @endif
                @if (filled($school['phone'] ?? null))
                    <p>{{ __('pondok.letter.phone', ['phone' => $school['phone']]) }}</p>
                @endif
            </div>
        </header>

        <div class="title">
            <h2>{{ __("pondok.letter.titles.{$letter->type}") }}</h2>
            <p>{{ __('pondok.letter.number', ['number' => $letter->letter_no]) }}</p>
        </div>

        @include('dokumen.partials.letter-cancelled')

        @include("dokumen.letters.{$letter->type}")

        <section class="closing">
            <div class="verify">
                @if ($qr)
                    {!! $qr !!}
                @endif
                <div>
                    <p>{{ __('pondok.letter.scan_hint') }}</p>
                    <p>{{ $letter->verificationUrl() }}</p>
                    <p class="code">{{ $letter->formattedVerificationCode() }}</p>
                </div>
            </div>
            <div class="signature">
                <p>{{ __('pondok.letter.place_date', ['date' => $letter->issued_on->locale('id')->translatedFormat('j F Y')]) }}</p>
                <div class="signature-row">
                    @foreach ($signers as $signer)
                        <div class="signature-col">
                            <p>{{ filled($signer['label'] ?? null) ? $signer['label'] : ($signer['position'] ?? '') }}</p>
                            <div class="space" aria-label="{{ __('pondok.letter.signature_space') }}"></div>
                            <p><strong>{{ $signer['name'] ?? '' }}</strong></p>
                            @if (filled($signer['label'] ?? null) && filled($signer['position'] ?? null))
                                <p>{{ $signer['position'] }}</p>
                            @endif
                            @if (filled($signer['nip'] ?? null))
                                <p>{{ __('pondok.letter.nip', ['nip' => $signer['nip']]) }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <footer class="footer">
            <span>{{ __('pondok.letter.issued_by', ['name' => $letter->issuer?->name]) }}</span>
            <span>{{ __('pondok.letter.printed_at', ['datetime' => $printedAt->locale('id')->translatedFormat('j M Y, H.i')]) }}</span>
        </footer>
    </main>
</body>
</html>
