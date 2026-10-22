<?php

namespace App\Filament\Wali\Pages;

use App\Enums\Module;
use App\Filament\Concerns\BelongsToPortalWali;
use App\Models\Invoice;
use App\Models\Student;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Beranda portal wali (F022): kartu per santri yang terhubung dengan akun wali login,
 * dengan sisa tagihan bila Keuangan aktif.
 */
class Dashboard extends Page
{
    use BelongsToPortalWali;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?int $navigationSort = -2;

    protected string $view = 'filament.wali.pages.dashboard';

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public static function getNavigationLabel(): string
    {
        return __('pondok.portal_wali.home');
    }

    public function getTitle(): string
    {
        return __('pondok.portal_wali.home');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = auth()->user();
        $students = static::withPortalSummary($user->portalStudents())->orderBy('name')->get();

        return [
            'students' => $students,
            'outstanding' => Module::Keuangan->isEnabled() ? $this->outstanding($students) : null,
        ];
    }

    /**
     * Sisa tagihan non-batal per santri (satu query per santri lewat `portalInvoices()`; wali hanya punya sedikit santri).
     *
     * @param  Collection<int, Student>  $students
     * @return array<int, int>
     */
    private function outstanding(Collection $students): array
    {
        $user = auth()->user();

        return $students->mapWithKeys(fn (Student $student): array => [
            $student->id => $user->portalInvoices($student)
                ->get(['id', 'total', 'paid_total'])
                ->sum(fn (Invoice $invoice): int => $invoice->remainingAmount()),
        ])->all();
    }
}
