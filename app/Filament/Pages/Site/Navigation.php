<?php

namespace App\Filament\Pages\Site;

use App\Actions\Konten\GetPortalNavigation;
use App\Actions\Konten\UpdatePortalNavigation;
use App\Filament\Pages\SettingsFormPage;
use App\Models\Category;
use App\Models\Setting;
use App\Models\StaticPage;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\StateCasts\NumberStateCast;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Navigasi portal (F043): menu utama (dengan grup submenu satu tingkat), tombol header,
 * dan navigasi footer, semuanya memakai pemilih tujuan yang sama. Form diisi dari daftar
 * efektif (bawaan dimaterialisasi) dan disimpan lewat `UpdatePortalNavigation`, yang
 * memegang seluruh validasi; galat baris bersarang dipetakan oleh `SettingsFormPage`.
 */
class Navigation extends SettingsFormPage
{
    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'site/navigation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3;

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.situs');
    }

    /** Nilai pemilih tombol header untuk "Tanpa tombol" (disimpan `false`). */
    public const NO_BUTTON = 'none';

    /** @var array<int, string>|null */
    private ?array $pageOptions = null;

    /** @var array<int, string>|null */
    private ?array $categoryOptions = null;

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
                        $this->itemsRepeater('primary', GetPortalNavigation::MAX_PRIMARY, GetPortalNavigation::LABEL_MAX_MENU, withGroups: true),
                    ]),
                Section::make(__('pondok.school_profile.section_header_button'))
                    ->description(__('pondok.school_profile.header_button_helper'))
                    ->schema([
                        Group::make($this->itemFields(GetPortalNavigation::LABEL_MAX_MENU, [self::NO_BUTTON => __('pondok.school_profile.nav_no_button')]))
                            ->statePath('header_button')
                            ->columns(2),
                    ]),
                Section::make(__('pondok.school_profile.section_footer_navigation'))
                    ->description(__('pondok.school_profile.footer_navigation_helper', ['max' => GetPortalNavigation::MAX_FOOTER]))
                    ->schema([
                        $this->itemsRepeater('footer', GetPortalNavigation::MAX_FOOTER, GetPortalNavigation::LABEL_MAX_FOOTER),
                    ]),
            ]);
    }

    protected function formData(array $profile): array
    {
        $primary = Setting::get('portal.navigation_primary');
        $footer = Setting::get('portal.navigation_footer');
        $button = Setting::get('portal.header_button');

        $buttonItem = match (true) {
            $button === false => null,
            $button === null => ['type' => GetPortalNavigation::DEFAULT_HEADER_BUTTON, 'label' => null],
            default => is_array($button) ? GetPortalNavigation::parseItem($button, false) : null,
        };

        return [
            'primary' => $this->rows($primary === null ? GetPortalNavigation::materializedDefaultPrimary() : GetPortalNavigation::parseItems($primary, true)),
            'footer' => $this->rows($footer === null
                ? array_map(fn (string $type): array => ['type' => $type, 'label' => null], GetPortalNavigation::DEFAULT_FOOTER)
                : GetPortalNavigation::parseItems($footer, false)),
            'header_button' => $buttonItem === null ? ['type' => self::NO_BUTTON] : $this->row($buttonItem),
        ];
    }

    protected function persist(array $data, User $user): void
    {
        $button = $data['header_button'] ?? null;

        app(UpdatePortalNavigation::class)->handle([
            'primary' => $data['primary'] ?? [],
            'footer' => $data['footer'] ?? [],
            'header_button' => ! is_array($button) || ($button['type'] ?? null) === self::NO_BUTTON ? false : $button,
        ], $user);

        $this->pageOptions = null;
        $this->categoryOptions = null;
    }

    private function itemsRepeater(string $name, int $max, int $labelMax, bool $withGroups = false): Repeater
    {
        $fields = $this->itemFields($labelMax, $withGroups ? ['group' => __('pondok.school_profile.nav_types.group')] : []);

        if ($withGroups) {
            $fields[] = Repeater::make('children')
                ->label(__('pondok.school_profile.nav_children'))
                ->helperText(__('pondok.school_profile.nav_children_helper', ['max' => GetPortalNavigation::MAX_CHILDREN]))
                ->addActionLabel(__('pondok.school_profile.nav_add_child'))
                ->schema($this->itemFields($labelMax))
                ->itemLabel(fn (array $state): string => $this->rowName($state))
                ->reorderable()
                ->collapsible()
                ->columns(2)
                ->minItems(1)
                ->maxItems(GetPortalNavigation::MAX_CHILDREN)
                ->defaultItems(1)
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => $get('type') === 'group');
        }

        return Repeater::make($name)
            ->hiddenLabel()
            ->addActionLabel(__('pondok.school_profile.nav_add_item'))
            ->schema($fields)
            ->itemLabel(fn (array $state): string => $this->rowName($state))
            ->reorderable()
            ->collapsible()
            ->columns(2)
            ->maxItems($max)
            ->defaultItems(0);
    }

    /**
     * Pemilih tujuan dan field kondisionalnya; `$extraTypes` menambah opsi di awal/akhir daftar.
     *
     * @param  array<string, string>  $extraTypes
     * @return list<Component>
     */
    private function itemFields(int $labelMax, array $extraTypes = []): array
    {
        $noButton = array_key_exists(self::NO_BUTTON, $extraTypes);

        return [
            Select::make('type')
                ->label(__('pondok.school_profile.nav_type'))
                ->options($noButton ? [...$extraTypes, ...$this->typeOptions()] : [...$this->typeOptions(), ...$extraTypes])
                ->required()
                ->native(false)
                ->live(),
            Select::make('page_id')
                ->label(__('pondok.school_profile.nav_page'))
                ->options(fn (): array => $this->pageOptions())
                ->required()
                ->stateCast(new NumberStateCast(isInteger: true))
                ->visible(fn (Get $get): bool => $get('type') === 'page'),
            Select::make('category_id')
                ->label(__('pondok.school_profile.nav_category'))
                ->options(fn (): array => $this->categoryOptions())
                ->required()
                ->stateCast(new NumberStateCast(isInteger: true))
                ->visible(fn (Get $get): bool => $get('type') === 'category'),
            TextInput::make('url')
                ->label(__('pondok.school_profile.nav_url'))
                ->helperText(__('pondok.school_profile.nav_url_helper'))
                ->required()
                ->maxLength(GetPortalNavigation::URL_MAX_LENGTH)
                // Sama dengan aturan Action; di sini agar semua baris yang salah tampil sekaligus.
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (filled($value) && ! GetPortalNavigation::isSafeTarget(trim((string) $value))) {
                        $fail(__('pondok.konten.errors.link_invalid'));
                    }
                })
                ->visible(fn (Get $get): bool => $get('type') === 'url'),
            TextInput::make('label')
                ->label(__('pondok.school_profile.nav_label'))
                ->helperText(fn (Get $get): string => in_array($get('type'), ['url', 'group'], true)
                    ? __('pondok.school_profile.nav_label_required_helper', ['max' => $labelMax])
                    : __('pondok.school_profile.nav_label_helper', ['max' => $labelMax]))
                ->required(fn (Get $get): bool => in_array($get('type'), ['url', 'group'], true))
                ->maxLength($labelMax)
                ->visible(fn (Get $get): bool => filled($get('type')) && $get('type') !== self::NO_BUTTON),
        ];
    }

    /**
     * Tujuan selain grup, label dari `lang/id`.
     *
     * @return array<string, string>
     */
    private function typeOptions(): array
    {
        $options = [];

        foreach (GetPortalNavigation::TYPES as $type) {
            if ($type !== 'group') {
                $options[$type] = __('pondok.school_profile.nav_types.'.str_replace('.', '_', $type));
            }
        }

        return $options;
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
            'type' => $item['type'],
            'label' => $item['label'] ?? null,
            'page_id' => $item['page_id'] ?? null,
            'category_id' => $item['category_id'] ?? null,
            'url' => $item['url'] ?? null,
            'children' => isset($item['children']) ? $this->rows($item['children']) : [],
        ];
    }

    /**
     * Judul baris repeater: label isian, atau nama tujuan (judul halaman, nama kategori).
     *
     * @param  array<string, mixed>  $state
     */
    private function rowName(array $state): string
    {
        $type = $state['type'] ?? null;

        if (filled($state['label'] ?? null)) {
            return (string) $state['label'];
        }

        return match ($type) {
            null, '' => __('pondok.school_profile.nav_new_item'),
            'page' => $this->pageOptions()[(int) ($state['page_id'] ?? 0)] ?? __('pondok.school_profile.nav_types.page'),
            'category' => $this->categoryOptions()[(int) ($state['category_id'] ?? 0)] ?? __('pondok.school_profile.nav_types.category'),
            default => __('pondok.school_profile.nav_types.'.str_replace('.', '_', (string) $type)),
        };
    }

    /**
     * Semua halaman urut judul; halaman draf diberi keterangan (tampil setelah terbit).
     *
     * @return array<int, string>
     */
    private function pageOptions(): array
    {
        return $this->pageOptions ??= StaticPage::query()->orderBy('title')->get(['id', 'title', 'status'])
            ->mapWithKeys(fn (StaticPage $page): array => [$page->id => $page->isPublished()
                ? $page->title
                : __('pondok.school_profile.nav_draft_page', ['title' => $page->title])])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function categoryOptions(): array
    {
        return $this->categoryOptions ??= Category::query()->ofType(Category::TYPE_POST)->orderBy('name')->pluck('name', 'id')->all();
    }
}
