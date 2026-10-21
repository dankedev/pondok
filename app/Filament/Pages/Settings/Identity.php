<?php

namespace App\Filament\Pages\Settings;

use App\Actions\Konten\StorePublicImage;
use App\Actions\Konten\UpdateSchoolProfile;
use App\Filament\Pages\SettingsFormPage;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;

/**
 * Identitas lembaga: nama, logo cetak/desktop/mobile, dan favicon. Alamat, email, dan
 * telepon cetak diatur di Kontak & sosial. Disimpan lewat `UpdateSchoolProfile`.
 */
class Identity extends SettingsFormPage
{
    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'settings/identity';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.pengaturan');
    }

    /** Field unggahan → [kunci path di profil, label, helper, batas KB, jenis]. */
    private const IMAGES = [
        'logo' => ['logo_path', 'logo', 'logo_helper', StorePublicImage::LOGO_MAX_KILOBYTES, StorePublicImage::MIME_TYPES],
        'logo_desktop' => ['logo_desktop_path', 'logo_desktop', 'logo_desktop_helper', StorePublicImage::LOGO_MAX_KILOBYTES, StorePublicImage::MIME_TYPES],
        'logo_mobile' => ['logo_mobile_path', 'logo_mobile', 'logo_mobile_helper', StorePublicImage::LOGO_MAX_KILOBYTES, StorePublicImage::MIME_TYPES],
        'favicon' => ['favicon_path', 'favicon', 'favicon_helper', StorePublicImage::FAVICON_MAX_KILOBYTES, ['image/png']],
    ];

    /**
     * Path gambar tersimpan (disk `public`) per field unggahan, untuk pratinjau.
     *
     * @var array<string, ?string>
     */
    public array $imagePaths = [];

    protected static function pageKey(): string
    {
        return 'identity';
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
                        TextInput::make('short_name')
                            ->label(__('pondok.school_profile.short_name'))
                            ->helperText(__('pondok.school_profile.short_name_helper'))
                            ->maxLength(30),
                        TextInput::make('arabic_name')
                            ->label(__('pondok.school_profile.arabic_name'))
                            ->extraInputAttributes(['dir' => 'rtl', 'lang' => 'ar'])
                            ->maxLength(150),
                        TextInput::make('tagline')
                            ->label(__('pondok.school_profile.tagline'))
                            ->maxLength(150),
                    ]),
                Section::make(__('pondok.school_profile.section_logos'))
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema(collect(self::IMAGES)->flatMap(fn (array $image, string $field): array => $this->imageComponents($field, ...$image))->all()),
            ]);
    }

    /**
     * @param  list<string>  $mimeTypes
     * @return list<mixed>
     */
    private function imageComponents(string $field, string $pathKey, string $label, string $helper, int $maxKilobytes, array $mimeTypes): array
    {
        $imageLabel = __("pondok.school_profile.{$label}");

        return [
            ImageEntry::make("current_{$field}")
                ->label(__('pondok.school_profile.current_image', ['image' => $imageLabel]))
                ->state(fn (): ?string => $this->imagePaths[$field] ?? null)
                ->disk(StorePublicImage::DISK)
                ->imageHeight(64)
                ->visible(fn (): bool => ($this->imagePaths[$field] ?? null) !== null),
            Toggle::make("remove_{$field}")
                ->label(__('pondok.school_profile.remove_image', ['image' => mb_strtolower($imageLabel)]))
                ->visible(fn (): bool => ($this->imagePaths[$field] ?? null) !== null),
            FileUpload::make($field)
                ->label($imageLabel)
                ->helperText(__("pondok.school_profile.{$helper}"))
                ->image()
                ->storeFiles(false)
                ->acceptedFileTypes($mimeTypes)
                ->maxSize($maxKilobytes)
                ->columnSpanFull(),
        ];
    }

    protected function formData(array $profile): array
    {
        $this->imagePaths = collect(self::IMAGES)->map(fn (array $image): ?string => $profile[$image[0]])->all();

        $data = collect($profile)->only(['name', 'short_name', 'arabic_name', 'tagline'])->all();

        foreach (array_keys(self::IMAGES) as $field) {
            $data[$field] = null;
            $data["remove_{$field}"] = false;
        }

        return $data;
    }

    protected function persist(array $data, User $user): void
    {
        foreach (array_keys(self::IMAGES) as $field) {
            $file = $data[$field] ?? null;

            if (is_array($file)) {
                $file = collect($file)->first(fn (mixed $item): bool => $item instanceof UploadedFile);
            }

            $data[$field] = $file instanceof UploadedFile ? $file : null;
        }

        app(UpdateSchoolProfile::class)->handle($data, $user);
    }
}
