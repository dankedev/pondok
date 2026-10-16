<dl>
    <dt>{{ __('pondok.letter.student_name') }}</dt>
    <dd><strong>{{ $student['name'] ?? '' }}</strong></dd>
    <dt>{{ __('pondok.letter.nis') }}</dt>
    <dd>{{ $student['nis'] ?? '' }}</dd>
    @if (filled($student['nisn'] ?? null))
        <dt>{{ __('pondok.letter.nisn') }}</dt>
        <dd>{{ $student['nisn'] }}</dd>
    @endif
    @if ($birth !== '')
        <dt>{{ __('pondok.letter.birth') }}</dt>
        <dd>{{ $birth }}</dd>
    @endif
    @if (filled($student['unit_name'] ?? null))
        <dt>{{ __('pondok.letter.unit') }}</dt>
        <dd>{{ $student['unit_name'] }}</dd>
    @endif
    @if (filled($student['classroom_name'] ?? null))
        <dt>{{ __('pondok.letter.classroom') }}</dt>
        <dd>{{ $student['classroom_name'] }}</dd>
        {{-- Tahun ajaran hanya bermakna bersama rombelnya. --}}
        @if (filled($student['academic_year_name'] ?? null))
            <dt>{{ __('pondok.letter.academic_year') }}</dt>
            <dd>{{ $student['academic_year_name'] }}</dd>
        @endif
    @endif
</dl>
