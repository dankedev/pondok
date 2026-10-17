<?php

namespace App\Filament\Pages\Site;

use App\Actions\Konten\GetPortalNavigation;
use App\Actions\Konten\UpdatePortalNavigation;
use App\Filament\Pages\SettingsFormPage;
use App\Filament\Pages\Site\Concerns\LinkTargetFields;
use App\Models\Setting;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Navigasi portal (F043): menu utama (dengan grup submenu satu tingkat) dan tombol header,
 * memakai pemilih tujuan yang sama. Tautan footer diatur lewat widget Tautan di halaman Footer (F046). Form diisi dari daftar
 * efektif (bawaan dimaterialisasi) dan disimpan lewat `UpdatePortalNavigation`, yang
 * memegang seluruh validasi; galat baris bersarang dipetakan oleh `SettingsFormPage`.
 */
class Navigation extends SettingsFormPage
{
    use LinkTargetFields;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'site/navigation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3;

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.situs');
    }

    protected static function pageKey(): string
    {
        return 'navigation';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('pondok.school_profile.section_primary_navigation'))
                    ->description(__('pondok.school_profile.primary_navigation_helper', ['max' => GetPortalNavigation::MAX_PRIMARY]))
                    ->schema([
                        $this->itemsRepeater('primary', GetPortalNavigation::MAX_PRIMARY, GetPortalNavigation::LABEL_MAX_MENU),
                    ]),
                Section::make(__('pondok.school_profile.section_header_button'))
                    ->description(__('pondok.school_profile.header_button_helper'))
                    ->schema([
                        Group::make($this->linkTargetFields(GetPortalNavigation::LABEL_MAX_MENU, [self::NO_BUTTON => __('pondok.school_profile.nav_no_button')]))
                            ->statePath('header_button')
                            ->columns(2),
                    ]),
            ]);
    }

    protected function formData(array $profile): array
    {
        $primary = Setting::get('portal.navigation_primary');
        $button = Setting::get('portal.header_button');

        $buttonItem = match (true) {
            $button === false => null,
            $button === null => ['type' => GetPortalNavigation::DEFAULT_HEADER_BUTTON, 'label' => null],
            default => is_array($button) ? GetPortalNavigation::parseItem($button, false) : null,
        };

        return [
            'primary' => $this->rows($primary === null ? GetPortalNavigation::materializedDefaultPrimary() : GetPortalNavigation::parseItems($primary, true)),
            'header_button' => $buttonItem === null ? ['type' => self::NO_BUTTON] : $this->row($buttonItem),
        ];
    }

    protected function persist(array $data, User $user): void
    {
        $button = $data['header_button'] ?? null;

        app(UpdatePortalNavigation::class)->handle([
            'primary' => $data['primary'] ?? [],
            'header_button' => ! is_array($button) || ($button['type'] ?? null) === self::NO_BUTTON ? false : $button,
        ], $user);

        $this->resetLinkTargetOptions();
    }

    private function itemsRepeater(string $name, int $max, int $labelMax): Repeater
    {
        $fields = $this->linkTargetFields($labelMax, ['group' => __('pondok.school_profile.nav_types.group')]);
        $fields[] = Repeater::make('children')
            ->label(__('pondok.school_profile.nav_children'))
            ->helperText(__('pondok.school_profile.nav_children_helper', ['max' => GetPortalNavigation::MAX_CHILDREN]))
            ->addActionLabel(__('pondok.school_profile.nav_add_child'))
            ->schema($this->linkTargetFields($labelMax))
            ->itemLabel(fn (array $state): string => $this->linkTargetName($state))
            ->reorderable()
            ->collapsible()
            ->columns(2)
            ->minItems(1)
            ->maxItems(GetPortalNavigation::MAX_CHILDREN)
            ->defaultItems(1)
            ->columnSpanFull()
            ->visible(fn (Get $get): bool => $get('type') === 'group');

        return Repeater::make($name)
            ->hiddenLabel()
            ->addActionLabel(__('pondok.school_profile.nav_add_item'))
            ->schema($fields)
            ->itemLabel(fn (array $state): string => $this->linkTargetName($state))
            ->reorderable()
            ->collapsible()
            ->columns(2)
            ->maxItems($max)
            ->defaultItems(0);
    }

    /**
     * Item tersimpan → baris form (semua field ada agar field kondisional terisi).
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function rows(array $items): array
    {
        return array_map(fn (array $item): array => $this->row($item), $items);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function row(array $item): array
    {
        return [
            ...$this->linkTargetRow($item),
            'children' => isset($item['children']) ? $this->rows($item['children']) : [],
        ];
    }
}
