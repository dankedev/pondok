<?php

namespace App\Actions\Konten;

use App\Actions\Keuangan\ResolveFeeRate;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionWave;
use App\Models\FeeType;
use App\Models\Setting;
use App\Models\StaticPage;
use App\Models\Unit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Data halaman Info PSB publik (F038), murni baca dan tanpa cakupan jenjang user login.
 * Isi halaman dari halaman terbit `info-psb` bertemplate `psb-info` (opsional), lalu empat
 * bagian otomatis: gelombang dibuka/mendatang, syarat berkas per jenjang, biaya per jenjang
 * (modul keuangan aktif dan ada gelombang dibuka), dan kontak bertanda `is_psb`. Bagian
 * kosong = `[]`.
 *
 * Query: konstan terhadap jumlah gelombang tanpa kuota, syarat, dan kontak. `isFull()` satu
 * query per gelombang berkuota yang berlangsung; biaya dua query ditambah `ResolveFeeRate`
 * per pasangan jenjang x jenis biaya.
 */
class GetPsbInfo
{
    /** Frekuensi biaya yang ditampilkan; `tahunan` dan `manual` sengaja tidak. */
    public const FEE_FREQUENCIES = [FeeType::FREQUENCY_ON_REGISTRATION, FeeType::FREQUENCY_ON_ACCEPTANCE, FeeType::FREQUENCY_MONTHLY];

    public const WAVE_OPEN = 'open';

    public const WAVE_UPCOMING = 'upcoming';

    public function __construct(
        private readonly SanitizeContentHtml $sanitize,
        private readonly GetSchoolProfile $schoolProfile,
        private readonly ResolveFeeRate $feeRates,
    ) {}

    /**
     * @return array{
     *     page: ?StaticPage,
     *     bodyHtml: ?HtmlString,
     *     waves: list<array{name: string, academic_year: string, opens_on: CarbonInterface, closes_on: CarbonInterface, status: string}>,
     *     requirements: list<array{unit: ?string, items: list<array{name: string, is_required: bool}>}>,
     *     fees: list<array{unit: string, items: list<array{name: string, frequency: string, amount: int}>}>,
     *     contacts: list<array{key: ?string, label: string, name: ?string, phone: string, is_whatsapp: bool, is_print: bool, is_psb: bool}>,
     * }
     */
    public function handle(): array
    {
        $page = StaticPage::query()->published()
            ->where('slug', StaticPage::PSB_INFO_SLUG)
            ->where('template', StaticPage::TEMPLATE_PSB_INFO)
            ->first();
        $body = $page === null ? '' : $this->sanitize->handle($page->body);

        $openWaves = AdmissionWave::openForRegistration(now('Asia/Jakarta'));

        return [
            'page' => $page,
            'bodyHtml' => $this->sanitize->isBlank($body) ? null : new HtmlString($body),
            'waves' => $this->waves($openWaves),
            'requirements' => $this->requirements(),
            'fees' => $this->fees($openWaves->first()),
            'contacts' => $this->contacts(),
        ];
    }

    /**
     * @param  Collection<int, AdmissionWave>  $openWaves
     * @return list<array{name: string, academic_year: string, opens_on: CarbonInterface, closes_on: CarbonInterface, status: string}>
     */
    private function waves(Collection $openWaves): array
    {
        // Nama tahun ajaran lewat join (bukan eager load) agar jumlah query tetap walau daftar ini kosong.
        $upcoming = AdmissionWave::query()
            ->join('academic_years', 'academic_years.id', '=', 'admission_waves.academic_year_id')
            ->where('admission_waves.is_open', true)
            ->whereDate('admission_waves.opens_on', '>', now('Asia/Jakarta')->format('Y-m-d'))
            ->orderBy('admission_waves.opens_on')
            ->orderBy('admission_waves.id')
            ->select('admission_waves.*', 'academic_years.name as academic_year_name')
            ->get();

        return [
            ...$openWaves->map(fn (AdmissionWave $wave): array => $this->wave($wave, $wave->academicYear->name, self::WAVE_OPEN)),
            ...$upcoming->map(fn (AdmissionWave $wave): array => $this->wave($wave, $wave->getAttribute('academic_year_name'), self::WAVE_UPCOMING)),
        ];
    }

    /**
     * @return array{name: string, academic_year: string, opens_on: CarbonInterface, closes_on: CarbonInterface, status: string}
     */
    private function wave(AdmissionWave $wave, string $academicYear, string $status): array
    {
        return [
            'name' => $wave->name,
            'academic_year' => $academicYear,
            'opens_on' => $wave->opens_on,
            'closes_on' => $wave->closes_on,
            'status' => $status,
        ];
    }

    /**
     * Dikelompokkan per jenjang; `unit` null (semua jenjang) lebih dulu, lalu urut jenjang.
     *
     * @return list<array{unit: ?string, items: list<array{name: string, is_required: bool}>}>
     */
    private function requirements(): array
    {
        return AdmissionRequirement::query()
            ->with('unit')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (AdmissionRequirement $requirement): int => $requirement->unit_id ?? 0)
            ->sortBy(fn (Collection $group): array => [$group->first()->unit?->sort_order ?? -1, $group->first()->unit_id ?? 0])
            ->map(fn (Collection $group): array => [
                'unit' => $group->first()->unit?->name,
                'items' => $group->map(fn (AdmissionRequirement $requirement): array => [
                    'name' => $requirement->name,
                    'is_required' => $requirement->is_required,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Tarif tahun ajaran dan gelombang dibuka pertama; jenjang atau jenis tanpa tarif dilewati.
     *
     * @return list<array{unit: string, items: list<array{name: string, frequency: string, amount: int}>}>
     */
    private function fees(?AdmissionWave $wave): array
    {
        if ($wave === null || ! Setting::moduleEnabled('keuangan')) {
            return [];
        }

        $feeTypes = FeeType::query()->active()
            ->whereIn('frequency', self::FEE_FREQUENCIES)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        if ($feeTypes->isEmpty()) {
            return [];
        }

        $fees = [];

        foreach (Unit::query()->orderBy('sort_order')->orderBy('id')->get() as $unit) {
            $items = [];

            foreach ($feeTypes as $feeType) {
                $rate = $this->feeRates->handle($feeType, $wave->academicYear, $unit->id, admissionWaveId: $wave->id);

                if ($rate !== null) {
                    $items[] = ['name' => $feeType->name, 'frequency' => $feeType->frequency, 'amount' => (int) $rate->amount];
                }
            }

            if ($items !== []) {
                $fees[] = ['unit' => $unit->name, 'items' => $items];
            }
        }

        return $fees;
    }

    /**
     * Kontak profil apa adanya (nomor belum diformat) agar view memakai partial `contact-list`.
     *
     * @return list<array{key: ?string, label: string, name: ?string, phone: string, is_whatsapp: bool, is_print: bool, is_psb: bool}>
     */
    private function contacts(): array
    {
        return array_values(array_filter($this->schoolProfile->handle()['contacts'], fn (array $contact): bool => $contact['is_psb']));
    }
}
