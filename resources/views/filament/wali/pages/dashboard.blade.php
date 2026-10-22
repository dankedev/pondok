<x-filament-panels::page>
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
                    @include('filament.wali.partials.student-summary', [
                        'student' => $student,
                        'outstanding' => $outstanding[$student->id] ?? null,
                        'link' => \App\Filament\Wali\Pages\ShowStudent::getUrl(['student' => $student->id]),
                    ])
                </li>
            @endforeach
        </ul>
    @endif
</x-filament-panels::page>
