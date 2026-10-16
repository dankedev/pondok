<p>{{ __('pondok.letter.bodies.syahadah', ['school' => $school['name'] ?? config('app.name')]) }}</p>

@include('dokumen.partials.letter-student')

<p>{{ __('pondok.letter.syahadah_for', ['purpose' => $letter->purpose]) }}</p>
