<p>{{ __('pondok.letter.opening') }}</p>

@include('dokumen.partials.letter-student')

<p>{{ __('pondok.letter.bodies.rekomendasi', ['school' => $school['name'] ?? config('app.name')]) }}</p>

<p>{{ __('pondok.letter.purpose', ['purpose' => $letter->purpose]) }}</p>
