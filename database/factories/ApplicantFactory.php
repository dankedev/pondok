<?php

namespace Database\Factories;

use App\Actions\Psb\ResetApplicantToken;
use App\Models\AdmissionTrack;
use App\Models\AdmissionWave;
use App\Models\Applicant;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * Pendaftar `diajukan` langsung ke database, tanpa validasi `SubmitApplication`.
 * Nomor pendaftaran melanjutkan nomor terbesar `PSB-2027-NNNN` yang sudah ada.
 *
 * @extends Factory<Applicant>
 */
class ApplicantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'admission_wave_id' => AdmissionWave::factory(),
            'admission_track_id' => AdmissionTrack::factory(),
            'unit_id' => Unit::factory(),
            'registration_no' => fn (): string => $this->nextRegistrationNo(),
            'access_token_hash' => Hash::make(fake()->bothify('????########')),
            'name' => fake()->unique()->name(),
            'nisn' => fake()->numerify('##########'),
            'gender' => fake()->randomElement(['L', 'P']),
            'birth_place' => fake()->city(),
            'birth_date' => fake()->dateTimeBetween('-15 years', '-6 years')->format('Y-m-d'),
            'previous_school' => 'SD '.fake()->city(),
            'guardian_name' => fake()->name(),
            'guardian_phone' => '08'.fake()->unique()->numerify('##########'),
            'email' => null,
            'status' => Applicant::STATUS_SUBMITTED,
            'consented_at' => now(),
        ];
    }

    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function withToken(string $token): static
    {
        return $this->state(['access_token_hash' => Hash::make(ResetApplicantToken::normalizeToken($token))]);
    }

    public function forWave(AdmissionWave $wave): static
    {
        return $this->state(['admission_wave_id' => $wave->id]);
    }

    /**
     * Pada `count()`, semua model dibuat sebelum disimpan sehingga nomor dari
     * database sama; nomor ganda di satu batch dinaikkan berurutan.
     *
     * @return Collection<int, Applicant>|Applicant
     */
    public function make($attributes = [], ?Model $parent = null)
    {
        $result = parent::make($attributes, $parent);

        if ($result instanceof Collection) {
            $seen = [];

            foreach ($result as $applicant) {
                while (isset($seen[$applicant->registration_no])) {
                    $applicant->registration_no = substr($applicant->registration_no, 0, 9)
                        .str_pad((string) ((int) substr($applicant->registration_no, 9) + 1), 4, '0', STR_PAD_LEFT);
                }

                $seen[$applicant->registration_no] = true;
            }
        }

        return $result;
    }

    private function nextRegistrationNo(): string
    {
        $last = Applicant::withoutGlobalScopes()
            ->where('registration_no', 'like', 'PSB-2027-%')
            ->max('registration_no');

        $sequence = $last === null ? 1 : (int) substr((string) $last, 9) + 1;

        return 'PSB-2027-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
