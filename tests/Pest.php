<?php

use App\Models\AcademicYear;
use App\Models\Dormitory;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Helper modul Asrama (F003)
|--------------------------------------------------------------------------
|
| Berawalan `asrama` agar tidak bentrok dengan helper global di file test lain.
|
*/

function asramaActiveYear(): AcademicYear
{
    return AcademicYear::factory()->active()->create();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function asramaDormitory(string $gender = 'L', array $attributes = []): Dormitory
{
    return Dormitory::factory()->create(['gender' => $gender, ...$attributes]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function asramaRoom(int $capacity = 10, string $gender = 'L', array $attributes = [], ?Dormitory $dormitory = null): Room
{
    $dormitory ??= asramaDormitory($gender);

    return Room::factory()->create(['dormitory_id' => $dormitory->id, 'capacity' => $capacity, ...$attributes]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function asramaStudent(string $gender = 'L', array $attributes = []): Student
{
    return Student::factory()->create(['gender' => $gender, ...$attributes]);
}

/**
 * Penempatan aktif langsung lewat factory (tanpa Action).
 *
 * @param  array<string, mixed>  $attributes
 */
function asramaResident(Student $student, Room $room, array $attributes = []): RoomAssignment
{
    return RoomAssignment::factory()->create([
        'student_id' => $student->id,
        'room_id' => $room->id,
        'academic_year_id' => AcademicYear::query()->where('is_active', true)->value('id') ?? AcademicYear::factory()->active()->create()->id,
        'start_on' => '2026-07-15',
        ...$attributes,
    ]);
}

/**
 * User dengan peran, opsional dibatasi ke jenjang (kode unit, mis. 'SLTP').
 * Seeder peran dan unit harus sudah dijalankan oleh test pemanggil.
 *
 * @param  list<string>  $unitCodes
 */
function asramaUser(string $role, array $unitCodes = []): User
{
    $user = User::factory()->create()->assignRole($role);

    foreach ($unitCodes as $code) {
        $user->units()->attach(Unit::query()->where('code', $code)->value('id'));
    }

    return $user;
}

/**
 * Pola regex untuk pesan `pondok.asrama.errors.<reason>`; placeholder `:nama` bebas isi.
 */
function asramaMessagePattern(string $reason): string
{
    $message = __("pondok.asrama.errors.{$reason}");

    return '/^'.preg_replace('/\\\\:[A-Za-z_]+/', '.+', preg_quote($message, '/')).'$/su';
}

/**
 * Pastikan callback melempar ValidationException dengan pesan kunci lang `reason`.
 * Bila `$field` diisi, error harus ada pada field tersebut.
 */
function asramaAssertRejected(Closure $callback, string $reason, ?string $field = null): void
{
    expect(__("pondok.asrama.errors.{$reason}"))->not->toBe("pondok.asrama.errors.{$reason}");

    try {
        $callback();
    } catch (ValidationException $exception) {
        $errors = $exception->errors();

        if ($field !== null) {
            expect($errors)->toHaveKey($field);
        }

        $matches = collect($errors)->flatten()->contains(fn (string $message): bool => preg_match(asramaMessagePattern($reason), $message) === 1);

        expect($matches)->toBeTrue("Pesan error tidak sesuai pondok.asrama.errors.{$reason}: ".json_encode($errors));

        return;
    }

    test()->fail('ValidationException diharapkan, tetapi tidak dilempar.');
}

/**
 * Notifikasi Filament yang terkirim pada request Livewire terakhir.
 *
 * @return Collection<int, array<string, mixed>>
 */
function asramaNotifications(): Collection
{
    app(NotificationMailbox::class)->flush();

    $component = new Notifications;
    $component->mount();
    $component->pullNotificationsFromSession();

    return $component->notifications->map(fn (Notification $notification): array => $notification->toArray())->values();
}
