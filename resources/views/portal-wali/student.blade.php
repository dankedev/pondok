{{-- Rincian santri portal wali (F079): ringkasan, tagihan (Keuangan aktif), dokumen (Dokumen aktif). --}}
@php($rupiah = fn (int $amount): string => \App\Models\Payment::formatRupiah($amount))

<x-layouts::public :title="$student->name" :noindex="true">
    <div class="flex flex-col gap-6">
        @include('portal-wali.partials.account-bar')

        <div class="flex flex-col gap-2">
            <x-portal.breadcrumb :items="[['label' => __('pondok.portal_wali.home'), 'url' => route('portal-wali.home')], ['label' => $student->name]]" />
            <h1 class="text-3xl font-bold tracking-tight text-brand-text sm:text-4xl">{{ $student->name }}</h1>
        </div>

        @include('portal-wali.partials.student-summary', ['student' => $student, 'showStatus' => true])

        @if ($invoices !== null)
            <x-ui.card :title="__('pondok.portal_wali.invoices')">
                @if ($invoices->isEmpty())
                    <x-ui.empty-state icon="heroicon-o-banknotes">{{ __('pondok.portal_wali.no_invoices') }}</x-ui.empty-state>
                @else
                    <ul class="flex flex-col divide-y divide-gray-200" role="list">
                        @foreach ($invoices as $invoice)
                            <li class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <a href="{{ route('portal-wali.invoices.show', $invoice->id) }}"
                                           class="rounded-lg font-semibold text-primary-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                                            {{ $invoice->period->translatedFormat('F Y') }}
                                        </a>
                                        <p class="text-sm text-gray-600">
                                            {{ \App\Models\Invoice::billingGroupLabel((string) $invoice->billing_group) }}
                                            · <span class="tabular-nums">{{ $invoice->invoice_no }}</span>
                                        </p>
                                    </div>
                                    <x-ui.badge :color="$invoice->statusColor()">{{ $invoice->statusLabel() }}</x-ui.badge>
                                </div>
                                <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
                                    <div>
                                        <dt class="text-gray-600">{{ __('pondok.invoice.total') }}</dt>
                                        <dd class="tabular-nums text-gray-900">{{ $rupiah($invoice->total) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-gray-600">{{ __('pondok.portal_wali.remaining') }}</dt>
                                        <dd class="font-semibold tabular-nums text-gray-900">{{ $rupiah($invoice->remainingAmount()) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-gray-600">{{ __('pondok.portal_wali.due_on') }}</dt>
                                        <dd class="text-gray-900">{{ $invoice->due_on?->translatedFormat('j M Y') ?? '-' }}</dd>
                                    </div>
                                </dl>
                            </li>
                        @endforeach
                    </ul>

                    {{ $invoices->links('portal.partials.pagination') }}
                @endif
            </x-ui.card>
        @endif

        @if ($documents !== null)
            <x-ui.card :title="__('pondok.portal_wali.documents')">
                @if ($documents->isEmpty())
                    <x-ui.empty-state icon="heroicon-o-document-text">{{ __('pondok.portal_wali.no_documents') }}</x-ui.empty-state>
                @else
                    <ul class="flex flex-col divide-y divide-gray-200" role="list">
                        @foreach ($documents as $document)
                            <li class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <p class="font-semibold text-gray-900">{{ $document->documentType?->name }}</p>
                                    <p class="text-sm text-gray-600">
                                        {{ __('pondok.portal_wali.expires_on') }}: {{ $document->expires_on?->translatedFormat('j M Y') ?? '-' }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <x-ui.badge :color="\App\Models\Document::STATUS_COLORS[$document->status] ?? 'gray'">{{ $document->statusLabel() }}</x-ui.badge>
                                    <x-ui.button variant="secondary" :href="route('portal-wali.documents.download', $document->id)">
                                        <x-heroicon-o-arrow-down-tray class="size-5" aria-hidden="true" />
                                        {{ __('pondok.portal_wali.download') }}
                                    </x-ui.button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        @endif
    </div>
</x-layouts::public>
