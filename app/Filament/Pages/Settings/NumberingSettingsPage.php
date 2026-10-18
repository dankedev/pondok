<?php

namespace App\Filament\Pages\Settings;

use App\Models\Setting;
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
 * Dasar halaman pengaturan penomoran dokumen (F039): satu form (`data`),
 * satu tombol simpan, satu Action. Akses lewat permission page `view:*`.
 *
 * @property-read Schema $form
 */
abstract class NumberingSettingsPage extends Page
{
    protected string $view = 'filament.pages.settings-form';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    abstract protected static function pagePermission(): string;

    /**
     * Saklar modul yang harus aktif agar halaman tampil, atau null bila tidak terikat modul.
     */
    protected static function moduleKey(): ?string
    {
        return null;
    }

    abstract public function form(Schema $schema): Schema;

    /**
     * @return array<string, mixed>
     */
    abstract protected function fillData(): array;

    /**
     * @param  array<string, mixed>  $data
     */
    abstract protected function persist(array $data, User $user): void;

    public static function canAccess(): bool
    {
        if (static::moduleKey() !== null && ! Setting::moduleEnabled((string) static::moduleKey())) {
            return false;
        }

        return (bool) auth()->user()?->can(static::pagePermission());
    }

    /**
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        return array_map(
            fn (NavigationItem $item): NavigationItem => $item->visible(fn (): bool => static::canAccess()),
            parent::getNavigationItems(),
        );
    }

    public function mount(): void
    {
        $this->form->fill($this->fillData());
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
                            ->label(__('pondok.numbering.save'))
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
            $this->persist($state, $user);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $field): array => ['data.'.$field => $messages])
                    ->all(),
            );
        }

        $this->form->fill($this->fillData());

        Notification::make()
            ->title(__('pondok.numbering.saved'))
            ->success()
            ->send();
    }
}
