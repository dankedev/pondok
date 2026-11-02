<?php

namespace App\Filament\Pages\Site;

use App\Actions\Konten\BuildColorScale;
use App\Actions\Konten\GetPortalTheme;
use App\Actions\Konten\ListPortalThemes;
use App\Actions\Konten\UpdatePortalTheme;
use App\Filament\Pages\SettingsFormPage;
use App\Models\Setting;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Radio;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Tampilan portal (F070): warna utama dan aksen, pasangan font, dan sudut kartu situs publik,
 * serta tema portal (F072, F102: daftar dari `ListPortalThemes`). Pratinjau warna tombol dan judul portal (F071) dihitung dari
 * state form lewat `GetPortalTheme::brandTokens()`, sama dengan portal.
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

        $themes = collect(app(ListPortalThemes::class)->handle())->keyBy('slug');

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('pondok.school_profile.theme.section'))
                    ->description(__('pondok.school_profile.theme.section_description'))
                    ->columns(2)
                    ->schema([
                        Radio::make('template')
                            ->label(__('pondok.school_profile.theme.template'))
                            ->helperText(__('pondok.school_profile.theme.template_helper'))
                            ->options($themes->map->name->all())
                            ->descriptions($themes->map(fn (array $theme): string => $theme['valid']
                                ? $theme['description']
                                : __('pondok.school_profile.theme.template_unavailable', ['reason' => $theme['error']]))->all())
                            ->disableOptionWhen(fn (string $value): bool => ! ($themes[$value]['valid'] ?? false))
                            ->columnSpanFull(),
                        ColorPicker::make('primary')
                            ->label(__('pondok.school_profile.theme.primary'))
                            ->helperText(__('pondok.school_profile.theme.color_helper'))
                            ->hex()
                            ->live(onBlur: true),
                        ColorPicker::make('accent')
                            ->label(__('pondok.school_profile.theme.accent'))
                            ->helperText(__('pondok.school_profile.theme.color_helper'))
                            ->hex()
                            ->live(onBlur: true),
                        View::make('filament.pages.site.theme-brand-preview')
                            ->viewData(fn (Get $get): array => ['brand' => self::brandPreview($get('primary'), $get('accent'))])
                            ->visible(fn (Get $get): bool => GetPortalTheme::isHex($get('primary')))
                            ->columnSpanFull(),
                        Radio::make('font')
                            ->label(__('pondok.school_profile.theme.font'))
                            ->options($options('fonts', array_keys(GetPortalTheme::FONTS))),
                        Radio::make('radius')
                            ->label(__('pondok.school_profile.theme.radius'))
                            ->options($options('radii', array_keys(GetPortalTheme::RADII))),
                    ]),
            ]);
    }

    /**
     * Warna merek portal untuk pratinjau (F071), dihitung sama seperti portal; null bila portal menolak warna ini.
     *
     * @return array{brand: string, brand_hover: string, on_brand: string, brand_text: string, fill_adjusted: bool, text_adjusted: bool}|null
     */
    private static function brandPreview(mixed $primary, mixed $accent): ?array
    {
        $accentScale = GetPortalTheme::isHex($accent) ? app(BuildColorScale::class)->handle($accent, 'accent') : null;

        return GetPortalTheme::brandTokens($primary, $accentScale);
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
            'template' => $this->selectableTheme($values['theme.template']),
        ];
    }

    /**
     * Slug tersimpan bila masih tema valid; selain itu bawaan (portal juga jatuh ke chrome inti).
     */
    private function selectableTheme(mixed $slug): string
    {
        $valid = collect(app(ListPortalThemes::class)->handle())->where('valid', true)->pluck('slug');

        return is_string($slug) && $valid->contains($slug) ? $slug : GetPortalTheme::DEFAULT_TEMPLATE;
    }

    protected function persist(array $data, User $user): void
    {
        // Radio mengubah kunci opsi angka-saja (slug "12345") jadi int; slug tersimpan harus tetap string.
        $template = is_int($data['template'] ?? null) ? (string) $data['template'] : ($data['template'] ?? null);

        app(UpdatePortalTheme::class)->handle([
            'primary' => $data['primary'] ?? null,
            'accent' => $data['accent'] ?? null,
            'font' => $data['font'] ?? null,
            'radius' => $data['radius'] ?? null,
            'template' => $template,
        ], $user);
    }
}
