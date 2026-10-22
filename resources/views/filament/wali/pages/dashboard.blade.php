<x-filament-panels::page>
    @php($students = $this->getStudents())

    @if ($students->isEmpty())
        <x-filament::empty-state
            :heading="__('pondok.portal_wali.no_students_heading')"
            :description="__('pondok.portal_wali.no_students')"
            icon="heroicon-o-user-group"
        />
    @else
        <ul class="grid gap-4" role="list">
            @foreach ($students as $student)
                <li>
                    <x-filament::section>
                        <p class="text-base font-semibold text-gray-950">{{ $student->name }}</p>
                        <p class="text-sm text-gray-600">{{ __('pondok.student.nis') }} {{ $student->nis }}</p>
                    </x-filament::section>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament-panels::page>
