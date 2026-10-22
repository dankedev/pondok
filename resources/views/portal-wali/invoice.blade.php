{{-- Rincian tagihan portal wali (F079): ringkasan, item, dan pembayaran diterima. --}}
@php($rupiah = fn (int $amount): string => \App\Models\Payment::formatRupiah($amount))

<x-layouts::public :title="__('pondok.portal_wali.invoice_title', ['number' => $invoice->invoice_no])" :noindex="true">
    <div class="flex flex-col gap-6">
        @include('portal-wali.partials.account-bar')

        <div class="flex flex-col gap-2">
            <x-portal.breadcrumb :items="[
                ['label' => __('pondok.portal_wali.home'), 'url' => route('portal-wali.home')],
                ['label' => $invoice->student->name, 'url' => route('portal-wali.students.show', $invoice->student_id)],
                ['label' => $invoice->invoice_no],
            ]" />
            <h1 class="text-3xl font-bold tracking-tight text-brand-text sm:text-4xl">{{ __('pondok.portal_wali.invoice_title', ['number' => $invoice->invoice_no]) }}</h1>
        </div>

        <x-ui.card>
            <div class="flex flex-col gap-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-gray-900">{{ $invoice->period->translatedFormat('F Y') }}</h2>
                        <p class="text-sm text-gray-600">{{ \App\Models\Invoice::billingGroupLabel((string) $invoice->billing_group) }}</p>
                    </div>
                    <x-ui.badge :color="$invoice->statusColor()">{{ $invoice->statusLabel() }}</x-ui.badge>
                </div>
                <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-gray-600">{{ __('pondok.invoice.total') }}</dt>
                        <dd class="font-medium tabular-nums text-gray-900">{{ $rupiah($invoice->total) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-600">{{ __('pondok.portal_wali.remaining') }}</dt>
                        <dd class="text-base font-semibold tabular-nums text-gray-900">{{ $rupiah($invoice->remainingAmount()) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-600">{{ __('pondok.portal_wali.due_on') }}</dt>
                        <dd class="font-medium text-gray-900">{{ $invoice->due_on?->translatedFormat('j M Y') ?? '-' }}</dd>
                    </div>
                </dl>
            </div>
        </x-ui.card>

        <x-ui.card :title="__('pondok.portal_wali.items')">
            <ul class="flex flex-col divide-y divide-gray-200" role="list">
                @foreach ($invoice->items as $item)
                    <li class="flex flex-col gap-2 py-4 first:pt-0 last:pb-0">
                        <p class="font-semibold text-gray-900">{{ $item->description ?: $item->feeType?->name }}</p>
                        <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
                            <div>
                                <dt class="text-gray-600">{{ __('pondok.portal_wali.base_amount') }}</dt>
                                <dd class="whitespace-nowrap tabular-nums text-gray-900">{{ $rupiah($item->base_amount) }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-600">{{ __('pondok.portal_wali.discount_amount') }}</dt>
                                <dd class="whitespace-nowrap tabular-nums text-gray-900">{{ $rupiah($item->discount_amount) }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-600">{{ __('pondok.portal_wali.amount') }}</dt>
                                <dd class="whitespace-nowrap font-semibold tabular-nums text-gray-900">{{ $rupiah($item->amount) }}</dd>
                            </div>
                        </dl>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        <x-ui.card :title="__('pondok.portal_wali.payments')">
            @if ($payments->isEmpty())
                <x-ui.empty-state icon="heroicon-o-banknotes">{{ __('pondok.portal_wali.no_payments') }}</x-ui.empty-state>
            @else
                <ul class="flex flex-col divide-y divide-gray-200" role="list">
                    @foreach ($payments as $payment)
                        <li class="flex items-center justify-between gap-3 py-3 text-sm first:pt-0 last:pb-0">
                            <div>
                                <p class="font-semibold text-gray-900">{{ $payment->paid_on->translatedFormat('j M Y') }}</p>
                                <p class="text-gray-600">{{ $payment->methodLabel() }}</p>
                            </div>
                            <p class="font-semibold tabular-nums text-gray-900">{{ $rupiah($payment->amount) }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>
</x-layouts::public>
