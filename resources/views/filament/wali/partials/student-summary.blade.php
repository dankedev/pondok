{{-- Ringkasan santri portal wali: dipakai di Beranda (dengan tautan) dan halaman Santri. --}}
@php($room = $student->relationLoaded('activeRoomAssignment') ? $student->activeRoomAssignment?->room : null)

<x-filament::section>
    <x-slot name="heading">{{ $student->name }}</x-slot>
    <x-slot name="description">{{ __('pondok.portal_wali.nis', ['nis' => $student->nis]) }}</x-slot>

    @if ($showStatus ?? false)
        <x-slot name="afterHeader">
            <x-filament::badge :color="$student->isActive() ? 'success' : 'gray'">
                {{ __("pondok.student.statuses.{$student->status}") }}
            </x-filament::badge>
        </x-slot>
    @endif

    <dl class="grid gap-3 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-gray-600">{{ __('pondok.portal_wali.unit') }}</dt>
            <dd class="font-medium text-gray-950">{{ $student->unit?->name ?? '-' }}</dd>
        </div>
        <div>
            <dt class="text-gray-600">{{ __('pondok.portal_wali.classroom') }}</dt>
            <dd class="font-medium text-gray-950">{{ $student->currentEnrollment?->classroom?->name ?? '-' }}</dd>
        </div>
        @if ($student->relationLoaded('activeRoomAssignment'))
            <div>
                <dt class="text-gray-600">{{ __('pondok.portal_wali.room') }}</dt>
                <dd class="font-medium text-gray-950">
                    {{ $room ? "{$room->dormitory->name} — {$room->name}" : __('pondok.portal_wali.non_resident') }}
                </dd>
            </div>
        @endif
        @if ($outstanding !== null)
            <div>
                <dt class="text-gray-600">{{ __('pondok.portal_wali.outstanding') }}</dt>
                <dd class="font-medium tabular-nums text-gray-950">{{ \App\Models\Payment::formatRupiah($outstanding) }}</dd>
            </div>
        @endif
    </dl>

    @if ($link ?? null)
        <x-slot name="footer">
            <x-filament::button :href="$link" tag="a" icon="heroicon-o-arrow-right" icon-position="after" color="gray">
                {{ __('pondok.portal_wali.view_student') }}
            </x-filament::button>
        </x-slot>
    @endif
</x-filament::section>
