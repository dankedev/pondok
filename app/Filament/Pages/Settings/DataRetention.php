<?php

namespace App\Filament\Pages\Settings;

use App\Actions\Core\CreateBackup;
use App\Actions\Core\UpdateDataRetentionSettings;
use App\Models\Setting;
use App\Models\User;
use BackedEnum;
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
 * saklar backup terjadwal, info arsip terakhir, dan masa simpan snapshot surat.
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
                            ->helperText(__('pondok.data_retention.backup_enabled_helper', ['keep' => CreateBackup::KEEP]))
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
            ]);
    }

    /**
     * @return array{backup_enabled: bool, letter_snapshot_years: mixed}
     */
    protected function fillData(): array
    {
        return [
            'backup_enabled' => (bool) Setting::get('backup.enabled', false),
            'letter_snapshot_years' => Setting::get('retention.letter_snapshot_years'),
        ];
    }

    /**
     * Toggle yang nonaktif (PostgreSQL) tidak ikut state form; nilainya selalu mati.
     *
     * @param  array<string, mixed>  $data
     */
    protected function persist(array $data, User $user): void
    {
        app(UpdateDataRetentionSettings::class)->handle([
            'backup_enabled' => (bool) ($data['backup_enabled'] ?? false),
            'letter_snapshot_years' => filled($data['letter_snapshot_years'] ?? null) ? $data['letter_snapshot_years'] : null,
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
