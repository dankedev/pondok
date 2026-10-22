@php($invoice = $this->invoice)

<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $invoice->period->translatedFormat('F Y') }}</x-slot>
        <x-slot name="description">{{ \App\Models\Invoice::billingGroupLabel((string) $invoice->billing_group) }}</x-slot>
        <x-slot name="afterHeader">
            <x-filament::badge :color="$invoice->statusColor()">{{ $invoice->statusLabel() }}</x-filament::badge>
        </x-slot>

        <dl class="grid gap-3 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-gray-600">{{ __('pondok.invoice.total') }}</dt>
                <dd class="font-medium tabular-nums text-gray-950">{{ \App\Models\Payment::formatRupiah($invoice->total) }}</dd>
            </div>
            <div>
                <dt class="text-gray-600">{{ __('pondok.portal_wali.remaining') }}</dt>
                <dd class="text-base font-semibold tabular-nums text-gray-950">{{ \App\Models\Payment::formatRupiah($invoice->remainingAmount()) }}</dd>
            </div>
            <div>
                <dt class="text-gray-600">{{ __('pondok.portal_wali.due_on') }}</dt>
                <dd class="font-medium text-gray-950">{{ $invoice->due_on?->translatedFormat('j M Y') ?? '-' }}</dd>
            </div>
        </dl>
    </x-filament::section>

    <x-filament::section :heading="__('pondok.portal_wali.items')">
        <ul class="divide-y divide-gray-100" role="list">
            @foreach ($invoice->items as $item)
                <li class="py-3 first:pt-0 last:pb-0">
                    <p class="font-medium text-gray-950">{{ $item->description ?: $item->feeType?->name }}</p>
                    <dl class="mt-1 grid grid-cols-3 gap-2 text-sm">
                        <div>
                            <dt class="text-gray-600">{{ __('pondok.portal_wali.base_amount') }}</dt>
                            <dd class="tabular-nums text-gray-950">{{ \App\Models\Payment::formatRupiah($item->base_amount) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-600">{{ __('pondok.portal_wali.discount_amount') }}</dt>
                            <dd class="tabular-nums text-gray-950">{{ \App\Models\Payment::formatRupiah($item->discount_amount) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-600">{{ __('pondok.portal_wali.amount') }}</dt>
                            <dd class="font-medium tabular-nums text-gray-950">{{ \App\Models\Payment::formatRupiah($item->amount) }}</dd>
                        </div>
                    </dl>
                </li>
            @endforeach
        </ul>
    </x-filament::section>

    <x-filament::section :heading="__('pondok.portal_wali.payments')">
        @if ($payments->isEmpty())
            <x-filament::empty-state
                :heading="__('pondok.portal_wali.no_payments')"
                icon="heroicon-o-banknotes"
                :contained="false"
                compact
            />
        @else
            <ul class="divide-y divide-gray-100" role="list">
                @foreach ($payments as $payment)
                    <li class="flex items-center justify-between gap-3 py-3 text-sm first:pt-0 last:pb-0">
                        <div>
                            <p class="font-medium text-gray-950">{{ $payment->paid_on->translatedFormat('j M Y') }}</p>
                            <p class="text-gray-600">{{ $payment->methodLabel() }}</p>
                        </div>
                        <p class="font-medium tabular-nums text-gray-950">{{ \App\Models\Payment::formatRupiah($payment->amount) }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-panels::page>
