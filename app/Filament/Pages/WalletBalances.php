<?php

namespace App\Filament\Pages;

use App\Enums\Module;
use App\Enums\WalletTransactionType;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\WalletTransaction;
use BackedEnum;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rekap saldo uang saku (F028): satu baris per santri yang punya transaksi `berhasil`,
 * saldo dihitung dari ledger lewat subquery agregat. Total di footer mengikuti baris
 * yang tersaring pencarian. Cakupan jenjang mengikuti global scope `Student`,
 * santri yang diarsipkan tetap ikut.
 */
class WalletBalances extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'wallet-balances';

    protected string $view = 'filament.pages.wallet-balances';

    public static function getModule(): string
    {
        return Module::Keuangan->value;
    }

    public static function canAccess(): bool
    {
        return Setting::moduleEnabled(static::getModule())
            && (bool) auth()->user()?->can('view:wallet-balances');
    }

    /**
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        return array_map(
            fn (NavigationItem $item): NavigationItem => $item->visible(fn (): bool => static::canAccess()),
            parent::getNavigationItems(),
        );
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.keuangan');
    }

    public static function getNavigationLabel(): string
    {
        return __('pondok.wallet.balances.title');
    }

    public function getTitle(): string
    {
        return __('pondok.wallet.balances.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->balancesQuery())
            ->defaultSort('balance', 'desc')
            ->columns([
                TextColumn::make('nis')
                    ->label(__('pondok.student.nis'))
                    ->searchable()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('name')
                    ->label(__('pondok.wallet.student'))
                    ->searchable(),
                TextColumn::make('unit.name')
                    ->label(__('pondok.wallet.balances.unit')),
                TextColumn::make('balance')
                    ->label(__('pondok.wallet.balance'))
                    ->formatStateUsing(fn (mixed $state): string => Payment::formatRupiah((int) $state))
                    ->alignEnd()
                    ->weight('semibold')
                    ->extraAttributes(['class' => 'tabular-nums'])
                    ->sortable()
                    ->summarize(Sum::make()
                        ->label(__('pondok.wallet.balances.total'))
                        ->formatStateUsing(fn (mixed $state): string => Payment::formatRupiah((int) $state))),
            ])
            ->emptyStateIcon(Heroicon::OutlinedBanknotes)
            ->emptyStateHeading(__('pondok.wallet.balances.empty'))
            ->emptyStateDescription(__('pondok.wallet.balances.empty_description'));
    }

    /**
     * @return Builder<Student>
     */
    private function balancesQuery(): Builder
    {
        $credits = collect(WalletTransactionType::cases())
            ->filter(fn (WalletTransactionType $type): bool => $type->isCredit())
            ->map(fn (WalletTransactionType $type): string => $type->value)
            ->all();

        $placeholders = implode(', ', array_fill(0, count($credits), '?'));

        $successful = fn (): Builder => WalletTransaction::withoutUnitScope()
            ->whereColumn('wallet_transactions.student_id', 'students.id')
            ->where('wallet_transactions.status', WalletTransaction::STATUS_SUCCESS);

        return Student::query()
            ->withTrashed()
            ->with('unit')
            ->select('students.*')
            ->selectSub(
                $successful()->selectRaw("COALESCE(SUM(CASE WHEN type IN ({$placeholders}) THEN amount ELSE -amount END), 0)", $credits),
                'balance',
            )
            ->whereExists($successful());
    }
}
