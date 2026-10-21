<?php

namespace App\Filament\Pages;

use App\Actions\Keuangan\ExportFinanceReportCsv;
use App\Actions\Keuangan\FinanceReportFilter;
use App\Actions\Keuangan\ListTopArrears;
use App\Actions\Keuangan\SummarizeCashReceipts;
use App\Actions\Keuangan\SummarizeInvoices;
use App\Actions\Keuangan\SummarizeScholarshipSubsidies;
use App\Filament\Resources\Students\StudentResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan keuangan (F008): ringkasan tagihan, santri menunggak teratas,
 * subsidi beasiswa, dan penerimaan kas untuk rentang bulan yang dipilih.
 *
 * Angka dihitung oleh Action `App\Actions\Keuangan\*` dan disimpan di `$report`
 * hanya saat filter valid; filter tidak valid menampilkan error di field dan
 * laporan terakhir tetap tampil tanpa dihitung ulang. Akses lewat permission
 * page Shield `view:finance-report` dan saklar modul keuangan, dicek juga saat
 * aksi unduh CSV dipanggil.
 *
 * @property-read Schema $form
 */
class FinanceReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'finance-report';

    protected string $view = 'filament.pages.finance-report';

    /** Jumlah baris santri menunggak di halaman (CSV memuat semua). */
    public const ARREARS_LIMIT = 10;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * Hasil keempat Action untuk filter valid terakhir.
     *
     * @var array{filter: array<string, mixed>, invoices: array<string, mixed>, arrears: list<array<string, mixed>>, subsidies: array<string, mixed>, cash: array<string, mixed>}|array{}
     */
    public array $report = [];

    public bool $hasInvalidFilter = false;

    public static function canAccess(): bool
    {
        return Setting::moduleEnabled('keuangan')
            && (bool) auth()->user()?->can('view:finance-report');
    }

    /**
     * Item navigasi disimpan di instance panel; cek ulang akses saat dirender agar
     * item yang terdaftar untuk user lain dalam proses yang sama tidak ikut tampil.
     *
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
        return __('pondok.finance_report.title');
    }

    public function getTitle(): string
    {
        return __('pondok.finance_report.title');
    }

    public function mount(): void
    {
        $filter = FinanceReportFilter::currentMonth();

        $this->form->fill([
            'period_from' => $filter->periodFrom->format('Y-m'),
            'period_to' => $filter->periodTo->format('Y-m'),
            'unit_id' => null,
            'billing_group' => null,
        ]);

        $this->loadReport($filter);
    }

    /**
     * Setiap perubahan filter (field `->live()`) menghitung ulang laporan bila valid.
     */
    public function updatedData(): void
    {
        $this->resetErrorBag();

        try {
            $filter = FinanceReportFilter::make(
                $this->data['period_from'] ?? null,
                $this->data['period_to'] ?? null,
                $this->data['unit_id'] ?? null,
                $this->data['billing_group'] ?? null,
            );
        } catch (ValidationException $exception) {
            $this->hasInvalidFilter = true;

            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError("data.{$field}", $message);
                }
            }

            return;
        }

        $this->hasInvalidFilter = false;
        $this->loadReport($filter);
    }

    public function form(Schema $schema): Schema
    {
        $months = $this->monthOptions();

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('pondok.finance_report.filters.heading'))
                    ->columns(['default' => 1, 'sm' => 2, 'xl' => 4])
                    ->schema([
                        Select::make('period_from')
                            ->label(__('pondok.finance_report.filters.period_from'))
                            ->options($months)
                            ->selectablePlaceholder(false)
                            ->live(),
                        Select::make('period_to')
                            ->label(__('pondok.finance_report.filters.period_to'))
                            ->options($months)
                            ->selectablePlaceholder(false)
                            ->live(),
                        Select::make('unit_id')
                            ->label(__('pondok.finance_report.filters.unit'))
                            ->placeholder(__('pondok.finance_report.filters.all_units'))
                            ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all())
                            ->live(),
                        Select::make('billing_group')
                            ->label(__('pondok.finance_report.filters.billing_group'))
                            ->placeholder(__('pondok.finance_report.filters.all_groups'))
                            ->options(fn (): array => Invoice::query()
                                ->distinct()
                                ->orderBy('billing_group')
                                ->pluck('billing_group', 'billing_group')
                                ->all())
                            ->live(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            ...$this->reportComponents(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('pondok.finance_report.export.label'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->authorize(fn (): bool => static::canAccess())
                ->visible(fn (): bool => ! $this->hasInvalidFilter && $this->report !== [])
                ->modalHeading(__('pondok.finance_report.export.heading'))
                ->modalDescription(__('pondok.finance_report.export.description'))
                ->modalSubmitActionLabel(__('pondok.finance_report.export.submit'))
                ->schema([
                    Radio::make('section')
                        ->label(__('pondok.finance_report.export.section'))
                        ->options(collect(ExportFinanceReportCsv::SECTIONS)
                            ->mapWithKeys(fn (string $section): array => [$section => __("pondok.finance_report.export.sections.{$section}")])
                            ->all())
                        ->default('ringkasan')
                        ->required(),
                ])
                ->action(function (array $data): StreamedResponse {
                    $filter = $this->appliedFilter();
                    /** @var User $user */
                    $user = auth()->user();

                    return app(ExportFinanceReportCsv::class)->handle($filter, (string) $data['section'], $user);
                }),
        ];
    }

    private function loadReport(FinanceReportFilter $filter): void
    {
        // Skema `content` bisa sudah dibangun dari laporan lama pada request yang sama.
        unset($this->cachedSchemas['content']);

        $this->report = [
            'filter' => $filter->toArray(),
            'invoices' => app(SummarizeInvoices::class)->handle($filter),
            'arrears' => app(ListTopArrears::class)->handle($filter, self::ARREARS_LIMIT),
            'subsidies' => app(SummarizeScholarshipSubsidies::class)->handle($filter),
            'cash' => app(SummarizeCashReceipts::class)->handle($filter),
        ];
    }

    /**
     * Filter valid terakhir (yang angkanya sedang tampil), divalidasi ulang terhadap cakupan user saat ini.
     */
    private function appliedFilter(): FinanceReportFilter
    {
        $filter = $this->report['filter'] ?? FinanceReportFilter::currentMonth()->toArray();

        return FinanceReportFilter::make($filter['period_from'], $filter['period_to'], $filter['unit_id'], $filter['billing_group']);
    }

    /**
     * 36 bulan terakhir sampai 12 bulan ke depan, terbaru di atas.
     *
     * @return array<string, string>
     */
    private function monthOptions(): array
    {
        $current = CarbonImmutable::now(config('app.timezone'))->startOfMonth();
        $options = [];

        for ($offset = 12; $offset >= -36; $offset--) {
            $month = $current->addMonths($offset);
            $options[$month->format('Y-m')] = $month->translatedFormat('F Y');
        }

        return $options;
    }

    /**
     * @return list<mixed>
     */
    private function reportComponents(): array
    {
        if ($this->report === []) {
            return [];
        }

        return [
            $this->invoiceSection(),
            $this->arrearsSection(),
            $this->subsidySection(),
            $this->cashSection(),
        ];
    }

    private function invoiceSection(): Section
    {
        $summary = $this->report['invoices'];
        $totals = $summary['totals'];
        $isEmpty = $totals['invoice_count'] === 0;
        $periods = $summary['periods'];

        return Section::make(__('pondok.finance_report.sections.invoices'))
            ->description(__('pondok.finance_report.sections.invoices_description'))
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2, 'xl' => 4])->schema([
                    $this->stat('billed', __('pondok.finance_report.stats.billed'), self::rupiah($totals['billed'])),
                    $this->stat('paid', __('pondok.finance_report.stats.paid'), self::rupiah($totals['paid']))
                        ->belowContent(__('pondok.finance_report.stats.collection_rate', ['rate' => self::percent($totals['collection_rate'])])),
                    $this->stat('overdue', __('pondok.finance_report.stats.overdue'), self::rupiah($totals['overdue']))
                        ->belowContent(__('pondok.finance_report.stats.overdue_detail', [
                            'invoices' => $totals['overdue_invoice_count'],
                            'students' => $totals['overdue_student_count'],
                        ])),
                    $this->stat('not_yet_due', __('pondok.finance_report.stats.not_yet_due'), self::rupiah($totals['not_yet_due'])),
                ]),
                $this->emptyState(__('pondok.finance_report.empty_invoices'), Heroicon::OutlinedBanknotes)
                    ->visible($isEmpty),
                $this->table(
                    'by_status',
                    __('pondok.finance_report.sections.by_status'),
                    collect($totals['by_status'])->map(fn (array $row, string $status): array => [
                        'status' => __("pondok.invoice.statuses.{$status}"),
                        'count' => $row['count'],
                        'total' => self::rupiah($row['total']),
                        'paid' => self::rupiah($row['paid']),
                    ])->values()->all(),
                    [
                        'status' => [__('pondok.finance_report.columns.status'), false],
                        'count' => [__('pondok.finance_report.columns.invoice_count'), true],
                        'total' => [__('pondok.finance_report.columns.billed'), true],
                        'paid' => [__('pondok.finance_report.columns.paid'), true],
                    ],
                )->visible(! $isEmpty),
                $this->table(
                    'periods',
                    __('pondok.finance_report.sections.periods'),
                    array_map(fn (array $row): array => [
                        'period' => CarbonImmutable::createFromFormat('!Y-m', $row['period'], config('app.timezone'))->translatedFormat('F Y'),
                        'invoice_count' => $row['invoice_count'],
                        'billed' => self::rupiah($row['billed']),
                        'paid' => self::rupiah($row['paid']),
                        'outstanding' => self::rupiah($row['outstanding']),
                        'overdue' => self::rupiah($row['overdue']),
                        'collection_rate' => self::percent($row['collection_rate']),
                    ], $periods),
                    [
                        'period' => [__('pondok.finance_report.columns.period'), false],
                        'invoice_count' => [__('pondok.finance_report.columns.invoice_count'), true],
                        'billed' => [__('pondok.finance_report.columns.billed'), true],
                        'paid' => [__('pondok.finance_report.columns.paid'), true],
                        'outstanding' => [__('pondok.finance_report.columns.outstanding'), true],
                        'overdue' => [__('pondok.finance_report.columns.overdue'), true],
                        'collection_rate' => [__('pondok.finance_report.columns.collection_rate'), true],
                    ],
                )->visible(! $isEmpty && count($periods) > 1),
            ]);
    }

    private function arrearsSection(): Section
    {
        $arrears = $this->report['arrears'];
        $canOpenStudent = StudentResource::canEdit(new Student);

        return Section::make(__('pondok.finance_report.sections.arrears', [
            'date' => CarbonImmutable::parse($this->report['invoices']['as_of'])->translatedFormat('j M Y'),
        ]))
            ->description(__('pondok.finance_report.sections.arrears_description'))
            ->schema([
                $this->emptyState(__('pondok.finance_report.empty_arrears'), Heroicon::OutlinedCheckCircle)
                    ->visible($arrears === []),
                RepeatableEntry::make('arrears')
                    ->hiddenLabel()
                    ->state(array_map(fn (array $row): array => [
                        'nis' => $row['nis'],
                        'name' => $row['name'],
                        'archived' => $row['is_archived'] ? __('pondok.finance_report.archived') : null,
                        'url' => $canOpenStudent ? StudentResource::getUrl('edit', ['record' => $row['student_id']]) : null,
                        'unit' => $row['unit_name'],
                        'invoice_count' => $row['overdue_invoice_count'],
                        'oldest_period' => CarbonImmutable::createFromFormat('!Y-m', $row['oldest_period'], config('app.timezone'))->translatedFormat('M Y'),
                        'overdue_amount' => self::rupiah($row['overdue_amount']),
                    ], $arrears))
                    ->table([
                        TableColumn::make(__('pondok.finance_report.columns.nis')),
                        TableColumn::make(__('pondok.finance_report.columns.name')),
                        TableColumn::make(__('pondok.finance_report.columns.unit')),
                        TableColumn::make(__('pondok.finance_report.columns.invoice_count'))->alignment(Alignment::End),
                        TableColumn::make(__('pondok.finance_report.columns.oldest_period')),
                        TableColumn::make(__('pondok.finance_report.columns.overdue_amount'))->alignment(Alignment::End),
                    ])
                    ->schema([
                        TextEntry::make('nis')->placeholder('-'),
                        TextEntry::make('name')
                            ->url(fn (TextEntry $component): ?string => $component->getContainer()->getConstantState()['url'] ?? null)
                            ->belowContent(fn (TextEntry $component): ?string => $component->getContainer()->getConstantState()['archived'] ?? null),
                        TextEntry::make('unit')->placeholder('-'),
                        $this->number('invoice_count'),
                        TextEntry::make('oldest_period'),
                        $this->number('overdue_amount'),
                    ])
                    ->visible($arrears !== []),
            ]);
    }

    private function subsidySection(): Section
    {
        $subsidies = $this->report['subsidies'];
        $relief = $subsidies['non_scholarship_relief'];
        $isEmpty = $subsidies['programs'] === [] && $relief['item_count'] === 0;
        $noSponsor = __('pondok.finance_report.no_sponsor');

        $programs = array_map(fn (array $row): array => [
            'name' => $row['name'],
            'sponsor' => $row['sponsor'] ?? $noSponsor,
            'beneficiary_count' => $row['beneficiary_count'],
            'subsidy' => self::rupiah($row['subsidy']),
        ], $subsidies['programs']);
        $programs[] = [
            'name' => __('pondok.finance_report.total'),
            'sponsor' => null,
            'beneficiary_count' => null,
            'subsidy' => self::rupiah($subsidies['total_subsidy']),
        ];

        return Section::make(__('pondok.finance_report.sections.subsidies'))
            ->description(__('pondok.finance_report.sections.subsidies_description'))
            ->schema([
                $this->emptyState(__('pondok.finance_report.empty_subsidies'), Heroicon::OutlinedGift)
                    ->visible($isEmpty),
                $this->table('programs', __('pondok.finance_report.sections.programs'), $programs, [
                    'name' => [__('pondok.finance_report.columns.program'), false],
                    'sponsor' => [__('pondok.finance_report.columns.sponsor'), false],
                    'beneficiary_count' => [__('pondok.finance_report.columns.beneficiary_count'), true],
                    'subsidy' => [__('pondok.finance_report.columns.subsidy'), true],
                ])->visible($subsidies['programs'] !== []),
                $this->table('sponsors', __('pondok.finance_report.sections.sponsors'), array_map(fn (array $row): array => [
                    'sponsor' => $row['sponsor'] ?? $noSponsor,
                    'program_count' => $row['program_count'],
                    'beneficiary_count' => $row['beneficiary_count'],
                    'subsidy' => self::rupiah($row['subsidy']),
                ], $subsidies['sponsors']), [
                    'sponsor' => [__('pondok.finance_report.columns.sponsor'), false],
                    'program_count' => [__('pondok.finance_report.columns.program_count'), true],
                    'beneficiary_count' => [__('pondok.finance_report.columns.beneficiary_count'), true],
                    'subsidy' => [__('pondok.finance_report.columns.subsidy'), true],
                ])->visible($subsidies['sponsors'] !== []),
                Grid::make(['default' => 1, 'sm' => 2, 'xl' => 4])->schema([
                    $this->stat('non_scholarship', __('pondok.finance_report.non_scholarship'), self::rupiah($relief['amount']))
                        ->belowContent(__('pondok.finance_report.item_count_detail', ['count' => $relief['item_count']])),
                ])->visible(! $isEmpty),
            ]);
    }

    private function cashSection(): Section
    {
        $cash = $this->report['cash'];
        $isEmpty = $cash['payment_count'] === 0;

        return Section::make(__('pondok.finance_report.sections.cash'))
            ->description(__('pondok.finance_report.sections.cash_description'))
            ->schema([
                $this->emptyState(__('pondok.finance_report.empty_cash'), Heroicon::OutlinedBanknotes)
                    ->visible($isEmpty),
                Grid::make(['default' => 1, 'sm' => 2, 'xl' => 4])->schema([
                    $this->stat('cash_total', __('pondok.finance_report.cash_total'), self::rupiah($cash['total']))
                        ->belowContent(__('pondok.finance_report.cash_total_detail', ['count' => $cash['payment_count']])),
                ])->visible(! $isEmpty),
                $this->table('by_method', __('pondok.finance_report.sections.by_method'), collect($cash['by_method'])->map(fn (array $row, string $method): array => [
                    'method' => __("pondok.payment.methods.{$method}"),
                    'count' => $row['count'],
                    'amount' => self::rupiah($row['amount']),
                ])->values()->all(), [
                    'method' => [__('pondok.finance_report.columns.method'), false],
                    'count' => [__('pondok.finance_report.columns.payment_count'), true],
                    'amount' => [__('pondok.finance_report.columns.amount'), true],
                ])->visible(! $isEmpty),
                $this->table('by_fee_type', __('pondok.finance_report.sections.by_fee_type'), array_map(fn (array $row): array => [
                    'name' => $row['name'],
                    'amount' => self::rupiah($row['amount']),
                ], $cash['by_fee_type']), [
                    'name' => [__('pondok.finance_report.columns.fee_type'), false],
                    'amount' => [__('pondok.finance_report.columns.amount'), true],
                ])->visible($cash['by_fee_type'] !== []),
                $this->table('by_day', __('pondok.finance_report.sections.by_day'), array_map(fn (array $row): array => [
                    'date' => CarbonImmutable::parse($row['date'])->translatedFormat('j M Y'),
                    'count' => $row['count'],
                    'amount' => self::rupiah($row['amount']),
                ], $cash['by_day']), [
                    'date' => [__('pondok.finance_report.columns.date'), false],
                    'count' => [__('pondok.finance_report.columns.payment_count'), true],
                    'amount' => [__('pondok.finance_report.columns.amount'), true],
                ])->visible(! $isEmpty),
            ]);
    }

    private function stat(string $name, string $label, string $value): TextEntry
    {
        return TextEntry::make("stat_{$name}")
            ->label($label)
            ->state($value)
            ->size(TextSize::Large)
            ->weight(FontWeight::SemiBold)
            ->extraAttributes(['class' => 'tabular-nums']);
    }

    private function number(string $name): TextEntry
    {
        return TextEntry::make($name)
            ->alignEnd()
            ->placeholder('')
            ->extraAttributes(['class' => 'tabular-nums']);
    }

    private function emptyState(string $message, Heroicon $icon): EmptyState
    {
        return EmptyState::make($message)
            ->icon($icon)
            ->compact()
            ->contained(false);
    }

    /**
     * Tabel rekap dari baris array yang sudah diformat.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, array{0: string, 1: bool}>  $columns  kunci => [label, rata kanan]
     */
    private function table(string $name, string $label, array $rows, array $columns): RepeatableEntry
    {
        return RepeatableEntry::make("table_{$name}")
            ->label($label)
            ->state($rows)
            ->table(array_map(
                fn (array $column): TableColumn => TableColumn::make($column[0])->alignment($column[1] ? Alignment::End : Alignment::Start),
                array_values($columns),
            ))
            ->schema(array_map(
                fn (string $key, array $column): TextEntry => $column[1] ? $this->number($key) : TextEntry::make($key)->placeholder(''),
                array_keys($columns),
                $columns,
            ));
    }

    private static function rupiah(int $amount): string
    {
        return Payment::formatRupiah($amount);
    }

    private static function percent(?int $rate): string
    {
        return $rate === null ? '-' : "{$rate}%";
    }
}
