<?php

namespace App\Filament\Clusters\InstitutionProfile\Pages;

use App\Actions\Konten\GetSchoolProfile;
use App\Filament\Clusters\InstitutionProfile\InstitutionProfileCluster;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Dasar empat halaman profil lembaga: satu form (`data`), satu tombol simpan, satu
 * Action. Galat Action dipetakan ke field form, termasuk baris repeater
 * (`contacts.1.phone` → `data.contacts.<uuid>.phone`).
 *
 * @property-read Schema $form
 */
abstract class InstitutionProfilePage extends Page
{
    protected static ?string $cluster = InstitutionProfileCluster::class;

    protected string $view = 'filament.pages.institution-profile';

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
                    ->mapWithKeys(fn (array $messages, string $field): array => ['data.'.$this->stateField($field, $state) => $messages])
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
     * `contacts.1.phone` → `contacts.<kunci baris ke-1>.phone`.
     *
     * @param  array<string, mixed>  $state
     */
    private function stateField(string $field, array $state): string
    {
        $parts = explode('.', $field, 3);

        if (count($parts) >= 2 && is_array($state[$parts[0]] ?? null) && ctype_digit($parts[1])) {
            $keys = array_keys($state[$parts[0]]);
            $parts[1] = (string) ($keys[(int) $parts[1]] ?? $parts[1]);
        }

        return implode('.', $parts);
    }
}
