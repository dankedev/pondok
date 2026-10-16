<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Achievement;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Prestasi individu belum terverifikasi tanpa peserta. Peserta ditambahkan
 * lewat `forStudents()`; test perilaku memakai `SaveAchievement`.
 *
 * @extends Factory<Achievement>
 */
class AchievementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'category' => fake()->randomElement(Achievement::CATEGORIES),
            'title' => 'Juara '.fake()->numberBetween(1, 3).' '.fake()->words(3, true),
            'event_name' => 'Lomba '.fake()->words(2, true),
            'organizer' => null,
            'level' => fake()->randomElement(Achievement::LEVELS),
            'kind' => Achievement::KIND_INDIVIDUAL,
            'achieved_on' => today()->subMonth()->toDateString(),
            'recorded_by' => User::factory(),
            'verified_by' => null,
            'is_public' => false,
        ];
    }

    public function verified(?User $verifier = null): static
    {
        return $this->state(fn (): array => ['verified_by' => $verifier?->id ?? User::factory()]);
    }

    public function public(): static
    {
        return $this->state(fn (): array => ['is_public' => true]);
    }

    public function team(): static
    {
        return $this->state(fn (): array => ['kind' => Achievement::KIND_TEAM]);
    }

    /**
     * @param  iterable<Student>  $students
     */
    public function forStudents(iterable $students, string $rank = 'Juara 1'): static
    {
        return $this->afterCreating(function (Achievement $achievement) use ($students, $rank): void {
            foreach ($students as $student) {
                $achievement->students()->attach($student->id, ['rank' => $rank, 'role' => null]);
            }
        });
    }
}
