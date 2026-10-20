<?php

namespace Database\Seeders\Demo;

use App\Actions\Keuangan\RecordPayment;
use App\Actions\Psb\AcceptApplicant;
use App\Actions\Psb\GenerateApplicantNumber;
use App\Actions\Psb\MarkApplicantVerified;
use App\Actions\Psb\RejectApplicant;
use App\Actions\Psb\ReRegisterApplicant;
use App\Actions\Psb\ReserveApplicant;
use App\Actions\Psb\ResetApplicantToken;
use App\Actions\Psb\SaveAdmissionRequirement;
use App\Actions\Psb\SaveAdmissionWave;
use App\Actions\Psb\SubmitApplication;
use App\Actions\Psb\UploadApplicantDocument;
use App\Actions\Psb\VerifyApplicantDocument;
use App\Models\AcademicYear;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionTrack;
use App\Models\AdmissionWave;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\DocumentType;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Unit;
use RuntimeException;

/**
 * Gelombang PSB tahun depan, syarat berkas, dan 14 pendaftar dengan alur
 * sampai daftar ulang.
 */
final class DemoAdmissions
{
    private AdmissionWave $wave;

    /** @var array<string, AdmissionRequirement> */
    private array $requirements = [];

    public function __construct(private readonly DemoContext $context) {}

    public function run(): void
    {
        $this->seedWave();
        $this->seedRequirements();
        $this->seedReRegistrationMinimums();

        foreach (DemoData::APPLICANTS as $row) {
            $this->seedApplicant($row);
        }
    }

    /**
     * Nominal syarat daftar ulang jenjang demo, hanya bila belum diatur dari panel.
     */
    private function seedReRegistrationMinimums(): void
    {
        foreach (DemoData::RE_REGISTRATION_MINIMUMS as $code => $amount) {
            Unit::query()->where('code', $code)->whereNull('min_reregistration_amount')->update(['min_reregistration_amount' => $amount]);
        }
    }

    /**
     * Dua gelombang bersambung pada tahun ajaran tujuan PSB; pendaftar demo masuk ke
     * gelombang yang menerima pendaftaran hari ini.
     */
    private function seedWave(): void
    {
        $calendar = $this->context->calendar;
        $start = $calendar->admissionYear();
        $year = AcademicYear::query()->firstOrCreate(['name' => $start.'/'.($start + 1)], [
            'starts_on' => "{$start}-07-01",
            'ends_on' => ($start + 1).'-06-30',
        ]);

        foreach ($calendar->admissionWaves() as $row) {
            $wave = AdmissionWave::query()->where('academic_year_id', $year->id)->where('name', $row['name'])->first()
                ?? app(SaveAdmissionWave::class)->handle([...$row, 'academic_year_id' => $year->id, 'is_open' => true]);

            if ($wave->isAcceptingRegistrationsOn($calendar->today)) {
                $this->wave = $wave;
            }
        }
    }

    private function seedRequirements(): void
    {
        $sort = 0;

        foreach (DemoData::REQUIREMENTS as $code => [$name, $required]) {
            $type = DocumentType::query()->where('code', $code)->firstOrFail();

            $this->requirements[$code] = AdmissionRequirement::query()
                ->where('document_type_id', $type->id)
                ->whereNull('unit_id')
                ->first()
                ?? app(SaveAdmissionRequirement::class)->handle([
                    'unit_id' => null,
                    'document_type_id' => $type->id,
                    'name' => $name,
                    'is_required' => $required,
                    'sort_order' => ++$sort,
                ]);
        }
    }

