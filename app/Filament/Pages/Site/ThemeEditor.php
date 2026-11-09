<?php

namespace App\Filament\Pages\Site;

use App\Actions\Konten\BuildColorScale;
use App\Actions\Konten\BuildThemePreviewUrl;
use App\Actions\Konten\DuplicatePortalTheme;
use App\Actions\Konten\GetPortalTheme;
use App\Actions\Konten\ListPortalThemes;
use App\Actions\Konten\ResolveThemePath;
use App\Actions\Konten\SaveEditedTheme;
use App\Actions\Konten\ThemeComponents;
use App\Actions\Konten\ThemeStyleMap;
use App\Actions\Konten\ValidatePortalTheme;
use App\Actions\Konten\ValidateThemeCss;
use App\Enums\Module;
use App\Models\Setting;
use App\Models\StaticPage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

/**
 * Editor tema v0 (F111): menyunting tema buatan sendiri `lokal-*` (nama, deskripsi, warna token, susunan beranda) dan menduplikat
 * tema lain menjadi `lokal-*`. Semua tulis lewat `SaveEditedTheme` dan `DuplicatePortalTheme`; halaman ini hanya form,
 * rasio kontras langsung (dihitung dari state form, tanpa menyentuh disk), dan tautan pratinjau bertanda tangan.
 * Hanya pemegang `manage:themes` dan hanya bila modul Konten aktif.
 *
 * @property-read Schema $form
 */
class ThemeEditor extends Page
{
    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'site/theme-editor';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaintBrush;

    protected string $view = 'filament.pages.site.theme-editor';

    /** Properti gaya skalar yang bisa diubah di form; `padding` dan `columns` (bisa responsif) dibiarkan apa adanya. */
    private const STYLE_PROPERTIES = ['tone', 'gap', 'align', 'width', 'radius', 'shadow', 'border'];

    public ?string $selectedSlug = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getModule(): string
    {
        return Module::Konten->value;
    }

    public static function canAccess(): bool
    {
        return Setting::moduleEnabled(static::getModule()) && (bool) auth()->user()?->can('manage:themes');
    }

    /**
     * Item selalu didaftarkan dan disaring lewat `visible()` di setiap render, supaya saklar modul yang berubah ikut berlaku
     * (sama dengan `ThemeCatalog`).
     */
    public static function registerNavigationItems(): void
    {
        if (static::shouldRegisterNavigation()) {
            Filament::getCurrentOrDefaultPanel()->navigationItems(static::getNavigationItems());
        }
    }

