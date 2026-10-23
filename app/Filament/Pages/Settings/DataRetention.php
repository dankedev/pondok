<?php

namespace App\Filament\Pages\Settings;

use App\Actions\Core\CreateBackup;
use App\Actions\Core\UpdateDataRetentionSettings;
use App\Enums\BackupFrequency;
use App\Models\Setting;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * Pengaturan → Backup & masa simpan (F042, hanya super-admin seperti `Modules`):
 * saklar dan frekuensi backup terjadwal (F085), info arsip terakhir, masa simpan snapshot surat, dan
 * masa simpan log aktivitas (F076).
 * Disimpan lewat `UpdateDataRetentionSettings`. Unduh arsip sengaja tidak
 * disediakan di panel; arsip diambil lewat File Manager hosting.
 */
class DataRetention extends NumberingSettingsPage
{
    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'settings/data-retention';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.pengaturan');
    }

    public static function getNavigationLabel(): string
    {
        return __('pondok.data_retention.title');
    }

    public function getTitle(): string
    {
        return __('pondok.data_retention.title');
    }

    /**
     * Akses berdasarkan role, bukan permission page Shield (pola `Modules`).
     */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('super-admin');
    }

    protected static function pagePermission(): string
    {
        return 'view:data-retention';
    }

    protected function savedMessage(): string
    {
        return __('pondok.data_retention.saved');
    }

    public function form(Schema $schema): Schema
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('pondok.data_retention.backup_heading'))
                    ->description(__('pondok.data_retention.backup_description'))
                    ->schema(array_values(array_filter([
                        $pgsql ? Callout::make(__('pondok.backup.pgsql_unsupported'))->warning() : null,
                        Toggle::make('backup_enabled')
                            ->label(__('pondok.data_retention.backup_enabled'))
                            ->helperText(__('pondok.data_retention.backup_enabled_helper'))
                            ->disabled($pgsql),
                        Radio::make('backup_frequency')
                            ->label(__('pondok.data_retention.backup_frequency'))
                            ->options(collect(BackupFrequency::cases())->mapWithKeys(fn (BackupFrequency $frequency): array => [$frequency->value => $frequency->label()]))
                            ->descriptions(collect(BackupFrequency::cases())->mapWithKeys(fn (BackupFrequency $frequency): array => [$frequency->value => $frequency->description()]))
                            ->helperText(__('pondok.data_retention.backup_frequency_helper', ['keep' => CreateBackup::KEEP]))
                            ->required()
                            ->disabled($pgsql),
                        Text::make(fn (): string => $this->latestBackup()),
                        Text::make(__('pondok.data_retention.backup_hint')),
                    ]))),
                Section::make(__('pondok.data_retention.letter_heading'))
                    ->description(__('pondok.data_retention.letter_description'))
                    ->schema([
                        TextInput::make('letter_snapshot_years')
                            ->label(__('pondok.data_retention.letter_snapshot_years'))
                            ->helperText(__('pondok.data_retention.letter_snapshot_years_helper'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(UpdateDataRetentionSettings::MAX_YEARS)
                            ->suffix(__('pondok.data_retention.years_suffix'))
                            ->nullable()
                            ->extraInputAttributes(['class' => 'tabular-nums']),
                    ]),
                Section::make(__('pondok.data_retention.activity_log_heading'))
                    ->description(__('pondok.data_retention.activity_log_description'))
                    ->schema([
                        TextInput::make('activity_log_months')
                            ->label(__('pondok.data_retention.activity_log_months'))
                            ->helperText(__('pondok.data_retention.activity_log_months_helper'))
                            ->numeric()
                            ->integer()
                            ->minValue(UpdateDataRetentionSettings::MIN_ACTIVITY_LOG_MONTHS)
                            ->maxValue(UpdateDataRetentionSettings::MAX_ACTIVITY_LOG_MONTHS)
                            ->suffix(__('pondok.data_retention.months_suffix'))
                            ->nullable()
                            ->extraInputAttributes(['class' => 'tabular-nums']),
                    ]),
            ]);
    }

    /**
     * @return array{backup_enabled: bool, backup_frequency: string, letter_snapshot_years: mixed, activity_log_months: mixed}
     */
    protected function fillData(): array
    {
        return [
            'backup_enabled' => (bool) Setting::get('backup.enabled', false),
            'backup_frequency' => BackupFrequency::current()->value,
            'letter_snapshot_years' => Setting::get('retention.letter_snapshot_years'),
            'activity_log_months' => Setting::get('retention.activity_log_months'),
        ];
    }

    /**
     * Field yang nonaktif (PostgreSQL) tidak ikut state form: toggle selalu mati,
     * frekuensi tidak dikirim sehingga nilai lama dipertahankan.
     *
     * @param  array<string, mixed>  $data
     */
    protected function persist(array $data, User $user): void
    {
        app(UpdateDataRetentionSettings::class)->handle([
            'backup_enabled' => (bool) ($data['backup_enabled'] ?? false),
            'letter_snapshot_years' => filled($data['letter_snapshot_years'] ?? null) ? $data['letter_snapshot_years'] : null,
            'activity_log_months' => filled($data['activity_log_months'] ?? null) ? $data['activity_log_months'] : null,
            ...filled($data['backup_frequency'] ?? null) ? ['backup_frequency' => $data['backup_frequency']] : [],
        ], $user);
    }

    private function latestBackup(): string
    {
        $path = CreateBackup::archives()->first();

        if ($path === null) {
            return __('pondok.backup.none_yet');
        }

        $disk = Storage::disk(CreateBackup::DISK);

        return __('pondok.backup.latest', [
            'file' => basename($path),
            'size' => Number::fileSize($disk->size($path), precision: 1),
            'datetime' => Carbon::createFromTimestamp($disk->lastModified($path), 'Asia/Jakarta')->locale('id')->translatedFormat('j M Y, H.i'),
        ]);
    }
}
