{{-- Rapor diniyah A4 (F027), dipakai cetak admin dan portal wali. HTML mandiri tanpa aset eksternal, aman hitam-putih. --}}
@php
    $student = $card['student'];
    $number = fn (float $value): string => number_format($value, 1, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('pondok.diniyah.report.title') }} {{ $student->name }}</title>
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
        dl { display: grid; grid-template-columns: 34% 1fr; gap: 2px 12px; margin: 12px 0; }
        dt { font-weight: 600; }
        dd { margin: 0; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        th, td { padding: 4px 8px; border: 1px solid currentColor; text-align: left; vertical-align: top; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        tfoot td { font-weight: 700; }
        .below { display: block; font-size: 11px; }
        .note { margin: 12px 0; padding: 8px 12px; border: 1px solid currentColor; white-space: pre-line; overflow-wrap: anywhere; }
        .note h3 { margin: 0 0 4px; font-size: 13px; }
        .note p { margin: 0; }
        .signature { margin: 24px 0 0 auto; width: 45%; min-width: 200px; text-align: center; }
        .signature .space { height: 64px; }
        .signature p { margin: 0; }
        .footer { margin-top: 24px; padding-top: 6px; border-top: 1px solid currentColor; font-size: 10px; text-align: right; }
        .actions { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; margin: 0 auto 12px; max-width: 170mm; }
        .actions a, .actions button { display: inline-flex; align-items: center; font: inherit; min-height: 44px; padding: 6px 16px; border: 1px solid currentColor; border-radius: 6px; background: transparent; color: inherit; cursor: pointer; text-decoration: none; }
        @media print {
            body { padding: 0; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="actions">
        <a href="{{ $backUrl }}">{{ __('pondok.diniyah.report.back') }}</a>
        <button type="button" onclick="window.print()">{{ __('pondok.diniyah.report.print_button') }}</button>
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
                    <p>{{ __('pondok.letter.phone', ['phone' => $school['phone']]) }}</p>
                @endif
            </div>
        </header>

        <div class="title">
            <h2>{{ __('pondok.diniyah.report.title') }}</h2>
        </div>

        <dl>
            <dt>{{ __('pondok.diniyah.report.name') }}</dt>
            <dd>{{ $student->name }}</dd>
            <dt>{{ __('pondok.diniyah.report.nis') }}</dt>
            <dd class="num" style="text-align: left">{{ $student->nis }}</dd>
            <dt>{{ __('pondok.diniyah.report.classroom') }}</dt>
            <dd>{{ $card['classroom']->name }}</dd>
            <dt>{{ __('pondok.diniyah.report.year') }}</dt>
            <dd>{{ $card['year']->name }}</dd>
            <dt>{{ __('pondok.diniyah.report.semester') }}</dt>
            <dd>{{ $card['semester'] }}</dd>
        </dl>

        <table>
            <thead>
                <tr>
                    <th scope="col">{{ __('pondok.diniyah.report.subject') }}</th>
                    <th scope="col" class="num">{{ __('pondok.diniyah.report.passing_score') }}</th>
                    <th scope="col" class="num">{{ __('pondok.diniyah.report.score') }}</th>
                    <th scope="col">{{ __('pondok.diniyah.report.predicate') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($card['rows'] as $row)
                    <tr>
                        <td>{{ $row['subject'] }}</td>
                        <td class="num">{{ $row['passing_score'] ?? '-' }}</td>
                        <td class="num">
                            {{ $row['score'] ?? '-' }}
                            @if ($row['below_passing'])
                                <strong class="below">{{ __('pondok.diniyah.report.below_passing') }}</strong>
                            @endif
                        </td>
                        <td>{{ $row['predicate']?->label() ?? '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2">{{ __('pondok.diniyah.report.average') }}</td>
                    <td class="num">{{ $card['average'] === null ? '-' : $number($card['average']) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        <section class="note">
            <h3>{{ __('pondok.diniyah.report.note') }}</h3>
            <p>{{ filled($card['note']) ? $card['note'] : __('pondok.diniyah.report.no_note') }}</p>
        </section>

        <section class="signature">
            <p>{{ __('pondok.diniyah.report.homeroom') }}</p>
            <div class="space"></div>
            <p><strong>{{ $card['homeroom']?->name ?? '-' }}</strong></p>
        </section>

        <footer class="footer">
            {{ __('pondok.diniyah.report.printed_at', ['datetime' => $printedAt->locale('id')->translatedFormat('j M Y, H.i')]) }}
        </footer>
    </main>
</body>
</html>
