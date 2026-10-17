<?php

namespace App\Filament\Pages\Site;

use App\Actions\Konten\NormalizeContentBlocks;
use App\Actions\Konten\ParseContentBlocks;
use App\Actions\Konten\UpdatePortalFooter;
use App\Filament\Pages\SettingsFormPage;
use App\Filament\Pages\Site\Concerns\LinkTargetFields;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Footer portal (F046): paling banyak 4 widget (Teks, Tautan, Media sosial, Kontak) yang disusun
 * dengan Builder, pola sama dengan halaman Beranda. State tiap widget `{type, data: {visible, ...}}`;
 * saat simpan `visible` dipindah ke tingkat widget dan validasi dipegang `UpdatePortalFooter`.
 * Form diisi lewat `ParseContentBlocks` (widget tersembunyi ikut); item tautan ke halaman/kategori
 * yang sudah dihapus dibuang agar form bisa disimpan.
 */
class Footer extends SettingsFormPage
{
    use LinkTargetFields;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'site/footer';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWindow;

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.situs');
    }

    protected static function pageKey(): string
    {
        return 'footer';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Builder::make('footer_widgets')
                    ->label(__('pondok.school_profile.widgets.builder'))
                    ->helperText(__('pondok.school_profile.widgets.builder_helper', ['max' => NormalizeContentBlocks::MAX_WIDGETS]))
                    ->addActionLabel(__('pondok.school_profile.widgets.add'))
                    ->blocks($this->widgets())
                    ->maxItems(NormalizeContentBlocks::MAX_WIDGETS)
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
    private function widgets(): array
    {
        $label = fn (string $type): Closure => fn (?array $state): string => collect([
            __("pondok.school_profile.widgets.types.{$type}"),
            $state['heading'] ?? null,
        ])->filter(fn (mixed $part): bool => is_string($part) && trim($part) !== '')->implode(': ');

        return [
            Block::make('text')
                ->label($label('text'))
                ->icon(Heroicon::OutlinedDocumentText)
                ->schema([
                    $this->visibleToggle(),
                    $this->headingField(),
                    Toggle::make('show_logo')
                        ->label(__('pondok.school_profile.widgets.show_logo'))
                        ->default(false),
                    RichEditor::make('body')
                        ->label(__('pondok.school_profile.blocks.text'))
                        ->helperText(__('pondok.school_profile.blocks.limited_text_helper'))
                        ->toolbarButtons(['bold', 'link']),
                ]),
            Block::make('links')
                ->label($label('links'))
                ->icon(Heroicon::OutlinedLink)
                ->schema([
                    $this->visibleToggle(),
                    $this->headingField(),
                    Repeater::make('items')
                        ->label(__('pondok.school_profile.widgets.items'))
                        ->helperText(__('pondok.school_profile.widgets.items_helper', ['max' => NormalizeContentBlocks::WIDGET_LINKS_MAX]))
                        ->addActionLabel(__('pondok.school_profile.nav_add_item'))
                        ->schema($this->linkTargetFields(NormalizeContentBlocks::WIDGET_LINK_LABEL_MAX))
                        ->itemLabel(fn (array $state): string => $this->linkTargetName($state))
                        ->reorderable()
                        ->collapsible()
                        ->columns(2)
                        ->minItems(1)
                        ->maxItems(NormalizeContentBlocks::WIDGET_LINKS_MAX)
                        ->defaultItems(1),
                ]),
            Block::make('social')
                ->label($label('social'))
                ->icon(Heroicon::OutlinedShare)
                ->schema([
                    $this->visibleToggle(),
                    $this->headingField(__('pondok.school_profile.widgets.social_helper')),
                ]),
            Block::make('contacts')
                ->label($label('contacts'))
                ->icon(Heroicon::OutlinedPhone)
                ->schema([
                    $this->visibleToggle(),
                    $this->headingField(__('pondok.school_profile.widgets.contacts_helper')),
                ]),
        ];
    }

    private function visibleToggle(): Toggle
    {
        return Toggle::make('visible')
            ->label(__('pondok.school_profile.widgets.visible'))
            ->helperText(__('pondok.school_profile.widgets.visible_helper'))
            ->default(true);
    }

    private function headingField(?string $helper = null): TextInput
    {
        return TextInput::make('heading')
            ->label(__('pondok.school_profile.widgets.heading'))
            ->helperText($helper ?? __('pondok.school_profile.blocks.heading_helper'))
            ->maxLength(NormalizeContentBlocks::WIDGET_HEADING_MAX);
    }

    protected function formData(array $profile): array
    {
        $widgets = app(ParseContentBlocks::class)->handle('footer', $profile['footer_widgets'], withHidden: true);

        return [
            'footer_widgets' => array_map(function (array $widget): array {
                $data = $widget['data'];

                if (isset($data['items'])) {
                    $items = array_filter($data['items'], fn (array $item): bool => ! $this->linkTargetMissing($item));
                    $data['items'] = array_map(fn (array $item): array => $this->linkTargetRow($item), array_values($items));
                }

                return ['type' => $widget['type'], 'data' => ['visible' => $widget['visible'], ...$data]];
            }, $widgets),
        ];
    }

    protected function persist(array $data, User $user): void
    {
        $widgets = array_map(function (mixed $widget): array {
            $widget = is_array($widget) ? $widget : [];
            $data = is_array($widget['data'] ?? null) ? $widget['data'] : [];
            $visible = (bool) ($data['visible'] ?? true);
            unset($data['visible']);

            return ['type' => $widget['type'] ?? null, 'visible' => $visible, 'data' => $data];
        }, array_values($data['footer_widgets'] ?? []));

        app(UpdatePortalFooter::class)->handle(['footer_widgets' => $widgets], $user);

        $this->resetLinkTargetOptions();
    }
}
