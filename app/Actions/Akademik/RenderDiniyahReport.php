<?php

namespace App\Actions\Akademik;

use App\Actions\Konten\GetPrintIdentity;
use App\Models\Setting;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;

/**
 * Halaman cetak A4 rapor diniyah (F027), satu view untuk cetak admin dan portal wali. Menolak (404)
 * rapor tanpa enrollment pada tahun itu atau tanpa nilai dan catatan sama sekali. Tanpa pemeriksaan akses.
 */
class RenderDiniyahReport
{
    public function __construct(private readonly GetPrintIdentity $printIdentity) {}

    /**
     * @param  array<string, mixed>  $card  hasil `BuildDiniyahReportCard`
     */
    public function handle(array $card, string $backUrl): View
    {
        abort_if($card['classroom'] === null || ($card['average'] === null && $card['note'] === null), 404);

        $logoPath = Setting::get('school.logo_path');

        return view('akademik.diniyah-report', [
            'card' => $card,
            'backUrl' => $backUrl,
            'school' => [
                ...$this->printIdentity->handle(),
                'logo' => filled($logoPath) && Storage::disk('public')->exists($logoPath) ? Storage::disk('public')->url($logoPath) : null,
            ],
            'printedAt' => now('Asia/Jakarta'),
        ]);
    }
}
