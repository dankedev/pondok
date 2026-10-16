<?php

namespace Database\Factories;

use App\Models\IssuedLetter;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Surat keluar `keterangan_aktif` hari ini dengan snapshot minimal. Factory
 * tidak memakai pengaturan penomoran; test perilaku memakai `IssueLetter`.
 *
 * Nomor urut memakai rentang 50000-89999 agar tidak bentrok dengan nomor
 * yang diterbitkan `IssueLetter` di test.
 *
 * @extends Factory<IssuedLetter>
 */
class IssuedLetterFactory extends Factory
{
    public function definition(): array
    {
        $sequence = fake()->unique()->numberBetween(50000, 89999);

        return [
            'letter_no' => fn (array $attributes): string => sprintf('%05d/FAKE/%s/%d', $sequence, strtoupper(substr((string) $attributes['type'], 0, 3)), today('Asia/Jakarta')->year),
            'type' => IssuedLetter::TYPE_ACTIVE_STATEMENT,
            'sequence_year' => today('Asia/Jakarta')->year,
            'sequence' => $sequence,
            'student_id' => Student::factory(),
            'signed_by_staff_id' => Staff::factory(),
            'issued_on' => today('Asia/Jakarta')->toDateString(),
            'purpose' => 'Persyaratan administrasi',
            'verification_code' => fn (): string => IssuedLetter::newVerificationCode(),
            'snapshot' => [
                'student' => ['name' => fake()->name()],
                'signer' => ['name' => fake()->name(), 'position' => 'Kepala Sekolah', 'nip' => null],
                'school' => ['name' => config('app.name'), 'address' => null, 'phone' => null],
            ],
            'file_path' => null,
            'status' => IssuedLetter::STATUS_ISSUED,
            'issued_by' => User::factory(),
        ];
    }

    public function ofType(string $type): static
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'purpose' => $type === IssuedLetter::TYPE_STUDENT_CARD ? null : 'Persyaratan administrasi',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status' => IssuedLetter::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => User::factory(),
            'cancel_reason' => 'Salah input',
        ]);
    }
}
