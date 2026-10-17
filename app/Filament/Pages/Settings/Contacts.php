<?php

namespace App\Filament\Pages\Settings;

use App\Actions\Konten\UpdateSchoolContacts;
use App\Filament\Pages\SettingsFormPage;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Kontak & sosial, satu-satunya sumber alamat, email, tautan peta, daftar kontak berlabel
 * (satu boleh ditandai cetak di kop surat dan kuitansi), dan media sosial.
 * Disimpan lewat `UpdateSchoolContacts`.
 */
class Contacts extends SettingsFormPage
{
    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'settings/contacts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.pengaturan');
    }

    protected static function pageKey(): string
    {
        return 'contacts';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('pondok.school_profile.section_address'))
                    ->columns(2)
                    ->schema([
                        Textarea::make('address')
                            ->label(__('pondok.school_profile.address'))
                            ->helperText(__('pondok.school_profile.address_helper'))
                            ->maxLength(500)
                            ->rows(2)
                            ->columnSpanFull(),
                        TextInput::make('email')
                            ->label(__('pondok.school_profile.email'))
                            ->maxLength(255),
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
                                Toggle::make('is_print')
                                    ->label(__('pondok.school_profile.contact_is_print'))
                                    ->helperText(__('pondok.school_profile.contact_is_print_helper'))
                                    ->default(false)
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
            'address' => $profile['address'],
            'email' => $profile['email'],
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
