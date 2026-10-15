<?php

namespace App\Livewire\Psb;

use App\Actions\Psb\CheckApplicantCredentials;
use App\Actions\Psb\UploadApplicantDocument;
use App\Models\AdmissionRequirement;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Halaman cek status pendaftar (tanpa akun): masuk dengan nomor pendaftaran +
 * token, lalu melihat status, tagihan pendaftaran, dan mengunggah berkas.
 *
 * Pendaftar tidak disimpan di properti publik; dibaca dari sesi tanpa global
 * scope setiap render. Sesi gugur bila pendaftar terhapus atau token diatur
 * ulang (sidik token berbeda).
 */
#[Layout('layouts.public')]
class ApplicationStatus extends Component
{
    use WithFileUploads;

    public const SESSION_APPLICANT = 'psb.applicant_id';

    public const SESSION_FINGERPRINT = 'psb.token_fingerprint';

    public const MAX_FAILURES_PER_IP = 5;

    public const MAX_FAILURES_PER_NUMBER = 10;

    public const UPLOAD_RULES = ['required', 'file', 'max:2048', 'mimes:pdf,jpg,jpeg,png'];

    public string $registration_no = '';

    public string $token = '';

    /** @var array<int|string, mixed> unggahan per id syarat */
    public array $documents = [];

    public ?string $alert = null;

    public ?string $notice = null;

    /**
     * Pendaftar dari sesi halaman status, atau null (sesi dilupakan bila tidak berlaku).
     */
    public static function sessionApplicant(): ?Applicant
    {
        $id = session(self::SESSION_APPLICANT);

        if ($id === null) {
            return null;
        }

        $applicant = Applicant::withoutUnitScope()->find($id);

        if ($applicant === null || ! hash_equals($applicant->tokenFingerprint(), (string) session(self::SESSION_FINGERPRINT))) {
            session()->forget([self::SESSION_APPLICANT, self::SESSION_FINGERPRINT]);

            return null;
        }

        return $applicant;
    }

    #[Computed]
    public function applicant(): ?Applicant
    {
        return static::sessionApplicant()?->load(['unit', 'admissionTrack', 'admissionWave.academicYear']);
    }

    /**
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function invoices(): Collection
    {
        // Semua tagihan aktif: formulir (F010) dan penerimaan (F011).
        return Invoice::withoutGlobalScopes()
            ->with(['items' => fn ($query) => $query->orderBy('id'), 'items.feeType'])
            ->where('applicant_id', $this->applicant->id)
            ->where('status', '<>', Invoice::STATUS_CANCELLED)
            ->orderBy('period')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, AdmissionRequirement>
     */
    #[Computed]
    public function requirements(): Collection
    {
        return $this->applicant->requirements()->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, ApplicantDocument>
     */
    #[Computed]
    public function uploadedDocuments(): \Illuminate\Support\Collection
    {
        return ApplicantDocument::withoutGlobalScopes()
            ->where('applicant_id', $this->applicant->id)
            ->get()
            ->keyBy('admission_requirement_id');
    }

    public function login(CheckApplicantCredentials $check): void
    {
        $this->alert = null;
        $number = mb_strtoupper(trim($this->registration_no));
        $ipKey = 'psb-status:'.request()->ip();
        $numberKey = 'psb-status-no:'.$number;

        foreach ([[$ipKey, self::MAX_FAILURES_PER_IP], [$numberKey, self::MAX_FAILURES_PER_NUMBER]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $this->alert = __('pondok.psb.errors.too_many_attempts', ['seconds' => RateLimiter::availableIn($key)]);

                return;
            }
        }

        $applicant = $check->handle($number, $this->token);

        if ($applicant === null) {
            RateLimiter::hit($ipKey, 60);
            RateLimiter::hit($numberKey, 3600);
            $this->alert = __('pondok.psb.errors.credentials_invalid');

            return;
        }

        RateLimiter::clear($ipKey);
        session()->regenerate();
        session()->put([
            self::SESSION_APPLICANT => $applicant->id,
            self::SESSION_FINGERPRINT => $applicant->tokenFingerprint(),
        ]);

        $this->reset('registration_no', 'token');
        unset($this->applicant);
    }

    public function logout(): void
    {
        session()->forget([self::SESSION_APPLICANT, self::SESSION_FINGERPRINT]);
        $this->reset('documents', 'alert', 'notice');
        unset($this->applicant);
    }

    public function updatedDocuments(mixed $value, string $key): void
    {
        $this->validateOnly("documents.{$key}", ["documents.{$key}" => self::UPLOAD_RULES]);
    }

    public function uploadDocument(int $requirementId, UploadApplicantDocument $upload): void
    {
        $this->alert = null;
        $this->notice = null;
        $applicant = $this->applicant;
        $requirement = AdmissionRequirement::query()->find($requirementId);

        if ($applicant === null || $requirement === null) {
            return;
        }

        $field = "documents.{$requirementId}";
        $this->validate([$field => self::UPLOAD_RULES]);

        try {
            $upload->handle($applicant, $requirement, $this->documents[$requirementId]);
        } catch (ValidationException $exception) {
            $this->addError($field, collect($exception->errors())->flatten()->first());

            return;
        }

        unset($this->documents[$requirementId], $this->uploadedDocuments);
        $this->notice = __('pondok.psb_public.uploaded', ['name' => $requirement->name]);
    }

    public function formatRupiah(int $amount): string
    {
        return Payment::formatRupiah($amount);
    }

    public function render(): View
    {
        return view('livewire.psb.application-status')->title(__('pondok.psb_public.status_title'));
    }
}
