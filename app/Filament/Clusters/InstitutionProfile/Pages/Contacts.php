<?php

namespace App\Filament\Clusters\InstitutionProfile\Pages;

use App\Actions\Konten\UpdateSchoolContacts;
use App\Models\User;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Kontak & lokasi: tautan peta, daftar kontak berlabel, dan media sosial.
 * Disimpan lewat `UpdateSchoolContacts`.
 */
class Contacts extends InstitutionProfilePage
{
    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'contacts';

    protected static function pageKey(): string
    {
        return 'contacts';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('pondok.school_profile.section_map'))
                    ->schema([
                        TextInput::make('map_url')
                            ->label(__('pondok.school_profile.map_url'))
                            ->helperText(__('pondok.school_profile.map_url_helper'))
                            ->placeholder('https://')
                            ->maxLength(500),
                    ]),
                Section::make(__('pondok.school_profile.section_contacts'))
                    ->schema([
                        Repeater::make('contacts')
                            ->hiddenLabel()
                            ->helperText(__('pondok.school_profile.contacts_helper'))
                            ->addActionLabel(__('pondok.school_profile.add_contact'))
                            ->reorderable()
                            ->maxItems(UpdateSchoolContacts::MAX_CONTACTS)
                            ->defaultItems(0)
                            ->columns(2)
                            ->schema([
                                Hidden::make('key'),
                                TextInput::make('label')
                                    ->label(__('pondok.school_profile.contact_label'))
                                    ->helperText(__('pondok.school_profile.contact_label_helper'))
                                    ->required()
                                    ->maxLength(60),
                                TextInput::make('name')
                                    ->label(__('pondok.school_profile.contact_name'))
                                    ->maxLength(100),
                                TextInput::make('phone')
                                    ->label(__('pondok.school_profile.contact_phone'))
                                    ->tel()
                                    ->required()
                                    ->maxLength(30),
                                Toggle::make('is_whatsapp')
                                    ->label(__('pondok.school_profile.contact_is_whatsapp'))
                                    ->default(true)
                                    ->inline(false),
                            ]),
                    ]),
                Section::make(__('pondok.school_profile.section_social'))
                    ->schema([
                        Repeater::make('social_links')
                            ->hiddenLabel()
                            ->addActionLabel(__('pondok.school_profile.add_social'))
                            ->reorderable()
                            ->maxItems(UpdateSchoolContacts::MAX_SOCIAL_LINKS)
                            ->defaultItems(0)
                            ->columns(3)
                            ->schema([
                                Select::make('platform')
                                    ->label(__('pondok.school_profile.platform'))
                                    ->options(collect(UpdateSchoolContacts::PLATFORMS)->mapWithKeys(fn (string $platform): array => [$platform => __("pondok.portal.social.{$platform}")])->all())
                                    ->required()
                                    ->native(false),
                                TextInput::make('label')
                                    ->label(__('pondok.school_profile.social_label'))
                                    ->helperText(__('pondok.school_profile.social_label_helper'))
                                    ->maxLength(60),
                                TextInput::make('url')
                                    ->label(__('pondok.school_profile.url'))
                                    ->placeholder('https://')
                                    ->required()
                                    ->maxLength(255),
                            ]),
                    ]),
            ]);
    }

    protected function formData(array $profile): array
    {
        return [
            'map_url' => $profile['map_url'],
            'contacts' => $profile['contacts'],
            'social_links' => $profile['social_links'],
        ];
    }

    protected function persist(array $data, User $user): void
    {
        app(UpdateSchoolContacts::class)->handle($data, $user);
    }
}