    /**
     * Tanggal bayar PSB: `$daysAgo` hari lalu, tidak sebelum gelombang dibuka.
     */
    private function paidOn(int $daysAgo): string
    {
        return $this->context->calendar->daysAgo($daysAgo)->max($this->wave->opens_on->toImmutable())->toDateString();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function seedApplicant(array $row): void
    {
        $calendar = $this->context->calendar;
        $unit = $this->context->unit($row['unit']);
        $birthDate = $calendar->birthDate($calendar->admissionYear() - DemoData::ENTRY_AGE[$row['unit']], $row['birth_md']);

        $exists = Applicant::withoutUnitScope()->withTrashed()
            ->where('admission_wave_id', $this->wave->id)
            ->where('name', $row['name'])
            ->whereDate('birth_date', $birthDate)
            ->exists();

        if ($exists) {
            return;
        }

        $data = [
            'unit_id' => $unit->id,
            'admission_track_id' => AdmissionTrack::query()->where('code', $row['track'])->value('id'),
            'admission_wave_id' => $this->wave->id,
            'name' => $row['name'],
            'gender' => 'L',
            'birth_place' => DemoData::PLACES[$row['place']],
            'birth_date' => $birthDate,
            'previous_school' => $row['school'],
            'guardian_name' => $row['guardian'],
            'guardian_phone' => $row['phone'],
            'email' => null,
            'consent' => true,
        ];

        if ($row['flow'] === 'draft') {
            $applicant = new Applicant([
                ...collect($data)->except('consent')->all(),
                'registration_no' => app(GenerateApplicantNumber::class)->handle($this->wave),
                'status' => Applicant::STATUS_DRAFT,
            ]);
            $applicant->forceFill(['access_token_hash' => ResetApplicantToken::hash(ResetApplicantToken::newToken())])->save();

            return;
        }

        $applicant = app(SubmitApplication::class)->handle($data)['applicant'];
        $panel = $this->context->user('admin-psb');
        $flow = $row['flow'];

        if ($flow === 'submitted') {
            return;
        }

        if ($flow === 'rejected') {
            app(RejectApplicant::class)->handle($applicant, $panel, 'Berkas dan persyaratan usia tidak sesuai jenjang yang dipilih.');

            return;
        }

        $this->payGroup($applicant, 'PSB_DAFTAR', 100, $this->paidOn(10));

        if (in_array($flow, ['submitted_doc_pending', 'submitted_doc_rejected'], true)) {
            $document = $this->upload($applicant, 'KK');

            if ($flow === 'submitted_doc_rejected') {
                app(VerifyApplicantDocument::class)->handle($document, $panel, ApplicantDocument::STATUS_REJECTED, 'Foto Kartu Keluarga buram, mohon unggah ulang.');
            }

            return;
        }

        $required = array_keys(array_filter(DemoData::REQUIREMENTS, fn (array $requirement): bool => $requirement[1]));

        foreach ($required as $code) {
            app(VerifyApplicantDocument::class)->handle($this->upload($applicant, $code), $panel, ApplicantDocument::STATUS_VALID);
        }

        app(MarkApplicantVerified::class)->handle($applicant, $panel);

        if ($flow === 'verified') {
            return;
        }

        if ($flow === 'reserved') {
            app(ReserveApplicant::class)->handle($applicant, $panel, 'Kuota rombel putri penuh, menunggu santri yang mengundurkan diri.');

            return;
        }

        // Keringanan jalur dibuat saat diterima dan tercatat disetujui oleh admin PSB yang menerima.
        $result = app(AcceptApplicant::class)->handle($applicant, $this->context->user('admin-psb'));

        if ($result['billing_failed']) {
            throw new RuntimeException("Tagihan penerimaan pendaftar demo {$applicant->name} gagal dibuat.");
        }

        $paidOn = $this->paidOn(2);

        match ($flow) {
            'accepted_partial' => $this->payGroup($applicant, 'PSB_TERIMA', 30, $paidOn),
            'reregistered_full' => $this->payGroup($applicant, 'PSB_TERIMA', 100, $paidOn),
            'reregistered_partial' => $this->payGroup($applicant, 'PSB_TERIMA', 60, $paidOn),
            default => null,
        };

        if (str_starts_with($flow, 'reregistered')) {
            app(ReRegisterApplicant::class)->handle($applicant, $panel, ['relation' => $row['relation'] ?? ReRegisterApplicant::DEFAULT_RELATION]);
        }
    }

    private function upload(Applicant $applicant, string $code): ApplicantDocument
    {
        return app(UploadApplicantDocument::class)->handle(
            $applicant,
            $this->requirements[$code],
            $this->context->files->pdf(strtolower($code).'-pendaftar'),
        );
    }

    private function payGroup(Applicant $applicant, string $group, int $percent, string $paidOn): void
    {
        $invoices = Invoice::withoutUnitScope()
            ->where('applicant_id', $applicant->id)
            ->where('billing_group', $group)
            ->where('status', '<>', Invoice::STATUS_CANCELLED)
            ->get();

        foreach ($invoices as $invoice) {
            $amount = $percent >= 100 ? $invoice->total : intdiv($invoice->total * $percent, 100 * 1000) * 1000;

            if ($amount > 0 && $invoice->remainingAmount() > 0) {
                app(RecordPayment::class)->handle($invoice, $this->context->user('bendahara'), [
                    'amount' => min($amount, $invoice->remainingAmount()),
                    'paid_on' => $paidOn,
                    'method' => Payment::METHOD_TRANSFER,
                ]);
            }
        }
    }
}
