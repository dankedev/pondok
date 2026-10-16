{{-- Isi draf berita dari prestasi (F015). Semua nilai di-escape; SavePost menyanitasi ulang. --}}
<p>{{ $achievement->organizer === null
    ? __('pondok.achievement.post_intro', ['event' => $achievement->event_name, 'level' => $level, 'date' => $date])
    : __('pondok.achievement.post_intro_organizer', ['event' => $achievement->event_name, 'organizer' => $achievement->organizer, 'level' => $level, 'date' => $date]) }}</p>
<p>{{ __('pondok.achievement.post_participants') }}</p>
<ul>
@foreach ($participants as $student)
<li>{{ $student->pivot->role === null
    ? __('pondok.achievement.post_participant', ['name' => $student->name, 'rank' => $student->pivot->rank])
    : __('pondok.achievement.post_participant_role', ['name' => $student->name, 'rank' => $student->pivot->rank, 'role' => $student->pivot->role]) }}</li>
@endforeach
</ul>
