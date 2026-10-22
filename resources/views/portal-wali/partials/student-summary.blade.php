{{--
    Ringkasan santri portal wali (beranda dengan tautan, halaman santri dengan status).
    $student dari `User::portalStudentsWithSummary()`; kamar hanya bila relasinya dimuat (Asrama aktif).
--}}
@php($room = $student->relationLoaded('activeRoomAssignment') ? $student->activeRoomAssignment?->room : null)

<x-ui.card>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-lg font-semibold text-gray-900">{{ $student->name }}</h2>
                <p class="text-sm tabular-nums text-gray-600">{{ __('pondok.portal_wali.nis', ['nis' => $student->nis]) }}</p>
            </div>
            @if ($showStatus ?? false)
                <x-ui.badge :color="$student->isActive() ? 'success' : 'gray'">{{ __("pondok.student.statuses.{$student->status}") }}</x-ui.badge>
            @endif
        </div>

        <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-gray-600">{{ __('pondok.portal_wali.unit') }}</dt>
                <dd class="font-medium text-gray-900">{{ $student->unit?->name ?? '-' }}</dd>
            </div>
            <div>
                <dt class="text-gray-600">{{ __('pondok.portal_wali.classroom') }}</dt>
                <dd class="font-medium text-gray-900">{{ $student->currentEnrollment?->classroom?->name ?? '-' }}</dd>
            </div>
            @if ($student->relationLoaded('activeRoomAssignment'))
                <div>
                    <dt class="text-gray-600">{{ __('pondok.portal_wali.room') }}</dt>
                    <dd class="font-medium text-gray-900">{{ $room ? "{$room->dormitory->name} — {$room->name}" : __('pondok.portal_wali.non_resident') }}</dd>
                </div>
            @endif
            @if (($outstanding ?? null) !== null)
                <div>
                    <dt class="text-gray-600">{{ __('pondok.portal_wali.outstanding') }}</dt>
                    <dd class="font-semibold tabular-nums text-gray-900">{{ \App\Models\Payment::formatRupiah($outstanding) }}</dd>
                </div>
            @endif
        </dl>

        @if ($link ?? null)
            <div>
                <x-ui.button variant="secondary" :href="$link">
                    {{ __('pondok.portal_wali.view_student') }}<span class="sr-only"> {{ $student->name }}</span>
                    <x-heroicon-o-arrow-right class="size-5" aria-hidden="true" />
                </x-ui.button>
            </div>
        @endif
    </div>
</x-ui.card>
