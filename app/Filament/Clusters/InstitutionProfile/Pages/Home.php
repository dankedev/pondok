<?php

namespace App\Filament\Clusters\InstitutionProfile\Pages;

use App\Actions\Konten\UpdatePortalHome;
use App\Models\Album;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

/**
 * Isi beranda portal: sambutan, keunggulan, dan media (album slider, video profil,
 * video PSB). Media memakai data modul Konten; bila modul mati, select tidak
 * dirender dan pilihan tersimpan tidak diubah. Disimpan lewat `UpdatePortalHome`.
 */
class Home extends InstitutionProfilePage
{
    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'home';

    private const MEDIA_FIELDS = ['hero_album_id', 'profile_video_id', 'psb_video_id'];

    protected static function pageKey(): string
    {
        return 'home';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('pondok.school_profile.section_greeting'))
                    ->schema([
                        TextInput::make('hero_greeting')
                            ->label(__('pondok.school_profile.hero_greeting'))
                            ->helperText(__('pondok.school_profile.hero_greeting_helper'))
                            ->maxLength(100),
                        TextInput::make('hero_title')
                            ->label(__('pondok.school_profile.hero_title'))
                            ->helperText(__('pondok.school_profile.hero_title_helper'))
                            ->maxLength(150),
                        Textarea::make('hero_subtitle')
                            ->label(__('pondok.school_profile.hero_subtitle'))
                            ->helperText(__('pondok.school_profile.hero_subtitle_helper'))
                            ->rows(2)
                            ->maxLength(300),
                    ]),
                Section::make(__('pondok.school_profile.section_highlights'))
                    ->schema([
                        TextInput::make('highlights_heading')
                            ->label(__('pondok.school_profile.highlights_heading'))
                            ->helperText(__('pondok.school_profile.highlights_heading_helper'))
                            ->maxLength(100),
                        Repeater::make('highlights')
                            ->label(__('pondok.school_profile.highlights'))
                            ->helperText(__('pondok.school_profile.highlights_helper'))
                            ->addActionLabel(__('pondok.school_profile.add_highlight'))
                            ->reorderable()
                            ->maxItems(UpdatePortalHome::MAX_HIGHLIGHTS)
                            ->defaultItems(0)
                            ->schema([
                                TextInput::make('title')
                                    ->label(__('pondok.school_profile.highlight_title'))
                                    ->required()
                                    ->maxLength(80),
                                Textarea::make('description')
                                    ->label(__('pondok.school_profile.highlight_description'))
                                    ->rows(2)
                                    ->maxLength(300),
                            ]),
                    ]),
                Section::make(__('pondok.school_profile.section_media'))
                    ->columns(3)
                    ->schema(Setting::moduleEnabled('konten') ? [
                        Select::make('hero_album_id')
                            ->label(__('pondok.school_profile.hero_album'))
                            ->helperText(__('pondok.school_profile.hero_album_helper'))
                            ->options(fn (): array => Album::query()->orderBy('title')->pluck('title', 'id')->all())
                            ->searchable(),
                        Select::make('profile_video_id')
                            ->label(__('pondok.school_profile.profile_video'))
                            ->options(fn (): array => $this->videoOptions())
                            ->searchable(),
                        Select::make('psb_video_id')
                            ->label(__('pondok.school_profile.psb_video'))
                            ->helperText(__('pondok.school_profile.psb_video_helper'))
                            ->options(fn (): array => $this->videoOptions())
                            ->searchable(),
                    ] : [
                        Text::make(__('pondok.common.module_disabled')),
                        Text::make(__('pondok.school_profile.media_disabled')),
                    ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private function videoOptions(): array
    {
        return Video::query()->orderBy('title')->pluck('title', 'id')->all();
    }

    protected function formData(array $profile): array
    {
        return [
            'hero_greeting' => $profile['hero']['greeting'],
            'hero_title' => $profile['hero']['title'],
            'hero_subtitle' => $profile['hero']['subtitle'],
            'highlights_heading' => $profile['highlights_heading'],
            'highlights' => $profile['highlights'],
            ...collect(self::MEDIA_FIELDS)->mapWithKeys(fn (string $field): array => [$field => $profile[$field]])->all(),
        ];
    }

    protected function persist(array $data, User $user): void
    {
        if (! Setting::moduleEnabled('konten')) {
            $data = collect($data)->except(self::MEDIA_FIELDS)->all();
        }

        app(UpdatePortalHome::class)->handle($data, $user);
    }
}
