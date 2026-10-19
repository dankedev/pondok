<?php

namespace App\Filament\Pages\Settings;

use App\Actions\Keuangan\GenerateInvoiceNumber;
use App\Actions\Keuangan\GenerateReceiptNumber;
use App\Actions\Keuangan\UpdateReceiptSigner;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengaturan format kuitansi dan tagihan (F039). Akses lewat
 * `view:finance-numbering` (bendahara dan super-admin).
 */
class FinanceNumbering extends NumberingSettingsPage
{
    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'settings/finance-numbering';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.keuangan');
    }

    public static function getNavigationLabel(): string
    {
        return __('pondok.numbering.finance.title');
    }

    public function getTitle(): string
    {
        return __('pondok.numbering.finance.title');
    }

    protected static function pagePermission(): string
    {
        return 'view:finance-numbering';
    }

    protected static function moduleKey(): ?string
    {
        return 'keuangan';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('pondok.numbering.finance.invoice_heading'))
                    ->description(__('pondok.numbering.finance.invoice_description'))
                    ->schema([
                        TextInput::make('invoice_format')
                            ->label(__('pondok.numbering.finance.invoice_format_label'))
                            ->helperText(__('pondok.numbering.finance.invoice_format_helper'))
                            ->required()
                            ->maxLength(20)
                            ->live(),
                        Placeholder::make('invoice_preview')
                            ->label(__('pondok.numbering.preview'))
                            ->content(fn (Get $get): string => $this->invoicePreview($get('invoice_format'))),
                    ]),
                Section::make(__('pondok.numbering.finance.receipt_heading'))
                    ->description(__('pondok.numbering.finance.receipt_description'))
                    ->schema([
                        TextInput::make('receipt_format')
                            ->label(__('pondok.numbering.finance.receipt_format_label'))
                            ->helperText(__('pondok.numbering.finance.receipt_format_helper'))
                            ->required()
                            ->maxLength(20)
                            ->live(),
                        Placeholder::make('receipt_preview')
                            ->label(__('pondok.numbering.preview'))
                            ->content(fn (Get $get): string => $this->receiptPreview($get('receipt_format'))),
                    ]),
                Section::make(__('pondok.numbering.finance.signer_heading'))
                    ->description(__('pondok.numbering.finance.signer_description'))
                    ->schema([
                        Select::make('receipt_signer_staff_id')
                            ->label(__('pondok.numbering.finance.signer_label'))
                            ->helperText(__('pondok.numbering.finance.signer_helper'))
                            ->placeholder(__('pondok.numbering.finance.signer_placeholder'))
                            ->options(fn (): array => $this->signerOptions())
                            ->getSearchResultsUsing(fn (string $search): array => $this->signerOptions($search))
                            ->searchable()
                            ->nullable(),
                    ]),
            ]);
    }

    /**
     * @return array<string, string|int|null>
     */
    protected function fillData(): array
    {
        $signerId = Setting::get(UpdateReceiptSigner::SETTING_KEY);

        return [
            'invoice_format' => app(GenerateInvoiceNumber::class)->resolvedFormat(),
            'receipt_format' => app(GenerateReceiptNumber::class)->resolvedFormat(),
            'receipt_signer_staff_id' => $signerId !== null && Staff::query()->where('is_active', true)->whereKey($signerId)->exists()
                ? (int) $signerId
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function persist(array $data, User $user): void
    {
        $invoice = app(GenerateInvoiceNumber::class);
        $receipt = app(GenerateReceiptNumber::class);

        try {
            $invoiceFormat = $invoice->validateFormat($data['invoice_format'] ?? null);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'invoice_format' => $exception->errors()['format'][0] ?? __('pondok.numbering.invalid_format'),
            ]);
        }

        try {
            $receiptFormat = $receipt->validateFormat($data['receipt_format'] ?? null);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'receipt_format' => $exception->errors()['format'][0] ?? __('pondok.numbering.invalid_format'),
            ]);
        }

        $signerId = filled($data['receipt_signer_staff_id'] ?? null) ? (int) $data['receipt_signer_staff_id'] : null;

        DB::transaction(function () use ($invoiceFormat, $receiptFormat, $signerId, $user): void {
            $old = [
                'invoice_format' => Setting::query()->find(GenerateInvoiceNumber::SETTING_KEY)?->value,
                'receipt_format' => Setting::query()->find(GenerateReceiptNumber::SETTING_KEY)?->value,
            ];

            Setting::set(GenerateInvoiceNumber::SETTING_KEY, $invoiceFormat);
            Setting::set(GenerateReceiptNumber::SETTING_KEY, $receiptFormat);

            activity('keuangan')
                ->causedBy($user)
                ->event('numbering_updated')
                ->withProperties([
                    'old' => $old,
                    'new' => ['invoice_format' => $invoiceFormat, 'receipt_format' => $receiptFormat],
                ])
                ->log('numbering_updated');

            app(UpdateReceiptSigner::class)->handle($signerId, $user);
        });
    }

    /**
     * @return array<int, string>
     */
    public function signerOptions(string $search = ''): array
    {
        return Staff::query()
            ->where('is_active', true)
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Staff $staff): array => [$staff->id => filled($staff->position)
                ? __('pondok.issued_letter.signer_option', ['name' => $staff->name, 'position' => $staff->position])
                : $staff->name])
            ->all();
    }

    public function invoicePreview(?string $format): string
    {
        try {
            return app(GenerateInvoiceNumber::class)->preview((string) $format, CarbonImmutable::now(config('app.timezone')));
        } catch (ValidationException) {
            return __('pondok.numbering.invalid_format');
        }
    }

    public function receiptPreview(?string $format): string
    {
        try {
            return app(GenerateReceiptNumber::class)->preview((string) $format, CarbonImmutable::now(config('app.timezone')));
        } catch (ValidationException) {
            return __('pondok.numbering.invalid_format');
        }
    }
}
