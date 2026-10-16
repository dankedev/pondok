<?php

namespace App\Filament\Pages;

use App\Actions\Konten\GetSchoolProfile;
use App\Actions\Konten\StorePublicImage;
use App\Actions\Konten\UpdateSchoolProfile;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;

/**
 * Profil sekolah (F016): nama, slogan, logo, kontak, dan media sosial di
 * `settings` (`school.*`), dipakai kuitansi dan portal publik. Disimpan lewat
 * `UpdateSchoolProfile`. Akses lewat permission page `view:school-profile`;
 * tidak terikat saklar modul konten.
 *
 * @property-read Schema $form
 */
class SchoolProfile extends Page
{
    use ShowsActionErrorsOnPageForm;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'school-profile';

    protected string $view = 'filament.pages.school-profile';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public ?string $currentLogoUrl = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('view:school-profile');
    }

    /**
     * Item navigasi disimpan di instance panel; cek ulang akses saat dirender.
     *
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
        return __('pondok.navigation.pengaturan');
    }

    public static function getNavigationLabel(): string
    {
        return __('pondok.school_profile.title');
    }

    public function getTitle(): string
    {
        return __('pondok.school_profile.title');
    }

    public function mount(): void
    {
        $this->fillFromSettings();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(2)
            ->components([
                Section::make(__('pondok.school_profile.section_identity'))
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('pondok.school_profile.name'))
                            ->required()
                            ->maxLength(100),
                        TextInput::make('tagline')
                            ->label(__('pondok.school_profile.tagline'))
                            ->maxLength(150),
                        ImageEntry::make('current_logo')
                            ->label(__('pondok.school_profile.current_logo'))
                            ->state(fn (): ?string => $this->currentLogoUrl)
                            ->imageHeight(80)
                            ->visible(fn (): bool => $this->currentLogoUrl !== null),
                        Toggle::make('remove_logo')
                            ->label(__('pondok.school_profile.remove_logo'))
                            ->visible(fn (): bool => $this->currentLogoUrl !== null),
                        FileUpload::make('logo')
                            ->label(__('pondok.school_profile.logo'))
                            ->helperText(__('pondok.school_profile.logo_helper'))
                            ->image()
                            ->storeFiles(false)
                            ->acceptedFileTypes(StorePublicImage::MIME_TYPES)
                            ->maxSize(StorePublicImage::LOGO_MAX_KILOBYTES)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('pondok.school_profile.section_contact'))
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        Textarea::make('address')
                            ->label(__('pondok.school_profile.address'))
                            ->maxLength(500)
                            ->rows(2)
                            ->columnSpanFull(),
                        TextInput::make('phone')
                            ->label(__('pondok.school_profile.phone'))
                            ->tel()
                            ->maxLength(30),
                        TextInput::make('whatsapp')
                            ->label(__('pondok.school_profile.whatsapp'))
                            ->tel()
                            ->maxLength(30),
                        TextInput::make('email')
                            ->label(__('pondok.school_profile.email'))
                            ->maxLength(255),
                    ]),
                Section::make(__('pondok.school_profile.section_social'))
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextInput::make('instagram_url')
                            ->label(__('pondok.school_profile.instagram_url'))
                            ->placeholder('https://')
                            ->maxLength(255),
                        TextInput::make('facebook_url')
                            ->label(__('pondok.school_profile.facebook_url'))
                            ->placeholder('https://')
                            ->maxLength(255),
                        TextInput::make('youtube_url')
                            ->label(__('pondok.school_profile.youtube_url'))
                            ->placeholder('https://')
                            ->maxLength(255),
                    ]),
            ]);
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
                            ->label(__('pondok.school_profile.save'))
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $logo = $data['logo'] ?? null;

        if (is_array($logo)) {
            $logo = collect($logo)->first(fn (mixed $item): bool => $item instanceof UploadedFile);
        }

        /** @var User $user */
        $user = auth()->user();

        $this->runShowingPageFormErrors(fn () => app(UpdateSchoolProfile::class)->handle(
            [...$data, 'logo' => $logo instanceof UploadedFile ? $logo : null],
            $user,
        ));

        $this->fillFromSettings();

        Notification::make()
            ->title(__('pondok.school_profile.saved'))
            ->success()
            ->send();
    }

    private function fillFromSettings(): void
    {
        $profile = app(GetSchoolProfile::class)->handle();
        $this->currentLogoUrl = $profile['logo_url'];

        $this->form->fill([
            ...collect($profile)->except(['logo_path', 'logo_url'])->all(),
            'remove_logo' => false,
            'logo' => null,
        ]);
    }
}
