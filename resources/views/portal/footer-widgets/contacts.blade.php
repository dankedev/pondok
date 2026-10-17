{{-- Widget Kontak: semua data halaman Kontak & sosial (alamat, email, peta, kontak berlabel). --}}
@include('portal.partials.contact-list', ['school' => $school, 'phoneFormatter' => app(\App\Actions\Konten\FormatPhoneForDisplay::class), 'onDark' => true])