    /**
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
        return __('pondok.navigation.situs');
    }

    public static function getNavigationLabel(): string
    {
        return __('pondok.theme_editor.title');
    }

    public function getTitle(): string
    {
        return __('pondok.theme_editor.title');
    }

    public function getSubheading(): ?string
    {
        return __('pondok.theme_editor.description');
    }

    public function updatedSelectedSlug(): void
    {
        $this->loadTheme();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('duplicate')
                ->label(__('pondok.theme_editor.actions.duplicate'))
                ->icon(Heroicon::OutlinedDocumentDuplicate)
                ->color('gray')
                ->modalHeading(__('pondok.theme_editor.actions.duplicate'))
                ->modalDescription(__('pondok.theme_editor.actions.duplicate_description'))
                ->modalSubmitActionLabel(__('pondok.theme_editor.actions.duplicate_submit'))
                ->schema([
                    Select::make('source')
                        ->label(__('pondok.theme_editor.fields.source'))
                        ->options(fn (): array => collect(app(ListPortalThemes::class)->handle())->where('valid', true)->pluck('name', 'slug')->all())
                        ->required(),
                    TextInput::make('name')
                        ->label(__('pondok.theme_editor.fields.new_name'))
                        ->required()
                        ->maxLength(60),
                ])
                ->action(function (array $data): void {
                    abort_unless(static::canAccess(), 403);

                    try {
                        $slug = app(DuplicatePortalTheme::class)->handle((string) $data['source'], ['name' => $data['name']], auth()->user());
                    } catch (InvalidArgumentException $exception) {
                        $this->notifyFailed($exception);

                        return;
                    }

                    $this->selectedSlug = $slug;
                    $this->loadTheme();

                    Notification::make()->success()->title(__('pondok.theme_editor.notifications.duplicated', ['name' => $this->data['name'] ?? $slug]))->send();
                }),
        ];
    }

    /**
     * Nama lama `getForm()` (dipakai test dan Livewire v3) diarahkan ke schema `form`.
     */
    public function getForm(string $name): ?Schema
    {
        return $this->getSchema($name);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label(__('pondok.theme_editor.actions.save'))
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $hasCss = ($this->data['tokens'] ?? []) !== [];

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('pondok.theme_editor.sections.identity'))->columns(2)->schema([
                    TextInput::make('name')->label(__('pondok.theme_editor.fields.name'))->required()->maxLength(60),
                    Textarea::make('description')->label(__('pondok.theme_editor.fields.description'))->rows(2),
                ]),
                Section::make(__('pondok.theme_editor.sections.colors'))
                    ->description(__('pondok.theme_editor.sections.colors_description'))
                    ->columns(3)
                    ->visible($hasCss)
                    ->schema(array_map(
                        fn (string $token): ColorPicker => ColorPicker::make("tokens.{$token}")->label($token)->hex()->live(onBlur: true),
                        array_keys($this->data['tokens'] ?? []),
                    )),
                Section::make(__('pondok.theme_editor.sections.basics'))
                    ->description(__('pondok.theme_editor.sections.basics_description'))
                    ->columns(3)
                    ->visible(! $hasCss)
                    ->schema([
                        Select::make('palette')->label(__('pondok.theme_editor.fields.palette'))->options($this->options('palettes', ['navy', 'hijau'])),
                        Select::make('surface')->label(__('pondok.theme_editor.fields.surface'))->options($this->options('surfaces', ['putih', 'krem'])),
                        Select::make('font')->label(__('pondok.theme_editor.fields.font'))->options($this->options('fonts', array_keys(GetPortalTheme::FONTS), 'pondok.school_profile.theme.fonts')),
                    ]),
                Section::make(__('pondok.theme_editor.sections.layout'))
                    ->description(__('pondok.theme_editor.sections.layout_description'))
                    ->schema([
                        Repeater::make('layout')
                            ->hiddenLabel()
                            ->itemLabel(fn (array $state): ?string => isset($state['component']) ? __("pondok.theme_editor.components.{$state['component']}") : null)
                            ->collapsible()
                            ->collapsed()
                            ->columns(3)
                            ->addActionLabel(__('pondok.theme_editor.actions.add_section'))
                            ->schema([
                                Select::make('component')
                                    ->label(__('pondok.theme_editor.fields.component'))
                                    ->options($this->options('components', ThemeComponents::SLOTS['home']))
                                    ->required()
                                    ->live(),
                                Select::make('variant')
                                    ->label(__('pondok.theme_editor.fields.variant'))
                                    ->options(fn (Get $get): array => $this->options('options', ThemeComponents::VARIANTS[$get('component')] ?? []))
                                    ->visible(fn (Get $get): bool => isset(ThemeComponents::VARIANTS[$get('component')])),
                                ...array_map(
                                    fn (string $property): Select => Select::make("style.{$property}")
                                        ->label(__("pondok.theme_editor.style.{$property}"))
                                        ->options($this->options('options', ThemeStyleMap::values($property)))
                                        ->placeholder(__('pondok.theme_editor.style.default')),
                                    self::STYLE_PROPERTIES,
                                ),
                            ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        if ($this->selectedSlug === null) {
            return;
        }

        // Tanpa perubahan: jangan menaikkan versi dan menimpa previous.json.
        if ($this->data === $this->formState((array) $this->manifest())) {
            Notification::make()->title(__('pondok.theme_editor.notifications.unchanged'))->send();

            return;
        }

        $input = collect($this->data)->only(['name', 'description', 'palette', 'surface', 'font'])->filter(fn (mixed $value): bool => $value !== null)->all();

        if (($this->data['tokens'] ?? []) !== []) {
            $input['tokens'] = $this->data['tokens'];
            unset($input['palette'], $input['surface'], $input['font']);
        }

        $layout = $this->cleanLayout($this->data['layout'] ?? []);

        // Layout hanya dikirim bila berubah: node bawaan bisa berisi gaya responsif yang ditolak Action untuk disunting.
        if ($layout !== $this->cleanLayout($this->manifest()['home']['layout'] ?? [])) {
            $input['layout'] = $layout;
        }

        try {
            app(SaveEditedTheme::class)->handle($this->selectedSlug, $input, auth()->user());
        } catch (InvalidArgumentException $exception) {
            $this->notifyFailed($exception);

            return;
        }

        $this->loadTheme();

        Notification::make()->success()->title(__('pondok.theme_editor.notifications.saved'))->send();
    }

    /**
     * Rasio kontras tiap pasangan `assets.contrast` dari token di form saat ini (belum disimpan).
     *
     * @return list<array{fg: string, bg: string, size: string, ratio: float, passes: bool}>
     */
    public function contrastRows(): array
    {
        $tokens = $this->data['tokens'] ?? [];
        $rows = [];

        foreach ($this->manifest()['assets']['contrast'] ?? [] as $pair) {
            if (! isset($tokens[$pair['fg']], $tokens[$pair['bg']]) || ! GetPortalTheme::isHex($tokens[$pair['fg']]) || ! GetPortalTheme::isHex($tokens[$pair['bg']])) {
                continue;
            }

            $ratio = BuildColorScale::contrast($tokens[$pair['fg']], $tokens[$pair['bg']]);

            $rows[] = ['fg' => $pair['fg'], 'bg' => $pair['bg'], 'size' => $pair['size'], 'ratio' => $ratio, 'passes' => $ratio >= ($pair['size'] === 'large' ? 3.0 : 4.5)];
        }

        return $rows;
    }

    /**
     * Pasangan yang gagal dipisah dari yang lulus agar yang perlu diperbaiki tampil lebih dulu.
     *
     * @param  list<array{fg: string, bg: string, size: string, ratio: float, passes: bool}>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    public function splitContrastRows(array $rows): array
    {
        return [
            array_values(array_filter($rows, fn (array $row): bool => ! $row['passes'])),
            array_values(array_filter($rows, fn (array $row): bool => $row['passes'])),
        ];
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    public function previewLinks(): array
    {
        if ($this->selectedSlug === null) {
            return [];
        }

        $build = app(BuildThemePreviewUrl::class);
        $links = [
            ['label' => __('pondok.theme_editor.preview.home'), 'url' => $build->handle($this->selectedSlug)],
            ['label' => __('pondok.theme_editor.preview.posts'), 'url' => $build->handle($this->selectedSlug, 'portal.posts.index')],
            ['label' => __('pondok.theme_editor.preview.albums'), 'url' => $build->handle($this->selectedSlug, 'portal.albums.index')],
        ];

        if ($page = StaticPage::published()->orderBy('id')->first()) {
            $links[] = ['label' => __('pondok.theme_editor.preview.page', ['title' => $page->title]), 'url' => $build->handle($this->selectedSlug, 'portal.pages.show', ['page' => $page->slug])];
        }

        return $links;
    }

    /**
     * Tema `lokal-*` terpasang untuk dipilih; kosong bila belum ada (state kosong di view).
     *
     * @return array<string, string>
     */
    public function localThemes(): array
    {
        return collect(app(ListPortalThemes::class)->handle())->where('source', 'local')->where('valid', true)->pluck('name', 'slug')->all();
    }

    private function loadTheme(): void
    {
        $this->data = [];
        $manifest = $this->manifest();

        if ($manifest === null) {
            $this->selectedSlug = null;

            return;
        }

        $this->data = $this->formState($manifest);
    }

    public function isActiveTheme(): bool
    {
        return app(GetPortalTheme::class)->template() === $this->selectedSlug;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function formState(array $manifest): array
    {
        return [
            'name' => $manifest['name'],
            'description' => $manifest['description'] ?? '',
            'palette' => $manifest['palette'] ?? null,
            'surface' => $manifest['surface'] ?? null,
            'font' => $manifest['font'] ?? null,
            'tokens' => $this->editableTokens($manifest),
            'layout' => array_values($manifest['home']['layout'] ?? []),
        ];
    }

    /**
     * Manifest tervalidasi tema `lokal-*` terpilih, atau null (bukan lokal, tidak terpasang, atau tidak valid).
     *
     * @return array<string, mixed>|null
     */
    private function manifest(): ?array
    {
        if ($this->selectedSlug === null || ! ResolveThemePath::isLocal($this->selectedSlug)) {
            return null;
        }

        try {
            $path = app(ResolveThemePath::class)->handle($this->selectedSlug);

            return $path === null ? null : app(ValidatePortalTheme::class)->handle($path);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Token hex yang didefinisikan sekali di blok scope CSS tema (yang bisa diubah `SaveEditedTheme`), peta `--nama` => `#rrggbb`.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, string>
     */
    private function editableTokens(array $manifest): array
    {
        if (! isset($manifest['assets']['css'])) {
            return [];
        }

        $path = rtrim((string) config('pondok.installed_themes_path'), '/')."/{$manifest['slug']}";
        $css = @file_get_contents("{$path}/assets/".substr((string) hash_file('sha256', "{$path}/theme.json"), 0, 12).'/theme.css');

        if ($css === false) {
            return [];
        }

        $scope = 'body[data-pt-theme="'.$manifest['slug'].'"]';
        $counts = [];
        $values = [];

        foreach (app(ValidateThemeCss::class)->handle($css, $manifest['slug'])['rules'] as $rule) {
            if (! $rule['top'] || $rule['selectors'] !== [$scope]) {
                continue;
            }

            foreach ($rule['declarations'] as [$property, $value]) {
                if (str_starts_with($property, '--')) {
                    $counts[$property] = ($counts[$property] ?? 0) + 1;
                    $values[$property] = $value;
                }
            }
        }

        return array_filter($values, fn (string $value, string $name): bool => $counts[$name] === 1 && GetPortalTheme::isHex($value), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Daftar node puncak tanpa kunci uuid Repeater, tanpa gaya kosong, dan dengan gaya responsif dipangkas ke `base`, supaya bisa dibandingkan dengan manifest.
     *
     * @param  array<array-key, mixed>  $layout
     * @return list<array<string, mixed>>
     */
    private function cleanLayout(array $layout): array
    {
        return array_map(function (array $node): array {
            // Action hanya menerima gaya skalar atau `{base}` di node puncak; nilai responsif dipangkas ke `base` (layar kecil).
            $node['style'] = array_filter(
                array_map(fn (mixed $value): mixed => is_array($value) ? (isset($value['base']) ? ['base' => $value['base']] : null) : $value, $node['style'] ?? []),
                fn (mixed $value): bool => $value !== null && $value !== '',
            );

            return array_filter($node, fn (mixed $value, string $key): bool => $value !== null && $value !== '' && ! ($key === 'style' && $value === []), ARRAY_FILTER_USE_BOTH);
        }, array_values($layout));
    }

    /**
     * @param  list<string|int>  $keys
     * @return array<string|int, string>
     */
    private function options(string $group, array $keys, string $base = 'pondok.theme_editor'): array
    {
        return array_combine($keys, array_map(fn (string|int $key): string => __("{$base}.{$group}.{$key}"), $keys)) ?: [];
    }

    private function notifyFailed(InvalidArgumentException $exception): void
    {
        Notification::make()->danger()->title(__('pondok.theme_editor.notifications.failed'))->body($exception->getMessage())->send();
    }
}
