<?php

namespace App\Filament\Clusters\InstitutionProfile\Pages;

use App\Actions\Konten\GetPortalNavigation;
use App\Actions\Konten\UpdatePortalMenu;
use App\Models\Setting;
use App\Models\StaticPage;
use App\Models\User;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

/**
 * Menu header portal (F037): semua item bawaan dan semua halaman dalam satu daftar
 * yang bisa diurutkan dan disembunyikan, serta tombol Daftar PSB. Daftar disusun
 * `GetPortalNavigation::mergedItems()` (sama dengan portal), halaman yang belum ada
 * di menu menyusul di akhir tersembunyi. Disimpan lewat `UpdatePortalMenu`.
 */
class Menu extends InstitutionProfilePage
{
    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'menu';

    /** @var Collection<int, StaticPage>|null */
    private ?Collection $pages = null;

    protected static function pageKey(): string
    {
        return 'menu';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('pondok.school_profile.section_menu'))
                    ->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->helperText(__('pondok.school_profile.menu_helper', ['max' => GetPortalNavigation::MAX_VISIBLE]))
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable()
                            ->columns(2)
                            ->itemLabel(fn (array $state): string => $this->itemName($state))
                            ->schema([
                                Hidden::make('type'),
                                Hidden::make('key'),
                                Hidden::make('page_id'),
                                Toggle::make('visible')
                                    ->label(__('pondok.school_profile.menu_visible'))
                                    ->inline(false),
                                TextInput::make('label')
                                    ->label(__('pondok.school_profile.menu_label'))
                                    ->helperText(__('pondok.school_profile.menu_label_helper'))
                                    ->placeholder(fn (Get $get): ?string => $this->pages()->get((int) $get('page_id'))?->title)
                                    ->maxLength(GetPortalNavigation::LABEL_MAX_LENGTH)
                                    ->visible(fn (Get $get): bool => $get('type') === 'page'),
                            ]),
                    ]),
                Section::make(__('pondok.school_profile.section_menu_buttons'))
                    ->schema([
                        Toggle::make('show_register')
                            ->label(__('pondok.school_profile.menu_show_register')),
                    ]),
            ]);
    }

    protected function formData(array $profile): array
    {
        $pages = $this->pages();
        $items = app(GetPortalNavigation::class)->mergedItems(
            Setting::get('portal.menu_items'),
            $pages->pluck('id', 'slug')->all(),
            $pages->keys()->all(),
        );

        $listed = array_column(array_filter($items, fn (array $item): bool => $item['type'] === 'page'), 'page_id');

        foreach ($pages->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE) as $page) {
            if (! in_array($page->id, $listed, true)) {
                $items[] = ['type' => 'page', 'page_id' => $page->id, 'label' => null, 'visible' => false];
            }
        }

        return [
            'items' => array_map(fn (array $item): array => [
                'type' => $item['type'],
                'key' => $item['key'] ?? null,
                'page_id' => $item['page_id'] ?? null,
                'label' => $item['label'] ?? null,
                'visible' => $item['visible'],
            ], $items),
            'show_register' => Setting::get('portal.menu_show_register') !== false,
        ];
    }

    protected function persist(array $data, User $user): void
    {
        $this->pages = null;

        app(UpdatePortalMenu::class)->handle($data, $user);
    }

    /**
     * Nama baris: label item bawaan dari `lang/id`, atau judul halaman (draf diberi keterangan).
     *
     * @param  array<string, mixed>  $state
     */
    private function itemName(array $state): string
    {
        if (($state['type'] ?? null) === 'builtin') {
            return __('pondok.portal.nav.'.$state['key']);
        }

        $page = $this->pages()->get((int) ($state['page_id'] ?? 0));

        if ($page === null) {
            return '';
        }

        return $page->isPublished() ? $page->title : __('pondok.school_profile.menu_draft_page', ['title' => $page->title]);
    }

    /**
     * @return Collection<int, StaticPage>
     */
    private function pages(): Collection
    {
        return $this->pages ??= StaticPage::query()->orderBy('id')->get(['id', 'slug', 'title', 'status'])->keyBy('id');
    }
}
