{{-- Beranda portal wali (F079): satu kartu per santri milik wali login. --}}
<x-layouts::public :title="__('pondok.portal_wali.home')" :noindex="true">
    <div class="flex flex-col gap-6">
        @include('portal-wali.partials.account-bar')

        <h1 class="text-3xl font-bold tracking-tight text-brand-text sm:text-4xl">{{ __('pondok.portal_wali.home') }}</h1>

        @if ($students->isEmpty())
            <x-ui.empty-state icon="heroicon-o-user-group">{{ __('pondok.portal_wali.no_students') }}</x-ui.empty-state>
        @else
            <ul class="flex flex-col gap-4" role="list">
                @foreach ($students as $student)
                    <li>
                        @include('portal-wali.partials.student-summary', [
                            'student' => $student,
                            'outstanding' => $outstanding[$student->id] ?? null,
                            'link' => route('portal-wali.students.show', $student->id),
                        ])
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-layouts::public>
