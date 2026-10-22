<?php

namespace App\Filament\Wali\Pages;

use App\Enums\Module;
use App\Filament\Concerns\BelongsToPortalWali;
use App\Http\Middleware\EnsureModuleIsEnabled;
use App\Models\Invoice;
use App\Models\Scopes\UnitScope;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Livewire\Attributes\Computed;

/**
 * Halaman tagihan portal wali (F022): rincian item, sisa, dan pembayaran `diterima`.
 * Tagihan `batal`, milik santri lain, atau saat Keuangan mati → 404.
 *
 * @property-read Invoice $invoice
 */
class ShowInvoice extends Page
{
    use BelongsToPortalWali {
        canAccess as portalWaliCanAccess;
    }

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'tagihan';

    protected static string|array $routeMiddleware = [EnsureModuleIsEnabled::class.':keuangan,404'];

    protected string $view = 'filament.wali.pages.show-invoice';

    public int $invoiceId;

    public static function canAccess(): bool
    {
        return static::portalWaliCanAccess() && Module::Keuangan->isEnabled();
    }

    public static function getRoutePath(Panel $panel): string
    {
        return '/tagihan/{invoice}';
    }

    public function mount(int $invoice): void
    {
        $this->invoiceId = auth()->user()->portalInvoice($invoice)->id;
    }

    #[Computed]
    public function invoice(): Invoice
    {
        return auth()->user()->portalInvoice($this->invoiceId)->load([
            'items.feeType',
            'student' => fn (Relation $relation) => $relation->withoutGlobalScope(UnitScope::class),
        ]);
    }

    public function getTitle(): string
    {
        return __('pondok.portal_wali.invoice_title', ['number' => $this->invoice->invoice_no]);
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            Dashboard::getUrl() => __('pondok.portal_wali.home'),
            ShowStudent::getUrl(['student' => $this->invoice->student_id]) => $this->invoice->student->name,
            $this->invoice->invoice_no,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'payments' => auth()->user()->portalPayments($this->invoice)->orderBy('paid_on')->orderBy('id')->get(),
        ];
    }
}
