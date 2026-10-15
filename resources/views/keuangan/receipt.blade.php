<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('pondok.receipt.title') }} {{ $payment->receipt_no }}</title>
    <style>
        @page { size: A5; margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 16px; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; font-size: 12px; line-height: 1.5; color: CanvasText; background: Canvas; }
        .sheet { max-width: 148mm; margin: 0 auto; }
        .letterhead { display: flex; gap: 12px; align-items: center; padding-bottom: 8px; border-bottom: 2px solid currentColor; }
        .letterhead img { width: 48px; height: 48px; object-fit: contain; }
        .letterhead h1 { margin: 0; font-size: 16px; }
        .letterhead p { margin: 0; }
        .title { display: flex; justify-content: space-between; align-items: baseline; margin: 12px 0 4px; }
        .title h2 { margin: 0; font-size: 18px; letter-spacing: 0.08em; text-transform: uppercase; }
        .cancelled { margin: 8px 0; padding: 6px 8px; border: 2px solid currentColor; }
        .cancelled strong { font-size: 16px; letter-spacing: 0.1em; }
        .cancelled p { margin: 0; }
        dl { display: grid; grid-template-columns: 34% 1fr; gap: 2px 8px; margin: 8px 0; }
        dt { font-weight: 600; }
        dd { margin: 0; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; }
        th, td { padding: 4px 6px; border-bottom: 1px solid currentColor; text-align: left; vertical-align: top; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        tfoot td { font-weight: 700; border-bottom: 2px solid currentColor; }
        .words { font-style: italic; }
        .footer { display: flex; justify-content: space-between; gap: 12px; margin-top: 16px; }
        .signature { text-align: center; min-width: 40%; }
        .signature .space { height: 48px; }
        .printed { margin-top: 12px; font-size: 10px; }
        .actions { margin: 0 auto 12px; max-width: 148mm; text-align: right; }
        .actions button { font: inherit; padding: 6px 16px; border: 1px solid currentColor; border-radius: 6px; background: transparent; color: inherit; cursor: pointer; }
        @media print {
            body { padding: 0; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="actions">
        <button type="button" onclick="window.print()">{{ __('pondok.receipt.print_button') }}</button>
    </div>

    <main class="sheet">
        <header class="letterhead">
            @if ($school['logo'])
                <img src="{{ $school['logo'] }}" alt="">
            @endif
            <div>
                <h1>{{ $school['name'] }}</h1>
                @if (filled($school['address']))
                    <p>{{ $school['address'] }}</p>
                @endif
                @if (filled($school['phone']))
                    <p>{{ __('pondok.receipt.phone', ['phone' => $school['phone']]) }}</p>
                @endif
            </div>
        </header>

        <div class="title">
            <h2>{{ __('pondok.receipt.title') }}</h2>
            <span>{{ __('pondok.receipt.number') }}: <strong>{{ $payment->receipt_no }}</strong></span>
        </div>

        @if ($payment->status === \App\Models\Payment::STATUS_CANCELLED)
            <section class="cancelled">
                <strong>{{ __('pondok.receipt.cancelled') }}</strong>
                @if ($payment->cancelled_at)
                    <p>{{ __('pondok.receipt.cancelled_on', ['datetime' => $payment->cancelled_at->timezone('Asia/Jakarta')->locale('id')->translatedFormat('j M Y, H.i')]) }}</p>
                @endif
                <p>{{ __('pondok.receipt.cancelled_reason', ['reason' => $payment->cancel_reason]) }}</p>
            </section>
        @endif

        <dl>
            <dt>{{ __('pondok.receipt.date') }}</dt>
            <dd>{{ $payment->paid_on->locale('id')->translatedFormat('j M Y') }}</dd>
            <dt>{{ __('pondok.receipt.received_from') }}</dt>
            <dd>{{ $receivedFrom ?? '-' }}</dd>
            <dt>{{ __('pondok.receipt.student') }}</dt>
            <dd>{{ $student ? "{$student->nis} — {$student->name}" : '-' }}@if ($student?->unit) ({{ $student->unit->name }})@endif</dd>
            <dt>{{ __('pondok.receipt.invoice') }}</dt>
            <dd>{{ $invoice?->invoice_no ?? '-' }}</dd>
            <dt>{{ __('pondok.receipt.period') }}</dt>
            <dd>{{ $period ?? '-' }}</dd>
            <dt>{{ __('pondok.receipt.method') }}</dt>
            <dd>{{ $payment->methodLabel() }}</dd>
        </dl>

        <table>
            <thead>
                <tr>
                    <th scope="col">{{ __('pondok.receipt.for_payment') }}</th>
                    <th scope="col" class="num">{{ __('pondok.receipt.amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($payment->allocations as $allocation)
                    <tr>
                        <td>{{ $allocation->invoiceItem?->description }}</td>
                        <td class="num">{{ \App\Models\Payment::formatRupiah($allocation->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td>{{ __('pondok.receipt.total') }}</td>
                    <td class="num">{{ \App\Models\Payment::formatRupiah($payment->amount) }}</td>
                </tr>
            </tfoot>
        </table>

        <dl>
            <dt>{{ __('pondok.receipt.in_words') }}</dt>
            <dd class="words">{{ $payment->amountInWords() }}</dd>
            @if (filled($payment->note))
                <dt>{{ __('pondok.receipt.note') }}</dt>
                <dd>{{ $payment->note }}</dd>
            @endif
        </dl>

        <footer class="footer">
            <p class="printed">{{ __('pondok.receipt.printed_at', ['datetime' => $printedAt->locale('id')->translatedFormat('j M Y, H.i')]) }}</p>
            <div class="signature">
                <div>{{ __('pondok.receipt.officer') }}</div>
                <div class="space"></div>
                <div><strong>{{ $payment->receiver?->name ?? '-' }}</strong></div>
            </div>
        </footer>
    </main>
</body>
</html>
