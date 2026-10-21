<?php

namespace App\Filament\Pages\Site;

use App\Actions\Konten\GetPortalTheme;
use App\Actions\Konten\UpdatePortalTheme;
use App\Filament\Pages\SettingsFormPage;
use App\Models\Setting;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Radio;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Tampilan portal (F070): warna utama dan aksen, pasangan font, dan sudut kartu situs publik.
 * Disimpan lewat `UpdatePortalTheme` (validasi hex, opsi, dan kontras skala); panel admin tidak
 * ikut berubah. Form diisi dari key `theme.*` mentah; kosong berarti bawaan.
 */
class Theme extends SettingsFormPage
{
    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'site/theme';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.situs');
    }

    protected static function pageKey(): string
    {
        return 'theme';
    }

    public function form(Schema $schema): Schema
    {
        $options = fn (string $group, array $keys): array => array_combine(
            $keys,
            array_map(fn (string $key): string => __("pondok.school_profile.theme.{$group}.{$key}"), $keys),
        );

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('pondok.school_profile.theme.section'))
                    ->description(__('pondok.school_profile.theme.section_description'))
                    ->columns(2)
                    ->schema([
                        ColorPicker::make('primary')
                            ->label(__('pondok.school_profile.theme.primary'))
                            ->helperText(__('pondok.school_profile.theme.color_helper'))
                            ->hex(),
                        ColorPicker::make('accent')
                            ->label(__('pondok.school_profile.theme.accent'))
                            ->helperText(__('pondok.school_profile.theme.color_helper'))
                            ->hex(),
                        Radio::make('font')
                            ->label(__('pondok.school_profile.theme.font'))
                            ->options($options('fonts', array_keys(GetPortalTheme::FONTS))),
                        Radio::make('radius')
                            ->label(__('pondok.school_profile.theme.radius'))
                            ->options($options('radii', array_keys(GetPortalTheme::RADII))),
                    ]),
            ]);
    }

    protected function formData(array $profile): array
    {
        $values = Setting::getMany(GetPortalTheme::KEYS);
        $option = fn (mixed $value, array $options, string $default): string => is_string($value) && array_key_exists($value, $options) ? $value : $default;

        return [
            'primary' => GetPortalTheme::isHex($values['theme.primary']) ? $values['theme.primary'] : null,
            'accent' => GetPortalTheme::isHex($values['theme.accent']) ? $values['theme.accent'] : null,
            'font' => $option($values['theme.font'], GetPortalTheme::FONTS, GetPortalTheme::DEFAULT_FONT),
            'radius' => $option($values['theme.radius'], GetPortalTheme::RADII, GetPortalTheme::DEFAULT_RADIUS),
        ];
    }

    protected function persist(array $data, User $user): void
    {
        app(UpdatePortalTheme::class)->handle([
            'primary' => $data['primary'] ?? null,
            'accent' => $data['accent'] ?? null,
            'font' => $data['font'] ?? null,
            'radius' => $data['radius'] ?? null,
        ], $user);
    }
}
