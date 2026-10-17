<?php

namespace App\Filament\Pages\Site\Concerns;

use App\Actions\Konten\GetPortalNavigation;
use App\Models\Category;
use App\Models\StaticPage;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\StateCasts\NumberStateCast;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Pemilih tujuan tautan F043 (`{type, label, page_id?, category_id?, url?}`), dipakai halaman
 * Navigasi (menu, tombol header) dan Beranda (tombol blok, F045). Validasi akhir tetap di Action.
 */
trait LinkTargetFields
{
    /** Nilai pemilih untuk "Tanpa tombol". */
    public const NO_BUTTON = 'none';

    /** @var array<int, string>|null */
    private ?array $pageOptions = null;

    /** @var array<int, string>|null */
    private ?array $categoryOptions = null;

    /**
     * Pemilih tujuan dan field kondisionalnya; `$extraTypes` menambah opsi di awal (tanpa tombol)
     * atau akhir daftar (grup).
     *
     * @param  array<string, string>  $extraTypes
     * @return list<Component>
     */
    protected function linkTargetFields(int $labelMax, array $extraTypes = []): array
    {
        $noButton = array_key_exists(self::NO_BUTTON, $extraTypes);

        return [
            Select::make('type')
                ->label(__('pondok.school_profile.nav_type'))
                ->options($noButton ? [...$extraTypes, ...$this->linkTypeOptions()] : [...$this->linkTypeOptions(), ...$extraTypes])
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
     * Item tersimpan → state form (semua field ada agar field kondisional terisi).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function linkTargetRow(array $item): array
    {
        return [
            'type' => $item['type'],
            'label' => $item['label'] ?? null,
            'page_id' => $item['page_id'] ?? null,
            'category_id' => $item['category_id'] ?? null,
            'url' => $item['url'] ?? null,
        ];
    }

    /**
     * Tujuan halaman/kategori yang sudah dihapus (opsi dimuat sekali per tabel).
     *
     * @param  array<string, mixed>  $item
     */
    protected function linkTargetMissing(array $item): bool
    {
        return match ($item['type'] ?? null) {
            'page' => ! array_key_exists((int) ($item['page_id'] ?? 0), $this->pageOptions()),
            'category' => ! array_key_exists((int) ($item['category_id'] ?? 0), $this->categoryOptions()),
            default => false,
        };
    }

    /**
     * Nama tampilan item: label isian, atau nama tujuan (judul halaman, nama kategori).
     *
     * @param  array<string, mixed>  $state
     */
    protected function linkTargetName(array $state): string
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

    /** Opsi halaman/kategori dimuat ulang setelah simpan. */
    protected function resetLinkTargetOptions(): void
    {
        $this->pageOptions = null;
        $this->categoryOptions = null;
    }

    /**
     * Tujuan selain grup, label dari `lang/id`.
     *
     * @return array<string, string>
     */
    private function linkTypeOptions(): array
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
