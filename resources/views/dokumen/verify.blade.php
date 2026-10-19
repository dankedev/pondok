@php
    $snapshot = $letter?->snapshot ?? [];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('pondok.letter_verification.title') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 16px; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; font-size: 16px; line-height: 1.5; color: CanvasText; background: Canvas; }
        main { max-width: 36rem; margin: 0 auto; }
        h1 { font-size: 1.375rem; margin: 8px 0 16px; }
        h2 { font-size: 1.125rem; margin: 0 0 12px; }
        .result { padding: 16px; border: 2px solid currentColor; border-radius: 8px; margin-bottom: 24px; }
        .status { display: inline-block; font-weight: 700; margin: 0 0 12px; }
        dl { display: grid; grid-template-columns: minmax(0, 40%) 1fr; gap: 6px 12px; margin: 0; }
        dt { font-weight: 600; }
        dd { margin: 0; overflow-wrap: anywhere; }
        .message { padding: 12px; border: 1px solid currentColor; border-radius: 8px; margin-bottom: 16px; }
        form { display: flex; flex-direction: column; gap: 8px; }
        label { font-weight: 600; }
        input { font: inherit; min-height: 44px; padding: 8px 12px; border: 1px solid currentColor; border-radius: 6px; background: Canvas; color: CanvasText; text-transform: uppercase; letter-spacing: 0.08em; }
        button { font: inherit; font-weight: 600; min-height: 44px; padding: 8px 16px; border: 1px solid currentColor; border-radius: 6px; background: CanvasText; color: Canvas; cursor: pointer; }
        input:focus-visible, button:focus-visible { outline: 3px solid Highlight; outline-offset: 2px; }
        .hint { font-size: 0.875rem; margin: 0; }
    </style>
</head>
<body>
    <main>
        <h1>{{ __('pondok.letter_verification.title') }}</h1>

        @if ($letter && $letter->isCancelled())
            <section class="result" aria-live="polite">
                <h2>{{ __('pondok.letter_verification.cancelled_title') }}</h2>
                <dl>
                    <dt>{{ __('pondok.letter_verification.school') }}</dt>
                    <dd>{{ $snapshot['school']['name'] ?? config('app.name') }}</dd>
                    <dt>{{ __('pondok.letter_verification.letter_no') }}</dt>
                    <dd>{{ $letter->letter_no }}</dd>
                    <dt>{{ __('pondok.letter_verification.type') }}</dt>
                    <dd>{{ $letter->typeLabel() }}</dd>
                    <dt>{{ __('pondok.letter_verification.cancelled_on') }}</dt>
                    <dd>{{ $letter->cancelled_at?->timezone('Asia/Jakarta')->locale('id')->translatedFormat('j M Y') }}</dd>
                </dl>
            </section>
        @elseif ($letter)
            <section class="result" aria-live="polite">
                <h2>{{ __('pondok.letter_verification.valid_title') }}</h2>
                <dl>
                    <dt>{{ __('pondok.letter_verification.school') }}</dt>
                    <dd>{{ $snapshot['school']['name'] ?? config('app.name') }}</dd>
                    <dt>{{ __('pondok.letter_verification.letter_no') }}</dt>
                    <dd>{{ $letter->letter_no }}</dd>
                    <dt>{{ __('pondok.letter_verification.type') }}</dt>
                    <dd>{{ $letter->typeLabel() }}</dd>
                    <dt>{{ __('pondok.letter_verification.issued_on') }}</dt>
                    <dd>{{ $letter->issued_on->locale('id')->translatedFormat('j M Y') }}</dd>
                    <dt>{{ __('pondok.letter_verification.student') }}</dt>
                    <dd>{{ $letter->maskedStudentName() }}</dd>
                    <dt>{{ __('pondok.letter_verification.signer') }}</dt>
                    <dd>
                        @foreach ($letter->signerList() as $signer)
                            @php($line = collect([$signer['name'] ?? null, $signer['position'] ?? null])->filter()->implode(', '))
                            <span style="display: block">{{ filled($signer['label'] ?? null) ? $signer['label'].': '.$line : $line }}</span>
                        @endforeach
                    </dd>
                    @if ($letter->type === \App\Models\IssuedLetter::TYPE_STUDENT_CARD)
                        <dt>{{ __('pondok.letter_verification.student_status') }}</dt>
                        <dd>{{ $letter->student && ! $letter->student->trashed() && $letter->student->isActive() ? __('pondok.letter_verification.student_active') : __('pondok.letter_verification.student_inactive') }}</dd>
                    @endif
                </dl>
            </section>
        @endif

        @if ($notFound)
            <p class="message" role="alert">{{ __('pondok.letter_verification.not_found') }}</p>
        @elseif ($error)
            <p class="message" role="alert">{{ $error }}</p>
        @endif

        <h2>{{ __('pondok.letter_verification.form_title') }}</h2>
        <form method="get" action="{{ route('dokumen.letters.verify-form', absolute: false) }}">
            <label for="kode">{{ __('pondok.letter_verification.code_label') }}</label>
            <input id="kode" name="kode" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="64" aria-describedby="kode-hint" required>
            <p class="hint" id="kode-hint">{{ __('pondok.letter_verification.code_hint') }}</p>
            <button type="submit">{{ __('pondok.letter_verification.submit') }}</button>
        </form>
    </main>
</body>
</html>
