<?php

namespace App\Filament\Clusters\InstitutionProfile\Pages;

use App\Actions\Konten\UpdatePortalFooter;
use App\Models\StaticPage;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Footer portal: kutipan (Arab, terjemahan, sumber), deskripsi lembaga, dan
 * tautan cepat (alamat bebas atau halaman situs, F037). Disimpan lewat `UpdatePortalFooter`.
 */
class Footer extends InstitutionProfilePage
{
    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'footer';

    private const LINK_PAGE = 'page';

    private const LINK_URL = 'url';

    protected static function pageKey(): string
    {
        return 'footer';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('pondok.school_profile.section_quote'))
                    ->schema([
                        Textarea::make('footer_quote_arabic')
                            ->label(__('pondok.school_profile.footer_quote_arabic'))
                            ->extraInputAttributes(['dir' => 'rtl', 'lang' => 'ar'])
                            ->rows(3)
                            ->maxLength(1000),
                        Textarea::make('footer_quote_translation')
                            ->label(__('pondok.school_profile.footer_quote_translation'))
                            ->rows(3)
                            ->maxLength(1000),
                        TextInput::make('footer_quote_source')
                            ->label(__('pondok.school_profile.footer_quote_source'))
                            ->maxLength(100),
                    ]),
                Section::make(__('pondok.school_profile.section_description'))
                    ->schema([
                        Textarea::make('footer_description')
                            ->label(__('pondok.school_profile.footer_description'))
                            ->helperText(__('pondok.school_profile.footer_description_helper'))
                            ->rows(3)
                            ->maxLength(500),
                    ]),
                Section::make(__('pondok.school_profile.section_links'))
                    ->schema([
                        Repeater::make('footer_links')
                            ->hiddenLabel()
                            ->helperText(__('pondok.school_profile.footer_links_helper'))
                            ->addActionLabel(__('pondok.school_profile.add_link'))
                            ->reorderable()
                            ->maxItems(UpdatePortalFooter::MAX_LINKS)
                            ->defaultItems(0)
                            ->columns(2)
                            ->schema([
                                ToggleButtons::make('type')
                                    ->label(__('pondok.school_profile.link_type'))
                                    ->options([
                                        self::LINK_PAGE => __('pondok.school_profile.link_type_page'),
                                        self::LINK_URL => __('pondok.school_profile.link_type_url'),
                                    ])
                                    ->default(self::LINK_URL)
                                    ->inline()
                                    ->live()
                                    ->columnSpanFull(),
                                Select::make('page_id')
                                    ->label(__('pondok.school_profile.link_page'))
                                    ->options(fn (): array => $this->pageOptions())
                                    ->required()
                                    ->native(false)
                                    ->visible(fn (Get $get): bool => $get('type') === self::LINK_PAGE),
                                TextInput::make('label')
                                    ->label(__('pondok.school_profile.link_label'))
                                    ->helperText(fn (Get $get): ?string => $get('type') === self::LINK_PAGE ? __('pondok.school_profile.link_page_label_helper') : null)
                                    ->required(fn (Get $get): bool => $get('type') !== self::LINK_PAGE)
                                    ->maxLength(60),
                                TextInput::make('url')
                                    ->label(__('pondok.school_profile.link_url'))
                                    ->helperText(__('pondok.school_profile.link_url_helper'))
                                    ->required()
                                    ->maxLength(255)
                                    ->visible(fn (Get $get): bool => $get('type') !== self::LINK_PAGE),
                            ]),
                    ]),
            ]);
    }

    protected function formData(array $profile): array
    {
        return [
            'footer_quote_arabic' => $profile['footer']['quote_arabic'],
            'footer_quote_translation' => $profile['footer']['quote_translation'],
            'footer_quote_source' => $profile['footer']['quote_source'],
            'footer_description' => $profile['footer']['description'],
            'footer_links' => array_map(fn (array $link): array => isset($link['page_id'])
                ? ['type' => self::LINK_PAGE, 'page_id' => $link['page_id'], 'label' => $link['label'], 'url' => null]
                : ['type' => self::LINK_URL, 'page_id' => null, 'label' => $link['label'], 'url' => $link['url']], $profile['footer']['links']),
        ];
    }

    protected function persist(array $data, User $user): void
    {
        // Jenis baris menentukan target: hanya `page_id` atau hanya `url` yang dikirim ke Action.
        $data['footer_links'] = array_map(fn (mixed $link): mixed => is_array($link) ? match ($link['type'] ?? self::LINK_URL) {
            self::LINK_PAGE => ['label' => $link['label'] ?? null, 'page_id' => $link['page_id'] ?? null],
            default => ['label' => $link['label'] ?? null, 'url' => $link['url'] ?? null],
        } : $link, is_array($data['footer_links'] ?? null) ? $data['footer_links'] : []);

        app(UpdatePortalFooter::class)->handle($data, $user);
    }

    /**
     * Semua halaman urut judul; halaman draf diberi keterangan (tautannya belum tampil).
     *
     * @return array<int, string>
     */
    private function pageOptions(): array
    {
        return StaticPage::query()->orderBy('title')->get(['id', 'title', 'status'])
            ->mapWithKeys(fn (StaticPage $page): array => [$page->id => $page->isPublished()
                ? $page->title
                : __('pondok.school_profile.menu_draft_page', ['title' => $page->title])])
            ->all();
    }
}
