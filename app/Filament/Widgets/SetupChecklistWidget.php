<?php

namespace App\Filament\Widgets;

use App\Actions\Dashboard\SummarizeSetupProgress;
use App\Filament\Pages\Settings\Identity;
use App\Filament\Pages\Settings\Modules;
use App\Filament\Resources\AcademicYears\AcademicYearResource;
use App\Filament\Resources\Classrooms\ClassroomResource;
use App\Filament\Resources\FeeRates\FeeRateResource;
use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\Units\UnitResource;
use App\Filament\Resources\Users\UserResource;
use Filament\Widgets\Widget;

/**
 * Setup awal (F094): checklist langkah setup untuk super-admin, paling atas di dashboard,
 * dengan status dari `SummarizeSetupProgress`. Hilang sendiri saat semua langkah selesai.
 * Widget inti tanpa saklar modul; langkah milik modul mati disaring di Action.
 */
class SetupChecklistWidget extends Widget
{
    public const DOCS_URL = 'https://pondok.org/docs/';

    public const FEATURES_URL = 'https://pondok.org/fitur/';

    /** Slug halaman panduan pondok.org/docs per langkah. */
    public const DOCS = [
        'profile' => 'pengenalan',
        'units' => 'pengenalan',
        'academic_year' => 'pengenalan',
        'modules' => 'modul',
        'classrooms' => 'import-data',
        'students' => 'import-data',
        'staff' => 'pengenalan',
        'fee_rates' => 'pengenalan',
        'mail' => 'konfigurasi-env',
        'cron' => 'cron-antrean',
    ];

    protected static bool $isLazy = false;

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.setup-checklist';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasRole('super-admin')
            && app(SummarizeSetupProgress::class)->handle()['percent'] < 100;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $progress = app(SummarizeSetupProgress::class)->handle();

        $progress['steps'] = array_map(fn (array $step): array => [
            ...$step,
            'url' => $this->stepUrl($step['key']),
            'docs_url' => self::DOCS_URL.self::DOCS[$step['key']].'/',
        ], $progress['steps']);

        return [
            'progress' => $progress,
            'featuresUrl' => self::FEATURES_URL,
        ];
    }

    /**
     * Halaman admin untuk langkah, bila ada dan boleh dibuka. Email dan cron diatur di server.
     */
    private function stepUrl(string $key): ?string
    {
        [$canOpen, $url] = match ($key) {
            'profile' => [Identity::canAccess(), fn (): string => Identity::getUrl()],
            'units' => [UnitResource::canViewAny(), fn (): string => UnitResource::getUrl()],
            'academic_year' => [AcademicYearResource::canViewAny(), fn (): string => AcademicYearResource::getUrl()],
            'modules' => [Modules::canAccess(), fn (): string => Modules::getUrl()],
            'classrooms' => [ClassroomResource::canViewAny(), fn (): string => ClassroomResource::getUrl()],
            'students' => [StudentResource::canViewAny(), fn (): string => StudentResource::getUrl()],
            'staff' => [UserResource::canViewAny(), fn (): string => UserResource::getUrl()],
            'fee_rates' => [FeeRateResource::canViewAny(), fn (): string => FeeRateResource::getUrl()],
            default => [false, null],
        };

        return $canOpen ? $url() : null;
    }
}
