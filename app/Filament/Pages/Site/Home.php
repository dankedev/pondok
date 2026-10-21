<?php

namespace App\Filament\Pages\Site;

use App\Actions\Konten\GetPortalNavigation;
use App\Actions\Konten\NormalizeContentBlocks;
use App\Actions\Konten\ParseContentBlocks;
use App\Actions\Konten\StorePublicImage;
use App\Actions\Konten\UpdatePortalHome;
use App\Filament\Pages\SettingsFormPage;
use App\Filament\Pages\Site\Concerns\LinkTargetFields;
use App\Models\Album;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\StateCasts\NumberStateCast;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Beranda portal (F045): daftar blok yang disusun admin dengan Builder. State tiap blok
 * `{type, data: {visible, ...}}`; saat simpan `visible` dipindah ke tingkat blok dan seluruh
 * validasi dipegang `UpdatePortalHome`. Form diisi lewat `ParseContentBlocks` (blok tersembunyi
 * ikut), album/video yang sudah dihapus dikosongkan. Pilihan album hero hanya tampil saat modul
 * konten hidup, tetapi nilainya tetap ikut disimpan.
 */
class Home extends SettingsFormPage
{
    use LinkTargetFields;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'site/home';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    /** Field Item tombol per tipe blok. */
    private const BUTTONS = ['primary', 'secondary', 'button'];

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.situs');
    }

    protected static function pageKey(): string
    {
        return 'home';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Builder::make('home_blocks')
                    ->label(__('pondok.school_profile.blocks.builder'))
                    ->helperText(__('pondok.school_profile.blocks.builder_helper', ['max' => NormalizeContentBlocks::MAX_BLOCKS]))
                    ->addActionLabel(__('pondok.school_profile.blocks.add'))
                    ->blocks($this->blocks())
                    ->maxItems(NormalizeContentBlocks::MAX_BLOCKS)
                    ->deleteAction(fn (Action $action): Action => $action->requiresConfirmation())
                    ->reorderable()
                    ->collapsible()
                    ->blockIcons()
                    ->blockPickerColumns(2),
            ]);
    }

    /**
     * @return list<Block>
     */
    private function blocks(): array
    {
        $label = fn (string $type): Closure => fn (?array $state): string => collect([
            __("pondok.school_profile.blocks.types.{$type}"),
            $state['title'] ?? $state['heading'] ?? null,
        ])->filter(fn (mixed $part): bool => is_string($part) && trim($part) !== '')->implode(': ');

        return [
            Block::make('hero')
                ->label($label('hero'))
                ->icon(Heroicon::OutlinedStar)
                ->maxItems(1)
                ->columns(2)
                ->schema([
                    $this->visibleToggle(),
                    TextInput::make('eyebrow')
                        ->label(__('pondok.school_profile.blocks.eyebrow'))
                        ->helperText(__('pondok.school_profile.blocks.eyebrow_helper'))
                        ->maxLength(100),
                    TextInput::make('title')
                        ->label(__('pondok.school_profile.blocks.hero_title'))
                        ->helperText(__('pondok.school_profile.blocks.hero_title_helper'))
                        ->maxLength(150),
                    Textarea::make('text')
                        ->label(__('pondok.school_profile.blocks.text'))
                        ->helperText(__('pondok.school_profile.blocks.hero_text_helper'))
                        ->rows(2)
                        ->maxLength(300)
                        ->columnSpanFull(),
                    ...$this->imageFields(),
                    Select::make('album_id')
                        ->label(__('pondok.school_profile.blocks.album'))
                        ->helperText(__('pondok.school_profile.blocks.album_helper'))
                        ->options(fn (): array => Album::query()->orderBy('title')->pluck('title', 'id')->all())
                        ->stateCast(new NumberStateCast(isInteger: true))
                        ->searchable()
                        ->visible(fn (): bool => Setting::moduleEnabled('konten'))
                        ->dehydratedWhenHidden(),
                    $this->buttonField('primary', 'primary'),
                    $this->buttonField('secondary', 'secondary'),
                ]),
            Block::make('text_image')
                ->label($label('text_image'))
                ->icon(Heroicon::OutlinedPhoto)
                ->columns(2)
                ->schema([
                    $this->visibleToggle(),
                    $this->headingField(),
                    RichEditor::make('text')
                        ->label(__('pondok.school_profile.blocks.text'))
                        ->helperText(__('pondok.school_profile.blocks.limited_text_helper'))
                        ->toolbarButtons(['bold', 'link'])
                        ->columnSpanFull(),
                    ...$this->imageFields(),
                    $this->buttonField('button', 'button'),
                ]),
            Block::make('features')
                ->label($label('features'))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->schema([
                    $this->visibleToggle(),
                    $this->headingField(),
                    Repeater::make('items')
                        ->label(__('pondok.school_profile.blocks.features_items'))
                        ->helperText(__('pondok.school_profile.blocks.features_items_helper', ['max' => NormalizeContentBlocks::MAX_FEATURES]))
                        ->addActionLabel(__('pondok.school_profile.blocks.add_feature'))
                        ->schema([
                            TextInput::make('title')
                                ->label(__('pondok.school_profile.blocks.feature_title'))
                                ->required()
                                ->maxLength(80),
                            Textarea::make('text')
                                ->label(__('pondok.school_profile.blocks.feature_text'))
                                ->rows(2)
                                ->maxLength(300),
                        ])
                        ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                        ->reorderable()
                        ->collapsible()
                        ->minItems(1)
                        ->maxItems(NormalizeContentBlocks::MAX_FEATURES)
                        ->defaultItems(1),
                ]),
            Block::make('quote')
                ->label($label('quote'))
                ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)
                ->schema([
                    $this->visibleToggle(),
                    Textarea::make('text')
                        ->label(__('pondok.school_profile.blocks.quote_text'))
                        ->required()
                        ->rows(3)
                        ->maxLength(1000),
                    Toggle::make('rtl')
                        ->label(__('pondok.school_profile.blocks.quote_rtl'))
                        ->helperText(__('pondok.school_profile.blocks.quote_rtl_helper'))
                        ->default(false),
                    Textarea::make('translation')
                        ->label(__('pondok.school_profile.blocks.quote_translation'))
                        ->rows(3)
                        ->maxLength(1000),
                    TextInput::make('source')
                        ->label(__('pondok.school_profile.blocks.quote_source'))
                        ->helperText(__('pondok.school_profile.blocks.quote_source_helper'))
                        ->maxLength(100),
                ]),
            Block::make('cta')
                ->label($label('cta'))
                ->icon(Heroicon::OutlinedMegaphone)
                ->columns(2)
                ->schema([
                    $this->visibleToggle(),
                    TextInput::make('eyebrow')
                        ->label(__('pondok.school_profile.blocks.eyebrow'))
                        ->helperText(__('pondok.school_profile.blocks.eyebrow_helper'))
                        ->maxLength(60),
                    TextInput::make('heading')
                        ->label(__('pondok.school_profile.blocks.heading'))
                        ->required()
                        ->maxLength(100),
                    Textarea::make('text')
                        ->label(__('pondok.school_profile.blocks.text'))
                        ->rows(2)
                        ->maxLength(300)
                        ->columnSpanFull(),
                    Select::make('variant')
                        ->label(__('pondok.school_profile.blocks.variant'))
                        ->options($this->options('variants', NormalizeContentBlocks::CTA_VARIANTS))
                        ->default('light')
                        ->required()
                        ->native(false),
                    $this->buttonField('primary', 'primary'),
                    $this->buttonField('secondary', 'secondary'),
                ]),
            Block::make('video')
                ->label($label('video'))
                ->icon(Heroicon::OutlinedFilm)
                ->schema([
                    $this->visibleToggle(),
                    $this->headingField(),
                    Select::make('video_id')
                        ->label(__('pondok.school_profile.blocks.video'))
                        ->helperText(__('pondok.school_profile.blocks.video_helper'))
                        ->options(fn (): array => Video::query()->orderBy('title')->pluck('title', 'id')->all())
                        ->stateCast(new NumberStateCast(isInteger: true))
                        ->required()
                        ->searchable(),
                ]),
            Block::make('feed')
                ->label($label('feed'))
                ->icon(Heroicon::OutlinedNewspaper)
                ->schema([
                    $this->visibleToggle(),
                    Select::make('source')
                        ->label(__('pondok.school_profile.blocks.source'))
                        ->helperText(__('pondok.school_profile.blocks.source_helper'))
                        ->options($this->options('sources', NormalizeContentBlocks::FEED_SOURCES))
                        ->default('posts')
                        ->required()
                        ->native(false),
                    TextInput::make('heading')
                        ->label(__('pondok.school_profile.blocks.heading'))
                        ->helperText(__('pondok.school_profile.blocks.feed_heading_helper'))
                        ->maxLength(100),
                ]),
        ];
    }

    private function visibleToggle(): Toggle
    {
        return Toggle::make('visible')
            ->label(__('pondok.school_profile.blocks.visible'))
            ->helperText(__('pondok.school_profile.blocks.visible_helper'))
            ->default(true)
            ->columnSpanFull();
    }

    private function headingField(): TextInput
    {
        return TextInput::make('heading')
            ->label(__('pondok.school_profile.blocks.heading'))
            ->helperText(__('pondok.school_profile.blocks.heading_helper'))
            ->maxLength(100)
            ->columnSpanFull();
    }

    /**
     * Gambar (tidak disimpan Filament; berkas diteruskan ke Action) dan teks alternatifnya.
     *
     * @return list<Component>
     */
    private function imageFields(): array
    {
        return [
            FileUpload::make('image')
                ->label(__('pondok.school_profile.blocks.image'))
                ->helperText(__('pondok.school_profile.blocks.image_helper', ['max' => StorePublicImage::MAX_KILOBYTES / 1024]))
                ->image()
                ->disk(StorePublicImage::DISK)
                ->storeFiles(false)
                ->acceptedFileTypes(StorePublicImage::MIME_TYPES)
                ->maxSize(StorePublicImage::MAX_KILOBYTES),
            TextInput::make('image_alt')
                ->label(__('pondok.school_profile.blocks.image_alt'))
                ->helperText(__('pondok.school_profile.blocks.image_alt_helper'))
                ->maxLength(150),
        ];
    }

    private function buttonField(string $name, string $label): Fieldset
    {
        return Fieldset::make(__("pondok.school_profile.blocks.{$label}"))
            ->statePath($name)
            ->schema($this->linkTargetFields(GetPortalNavigation::LABEL_MAX_MENU, [self::NO_BUTTON => __('pondok.school_profile.nav_no_button')]))
            ->columns(2)
            ->columnSpanFull();
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private function options(string $group, array $values): array
    {
        return collect($values)->mapWithKeys(fn (string $value): array => [$value => __("pondok.school_profile.blocks.{$group}.{$value}")])->all();
    }

    protected function formData(array $profile): array
    {
        $blocks = app(ParseContentBlocks::class)->handle('home', $profile['home_blocks'], withHidden: true);
        $albumIds = $this->existingIds(Album::class, $blocks, 'album_id');
        $videoIds = $this->existingIds(Video::class, $blocks, 'video_id');

        return [
            'home_blocks' => array_map(function (array $block) use ($albumIds, $videoIds): array {
                $data = $block['data'];

                foreach (['album_id' => $albumIds, 'video_id' => $videoIds] as $field => $ids) {
                    if (array_key_exists($field, $data) && ! in_array($data[$field], $ids, true)) {
                        $data[$field] = null;
                    }
                }

                foreach (self::BUTTONS as $field) {
                    if (array_key_exists($field, $data)) {
                        $data[$field] = $data[$field] === null || $this->linkTargetMissing($data[$field])
                            ? ['type' => self::NO_BUTTON]
                            : $this->linkTargetRow($data[$field]);
                    }
                }

                return ['type' => $block['type'], 'data' => ['visible' => $block['visible'], ...$data]];
            }, $blocks),
        ];
    }

    /**
     * Id rujukan yang masih ada (satu query per model).
     *
     * @param  class-string<Album|Video>  $model
     * @param  list<array{type: string, visible: bool, data: array<string, mixed>}>  $blocks
     * @return list<int>
     */
    private function existingIds(string $model, array $blocks, string $field): array
    {
        $ids = array_filter(array_map(fn (array $block): mixed => $block['data'][$field] ?? null, $blocks));

        return $ids === [] ? [] : $model::query()->whereKey($ids)->pluck('id')->all();
    }

    protected function persist(array $data, User $user): void
    {
        $blocks = array_map(function (mixed $block): array {
            $block = is_array($block) ? $block : [];
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            foreach (self::BUTTONS as $field) {
                $button = $data[$field] ?? null;

                if (array_key_exists($field, $data) && (! is_array($button) || in_array($button['type'] ?? null, [null, '', self::NO_BUTTON], true))) {
                    $data[$field] = null;
                }
            }

            $visible = (bool) ($data['visible'] ?? true);
            unset($data['visible']);

            return ['type' => $block['type'] ?? null, 'visible' => $visible, 'data' => $data];
        }, array_values($data['home_blocks'] ?? []));

        app(UpdatePortalHome::class)->handle(['home_blocks' => $blocks], $user);

        $this->resetLinkTargetOptions();
    }
}
