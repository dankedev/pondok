<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Pembayaran satu tagihan, termasuk yang `batal`. Tanpa Create, Edit,
 * maupun Delete: pembayaran dicatat lewat aksi "Catat pembayaran" di tagihan
 * dan dibatalkan lewat `CancelPayment`.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('pondok.payment.plural');
    }

    /**
     * Aksi bisnis kustom (cetak, batalkan) tetap tampil di halaman detail tagihan;
     * aksi bawaan pengubah data memang tidak dipasang.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('receipt_no')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('receiver'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('receipt_no')
                    ->label(__('pondok.payment.receipt_no')),
                TextColumn::make('paid_on')
                    ->label(__('pondok.payment.paid_on'))
                    ->date('j M Y'),
                TextColumn::make('method')
                    ->label(__('pondok.payment.method'))
                    ->formatStateUsing(fn (Payment $record): string => $record->methodLabel()),
                TextColumn::make('amount')
                    ->label(__('pondok.payment.amount'))
                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('receiver.name')
                    ->label(__('pondok.payment.received_by'))
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->label(__('pondok.payment.status'))
                    ->badge()
                    ->formatStateUsing(fn (Payment $record): string => $record->statusLabel())
                    ->color(fn (Payment $record): string => $record->statusColor()),
            ])
            ->emptyStateIcon(Heroicon::OutlinedBanknotes)
            ->emptyStateHeading(__('pondok.payment.empty_for_invoice'))
            ->recordActions([
                ActionGroup::make([
                    Action::make('view')
                        ->label(__('pondok.payment.view_action'))
                        ->icon(Heroicon::OutlinedEye)
                        ->color('gray')
                        ->visible(fn (Payment $record): bool => PaymentResource::canView($record))
                        ->url(fn (Payment $record): string => PaymentResource::getUrl('view', ['record' => $record])),
                    PaymentResource::printReceiptAction(),
                    PaymentResource::downloadProofAction(),
                    PaymentResource::cancelAction(),
                ]),
            ]);
    }
}
