<x-filament-panels::page>
    @include('filament.wali.partials.student-summary', ['student' => $this->student, 'outstanding' => null, 'showStatus' => true])

    @if ($showInvoices)
        {{ $this->table }}
    @endif

    @if ($documents !== null)
        <x-filament::section :heading="__('pondok.portal_wali.documents')">
            @if ($documents->isEmpty())
                <x-filament::empty-state
                    :heading="__('pondok.portal_wali.no_documents')"
                    icon="heroicon-o-document-text"
                    :contained="false"
                    compact
                />
            @else
                <ul class="divide-y divide-gray-100" role="list">
                    @foreach ($documents as $document)
                        <li class="flex flex-col gap-3 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-950">{{ $document->documentType?->name }}</p>
                                <p class="text-sm text-gray-600">
                                    {{ __('pondok.portal_wali.expires_on') }}:
                                    {{ $document->expires_on?->translatedFormat('j M Y') ?? '-' }}
                                </p>
                            </div>
                            <div class="flex items-center gap-3">
                                <x-filament::badge :color="\App\Models\Document::STATUS_COLORS[$document->status] ?? 'gray'">
                                    {{ $document->statusLabel() }}
                                </x-filament::badge>
                                <x-filament::button
                                    tag="a"
                                    :href="route('filament.wali.dokumen.download', $document->id)"
                                    icon="heroicon-o-arrow-down-tray"
                                    color="gray"
                                    size="sm"
                                >
                                    {{ __('pondok.portal_wali.download') }}
                                </x-filament::button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
