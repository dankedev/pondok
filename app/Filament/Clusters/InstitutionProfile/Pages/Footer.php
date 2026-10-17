<?php

namespace App\Filament\Clusters\InstitutionProfile\Pages;

use App\Actions\Konten\UpdatePortalFooter;
use App\Models\User;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Footer portal (F043): deskripsi lembaga, satu teks pendek (judul opsional + isi), dan
 * saklar tampil kontak/media sosial. Tautan footer diatur di halaman Navigasi.
 * Disimpan lewat `UpdatePortalFooter`.
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
                Section::make(__('pondok.school_profile.section_description'))
                    ->schema([
                        Textarea::make('footer_description')
                            ->label(__('pondok.school_profile.footer_description'))
                            ->helperText(__('pondok.school_profile.footer_description_helper'))
                            ->rows(3)
                            ->maxLength(UpdatePortalFooter::DESCRIPTION_MAX_LENGTH),
                    ]),
                Section::make(__('pondok.school_profile.section_footer_text'))
                    ->description(__('pondok.school_profile.footer_text_helper'))
                    ->schema([
                        TextInput::make('footer_text.title')
                            ->label(__('pondok.school_profile.footer_text_title'))
                            ->maxLength(UpdatePortalFooter::TEXT_TITLE_MAX_LENGTH),
                        Textarea::make('footer_text.body')
                            ->label(__('pondok.school_profile.footer_text_body'))
                            ->rows(4)
                            ->maxLength(UpdatePortalFooter::TEXT_BODY_MAX_LENGTH),
                    ]),
                Section::make(__('pondok.school_profile.section_footer_display'))
                    ->description(__('pondok.school_profile.footer_display_helper'))
                    ->schema([
                        Toggle::make('footer_show_contacts')
                            ->label(__('pondok.school_profile.footer_show_contacts'))
                            ->default(true),
                        Toggle::make('footer_show_social')
                            ->label(__('pondok.school_profile.footer_show_social'))
                            ->default(true),
                    ]),
            ]);
    }

    protected function formData(array $profile): array
    {
        $footer = $profile['footer'];

        return [
            'footer_description' => $footer['description'],
            'footer_text' => [
                'title' => $footer['text']['title'] ?? null,
                'body' => $footer['text']['body'] ?? null,
            ],
            'footer_show_contacts' => $footer['show_contacts'],
            'footer_show_social' => $footer['show_social'],
        ];
    }

    protected function persist(array $data, User $user): void
    {
        app(UpdatePortalFooter::class)->handle($data, $user);
    }
}
