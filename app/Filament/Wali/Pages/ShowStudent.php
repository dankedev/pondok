<?php

namespace App\Filament\Wali\Pages;

use App\Enums\Module;
use App\Filament\Concerns\BelongsToPortalWali;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Student;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Halaman santri portal wali (F022): ringkasan santri, tagihan (Keuangan aktif), dan
 * dokumen (Dokumen aktif). Hanya id yang disimpan; santri bukan milik wali → 404.
 *
 * @property-read Student $student
 */
class ShowStudent extends Page implements HasTable
{
    use BelongsToPortalWali, InteractsWithTable;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'santri';

    protected string $view = 'filament.wali.pages.show-student';

    #[Locked]
    public int $studentId;

    public static function getRoutePath(Panel $panel): string
    {
        return '/santri/{student}';
    }

    public function mount(int $student): void
    {
        $this->studentId = auth()->user()->portalStudent($student)->id;
    }

    #[Computed]
    public function student(): Student
    {
        return static::withPortalSummary(auth()->user()->portalStudents())->findOrFail($this->studentId);
    }

    public function getTitle(): string
    {
        return $this->student->name;
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [Dashboard::getUrl() => __('pondok.portal_wali.home'), $this->student->name];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('pondok.portal_wali.invoices'))
            ->query(fn () => auth()->user()->portalInvoices($this->student))
            ->defaultSort('period', 'desc')
            ->paginated([10])
            ->recordUrl(fn (Invoice $record): string => ShowInvoice::getUrl(['invoice' => $record->id]))
            ->emptyStateHeading(__('pondok.portal_wali.no_invoices'))
            ->emptyStateIcon('heroicon-o-banknotes')
            ->columns([
                TextColumn::make('invoice_no')
                    ->label(__('pondok.invoice.invoice_no')),
                TextColumn::make('period')
                    ->label(__('pondok.invoice.period'))
                    ->formatStateUsing(fn (Invoice $record): string => $record->period->translatedFormat('F Y'))
                    ->description(fn (Invoice $record): string => Invoice::billingGroupLabel((string) $record->billing_group)),
                TextColumn::make('total')
                    ->label(__('pondok.invoice.total'))
                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                    ->alignEnd(),
                TextColumn::make('remaining')
                    ->label(__('pondok.portal_wali.remaining'))
                    ->state(fn (Invoice $record): int => $record->remainingAmount())
                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                    ->alignEnd(),
                TextColumn::make('due_on')
                    ->label(__('pondok.portal_wali.due_on'))
                    ->date('j M Y')
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->label(__('pondok.invoice.status'))
                    ->badge()
                    ->color(fn (Invoice $record): string => $record->statusColor())
                    ->formatStateUsing(fn (Invoice $record): string => $record->statusLabel()),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'showInvoices' => Module::Keuangan->isEnabled(),
            'documents' => Module::Dokumen->isEnabled() ? $this->documents() : null,
        ];
    }

    /**
     * @return Collection<int, Document>
     */
    private function documents(): Collection
    {
        return auth()->user()->portalDocuments()
            ->where('documentable_id', $this->studentId)
            ->with('documentType')
            ->latest('id')
            ->get();
    }
}
