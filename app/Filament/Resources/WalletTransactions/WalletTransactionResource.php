<?php

namespace App\Filament\Resources\WalletTransactions;

use App\Actions\Keuangan\RecordWalletTransaction;
use App\Actions\Keuangan\VoidWalletTransaction;
use App\Enums\WalletTransactionType;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\WalletTransactions\Pages\ListWalletTransactions;
use App\Models\Payment;
use App\Models\Student;
use App\Models\WalletTransaction;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Daftar mutasi uang saku santri (F028). Mutasi dicatat lewat `RecordWalletTransaction`
 * dan dibatalkan lewat `VoidWalletTransaction`; ledger append-only, jadi tidak ada
 * Create, Edit, View, maupun Delete.
 *
 * Cakupan jenjang mengikuti global scope `WalletTransaction` (lewat santri, termasuk yang diarsipkan).
 */
class WalletTransactionResource extends Resource
{
    use BelongsToModule, ShowsActionErrorsOnForm;

    protected static ?string $model = WalletTransaction::class;

    // Design guide §3: kapital hanya di awal kalimat.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?int $navigationSort = 2;

    public static function getModule(): string
    {
        return 'keuangan';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.wallet.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.wallet.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.keuangan');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student', 'recorder']))
            ->columns([
                TextColumn::make('occurred_on')
                    ->label(__('pondok.wallet.occurred_on'))
                    ->date('j M Y')
                    ->sortable(),
                TextColumn::make('student.nis')
                    ->label(__('pondok.student.nis'))
                    ->searchable()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('student.name')
                    ->label(__('pondok.wallet.student'))
                    ->searchable(),
                TextColumn::make('type')
                    ->label(__('pondok.wallet.type'))
                    ->formatStateUsing(fn (WalletTransactionType $state): string => $state->label()),
                TextColumn::make('amount')
                    ->label(__('pondok.wallet.amount'))
                    ->formatStateUsing(fn (int $state): string => Payment::formatRupiah($state))
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums'])
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('pondok.wallet.status_label'))
                    ->badge()
                    ->formatStateUsing(fn (WalletTransaction $record): string => $record->statusLabel())
                    ->color(fn (WalletTransaction $record): string => $record->statusColor()),
                TextColumn::make('note')
                    ->label(__('pondok.wallet.note'))
                    ->limit(40)
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('recorder.name')
                    ->label(__('pondok.wallet.recorded_by'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('pondok.wallet.type'))
                    ->options(collect(WalletTransactionType::cases())
                        ->mapWithKeys(fn (WalletTransactionType $type): array => [$type->value => $type->label()])
                        ->all()),
                SelectFilter::make('status')
                    ->label(__('pondok.wallet.status_label'))
                    ->options([
                        WalletTransaction::STATUS_SUCCESS => __('pondok.wallet.status.berhasil'),
                        WalletTransaction::STATUS_CANCELLED => __('pondok.wallet.status.batal'),
                    ]),
                SelectFilter::make('student_id')
                    ->label(__('pondok.wallet.student'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => static::studentOptions($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => static::studentLabel(Student::withTrashed()->find($value))),
                Filter::make('occurred_on')
                    ->schema([
                        DatePicker::make('from')
                            ->label(__('pondok.wallet.filter_from')),
                        DatePicker::make('until')
                            ->label(__('pondok.wallet.filter_until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('occurred_on', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('occurred_on', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = __('pondok.wallet.filter_from').' '.static::dateLabel($data['from']);
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = __('pondok.wallet.filter_until').' '.static::dateLabel($data['until']);
                        }

                        return $indicators;
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedWallet)
            ->emptyStateHeading(__('pondok.wallet.empty'))
            ->emptyStateDescription(__('pondok.wallet.empty_description'))
            ->recordActions([
                static::cancelAction(),
            ]);
    }

    /**
     * Mencatat setor, tarik, atau koreksi; kesalahan Action tampil di field form.
     */
    public static function recordAction(): Action
    {
        return Action::make('record')
            ->label(__('pondok.wallet.record_action'))
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading(__('pondok.wallet.record_heading'))
            ->modalSubmitActionLabel(__('pondok.wallet.record_submit'))
            ->visible(fn (): bool => static::canCreate())
            ->schema([
                Select::make('student_id')
                    ->label(__('pondok.wallet.student'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => static::studentOptions($search, active: true))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => static::studentLabel(Student::query()->find($value)))
                    ->required(),
                Select::make('type')
                    ->label(__('pondok.wallet.type'))
                    ->options(collect(WalletTransactionType::cases())
                        ->mapWithKeys(fn (WalletTransactionType $type): array => [$type->value => $type->label()])
                        ->all())
                    ->default(WalletTransactionType::Setor->value)
                    ->required(),
                TextInput::make('amount')
                    ->label(__('pondok.wallet.amount'))
                    ->prefix('Rp')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(RecordWalletTransaction::MAX_AMOUNT)
                    ->required(),
                DatePicker::make('occurred_on')
                    ->label(__('pondok.wallet.occurred_on'))
                    ->default(fn (): string => CarbonImmutable::now('Asia/Jakarta')->toDateString())
                    ->maxDate(fn (): string => CarbonImmutable::now('Asia/Jakarta')->toDateString())
                    ->required(),
                TextInput::make('note')
                    ->label(__('pondok.wallet.note'))
                    ->helperText(__('pondok.wallet.note_helper'))
                    ->maxLength(255),
            ])
            ->action(function (array $data, RecordWalletTransaction $recorder, Action $action): void {
                $student = Student::withoutUnitScope()->find($data['student_id']);

                static::runShowingFormErrors($action, function () use ($recorder, $student, $data): void {
                    try {
                        if ($student === null) {
                            throw ValidationException::withMessages(['student' => __('pondok.wallet.errors.student_unavailable')]);
                        }

                        $recorder->handle($student, auth()->user(), $data);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages(collect($exception->errors())
                            ->mapWithKeys(fn (array $messages, string $field): array => [$field === 'student' ? 'student_id' : $field => $messages])
                            ->all());
                    }
                });

                Notification::make()
                    ->title(__('pondok.wallet.recorded'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Membatalkan mutasi yang salah catat; tersembunyi untuk mutasi `batal`.
     */
    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('pondok.wallet.cancel_action'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('pondok.wallet.cancel_heading'))
            ->modalDescription(__('pondok.wallet.cancel_description'))
            ->modalSubmitActionLabel(__('pondok.wallet.cancel_submit'))
            ->authorize(fn (WalletTransaction $record): bool => static::can('cancel', $record))
            ->hidden(fn (WalletTransaction $record): bool => ! $record->isCancellable())
            ->schema([
                TextInput::make('reason')
                    ->label(__('pondok.wallet.cancel_reason'))
                    ->helperText(__('pondok.wallet.cancel_reason_helper'))
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (WalletTransaction $record, array $data, VoidWalletTransaction $void, Action $action): void {
                try {
                    $void->handle($record, auth()->user(), (string) $data['reason']);
                } catch (ValidationException $exception) {
                    // Sudah batal atau saldo akan negatif: bukan kesalahan isian form.
                    if (array_key_exists('transaction', $exception->errors())) {
                        Notification::make()->title($exception->errors()['transaction'][0])->danger()->send();
                        $action->cancel();

                        return;
                    }

                    static::runShowingFormErrors($action, fn () => throw $exception);
                }

                Notification::make()
                    ->title(__('pondok.wallet.cancelled'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Santri sesuai cakupan jenjang user; `$active` membuang yang diarsipkan (untuk form catat).
     *
     * @return array<int, string>
     */
    public static function studentOptions(string $search, bool $active = false): array
    {
        return Student::query()
            ->when(! $active, fn (Builder $query) => $query->withTrashed())
            ->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('nis', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Student $student): array => [$student->id => static::studentLabel($student)])
            ->all();
    }

    public static function studentLabel(?Student $student): ?string
    {
        return $student === null ? null : "{$student->nis} — {$student->name}";
    }

    public static function dateLabel(string $date): string
    {
        return CarbonImmutable::parse($date)->locale('id')->translatedFormat('j M Y');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWalletTransactions::route('/'),
        ];
    }
}
