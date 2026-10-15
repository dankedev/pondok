<?php

namespace App\Livewire\Psb;

use App\Actions\Psb\SubmitApplication;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionTrack;
use App\Models\AdmissionWave;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Wizard pendaftaran PSB publik (4 langkah). Data tidak disimpan sebelum
 * dikirim; setiap langkah diperiksa lewat `SubmitApplication::normalize()`.
 *
 * Anti-spam: honeypot `website`, waktu isi minimal sejak halaman dimuat, dan
 * rate limit per IP yang menghitung setiap percobaan kirim (bukan navigasi).
 * Token hasil kirim hanya ada di komponen ini (sekali tampil).
 */
#[Layout('layouts.public')]
class RegistrationForm extends Component
{
    public const STEP_FIELDS = [
        1 => ['unit_id', 'admission_track_id', 'admission_wave_id'],
        2 => ['name', 'gender', 'birth_place', 'birth_date', 'nisn', 'previous_school'],
        3 => ['guardian_name', 'guardian_phone', 'email'],
        4 => ['consent'],
    ];

    public const MAX_ATTEMPTS_PER_HOUR = 10;

    public const MIN_FILL_SECONDS = 3;

    public int $step = 1;

    public mixed $unit_id = null;

    public mixed $admission_track_id = null;

    public mixed $admission_wave_id = null;

    public string $name = '';

    public string $gender = '';

    public string $birth_place = '';

    public string $birth_date = '';

    public string $nisn = '';

    public string $previous_school = '';

    public string $guardian_name = '';

    public string $guardian_phone = '';

    public string $email = '';

    public bool $consent = false;

    /** Honeypot: harus tetap kosong. */
    public string $website = '';

    public ?string $alert = null;

    #[Locked]
    public int $startedAt = 0;

    #[Locked]
    public ?string $registeredNumber = null;

    #[Locked]
    public ?string $issuedToken = null;

    #[Locked]
    public ?int $feeTotal = null;

    public function mount(): void
    {
        $this->startedAt = now()->getTimestamp();

        if ($this->waves->count() === 1) {
            $this->admission_wave_id = $this->waves->first()->id;
        }
    }

    /**
     * @return Collection<int, AdmissionWave>
     */
    #[Computed]
    public function waves(): Collection
    {
        return AdmissionWave::openForRegistration(now('Asia/Jakarta'));
    }

    /**
     * @return Collection<int, Unit>
     */
    #[Computed]
    public function units(): Collection
    {
        return Unit::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, AdmissionTrack>
     */
    #[Computed]
    public function tracks(): Collection
    {
        return AdmissionTrack::query()->active()->orderBy('name')->get();
    }

    #[Computed]
    public function selectedTrack(): ?AdmissionTrack
    {
        return blank($this->admission_track_id) ? null : $this->tracks->firstWhere('id', (int) $this->admission_track_id);
    }

    /**
     * @return Collection<int, AdmissionRequirement>
     */
    #[Computed]
    public function requirements(): Collection
    {
        return blank($this->unit_id) ? new Collection : AdmissionRequirement::query()->forUnit((int) $this->unit_id)->get();
    }

    public function next(SubmitApplication $action): void
    {
        $this->alert = null;
        $this->resetErrorBag();

        if (! $this->validateStep($action, $this->step)) {
            return;
        }

        $this->step = min(4, $this->step + 1);
    }

    public function back(): void
    {
        $this->alert = null;
        $this->resetErrorBag();
        $this->step = max(1, $this->step - 1);
    }

    public function submit(SubmitApplication $action): void
    {
        $this->alert = null;
        $this->resetErrorBag();

        $key = 'psb-register:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS_PER_HOUR)) {
            $this->alert = __('pondok.psb.errors.too_many_registrations', [
                'minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60)),
            ]);

            return;
        }

        RateLimiter::hit($key, 3600);

        if ($this->website !== '' || now()->getTimestamp() - $this->startedAt < self::MIN_FILL_SECONDS) {
            $this->alert = __('pondok.psb.errors.spam_rejected');

            return;
        }

        try {
            $result = $action->handle($this->only(array_merge(...array_values(self::STEP_FIELDS))));
        } catch (ValidationException $exception) {
            $this->showErrors($exception);

            return;
        } catch (RuntimeException $exception) {
            $this->alert = __('pondok.psb.errors.number_conflict');

            return;
        }

        $this->registeredNumber = $result['applicant']->registration_no;
        $this->issuedToken = $result['token'];

        $invoices = Invoice::withoutGlobalScopes()
            ->where('applicant_id', $result['applicant']->id)
            ->where('status', '<>', Invoice::STATUS_CANCELLED);
        $this->feeTotal = $invoices->exists() ? (int) $invoices->sum('total') : null;
    }

    public function formatRupiah(int $amount): string
    {
        return Payment::formatRupiah($amount);
    }

    public function waveLabel(AdmissionWave $wave): string
    {
        return __('pondok.psb_public.wave_option', ['wave' => $wave->name, 'year' => $wave->academicYear->name]);
    }

    public function render(): View
    {
        return view('livewire.psb.registration-form')->title(__('pondok.psb_public.title'));
    }

    private function validateStep(SubmitApplication $action, int $step): bool
    {
        $fields = self::STEP_FIELDS[$step];

        try {
            $action->normalize($this->only($fields), $fields);
        } catch (ValidationException $exception) {
            $this->showErrors($exception);

            return false;
        }

        return true;
    }

    /**
     * Error field tampil di bawah field; error yang fieldnya tidak ada di langkah
     * aktif (mis. gelombang penuh, pendaftaran ganda) tampil sebagai alert.
     */
    private function showErrors(ValidationException $exception): void
    {
        $visible = self::STEP_FIELDS[$this->step];
        $alerts = [];

        foreach ($exception->errors() as $field => $messages) {
            $this->addError($field, $messages[0]);

            if (! in_array($field, $visible, true)) {
                $alerts[] = $messages[0];
            }
        }

        $this->alert = $alerts === [] ? null : implode(' ', array_unique($alerts));
    }
}
