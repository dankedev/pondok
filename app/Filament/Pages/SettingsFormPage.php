<?php

namespace App\Filament\Pages;

use App\Actions\Konten\GetSchoolProfile;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Dasar halaman pengaturan berbasis settings (grup Situs: Beranda, Navigasi, Footer;
 * grup Pengaturan: Identitas, Kontak & sosial): satu form (`data`), satu tombol simpan, satu
 * Action. Akses lewat permission page `view:school-profile` (super-admin, admin-sekolah,
 * admin-konten); tidak terikat saklar modul. Galat Action dipetakan ke field form, termasuk baris repeater
 * (`contacts.1.phone` → `data.contacts.<uuid>.phone`).
 *
 * @property-read Schema $form
 */
abstract class SettingsFormPage extends Page
{
    protected string $view = 'filament.pages.settings-form';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /** Kunci halaman di `pondok.school_profile.pages.*` dan `saved.*`. */
    abstract protected static function pageKey(): string;

    /**
     * Isi form dari profil yang dibaca `GetSchoolProfile`.
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    abstract protected function formData(array $profile): array;

    /**
     * Panggil Action simpan halaman ini.
     *
     * @param  array<string, mixed>  $data
     */
    abstract protected function persist(array $data, User $user): void;

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

    public static function getNavigationLabel(): string
    {
        return __('pondok.school_profile.pages.'.static::pageKey());
    }

    public function getTitle(): string
    {
        return __('pondok.school_profile.pages.'.static::pageKey());
    }

    public function mount(): void
    {
        $this->fillFromSettings();
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

        $state = $this->form->getState();

        /** @var User $user */
        $user = auth()->user();

        try {
            $this->persist($this->listsAsValues($state), $user);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $field): array => ['data.'.$this->stateField($field, $this->form->getRawState()) => $messages])
                    ->all(),
            );
        }

        $this->fillFromSettings();

        Notification::make()
            ->title(__('pondok.school_profile.saved.'.static::pageKey()))
            ->success()
            ->send();
    }

    protected function fillFromSettings(): void
    {
        $this->form->fill($this->formData(app(GetSchoolProfile::class)->handle()));
    }

    /**
     * Repeater menyimpan baris dengan kunci UUID; Action menerima daftar berindeks.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function listsAsValues(array $state): array
    {
        return array_map(
            fn (mixed $value): mixed => is_array($value) && $value !== [] && is_array(reset($value)) ? array_values($value) : $value,
            $state,
        );
    }

    /**
     * Dipetakan dari state mentah karena `getState()` Builder sudah berupa daftar berindeks.
     *
     * `contacts.1.phone` → `contacts.<kunci baris ke-1>.phone`, juga untuk repeater
     * bersarang (`primary.0.children.1.url` → `primary.<uuid>.children.<uuid>.url`).
     *
     * @param  array<string, mixed>  $state
     */
    private function stateField(string $field, array $state): string
    {
        $parts = explode('.', $field);
        $node = $state;

        foreach ($parts as $index => $part) {
            if ($index > 0 && is_array($node) && ctype_digit($part)) {
                $keys = array_keys($node);
                $parts[$index] = $part = (string) ($keys[(int) $part] ?? $part);
            }

            $node = is_array($node) ? ($node[$part] ?? null) : null;
        }

        return implode('.', $parts);
    }
}
