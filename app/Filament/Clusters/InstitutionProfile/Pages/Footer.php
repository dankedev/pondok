<?php

namespace App\Filament\Clusters\InstitutionProfile\Pages;

use App\Actions\Konten\UpdatePortalFooter;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Footer portal: kutipan (Arab, terjemahan, sumber), deskripsi lembaga, dan
 * tautan cepat. Disimpan lewat `UpdatePortalFooter`.
 */
class Footer extends InstitutionProfilePage
{
    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'footer';

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
                                TextInput::make('label')
                                    ->label(__('pondok.school_profile.link_label'))
                                    ->required()
                                    ->maxLength(60),
                                TextInput::make('url')
                                    ->label(__('pondok.school_profile.link_url'))
                                    ->helperText(__('pondok.school_profile.link_url_helper'))
                                    ->required()
                                    ->maxLength(255),
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
            'footer_links' => $profile['footer']['links'],
        ];
    }

    protected function persist(array $data, User $user): void
    {
        app(UpdatePortalFooter::class)->handle($data, $user);
    }
}
