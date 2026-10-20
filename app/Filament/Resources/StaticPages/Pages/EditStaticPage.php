<?php

namespace App\Filament\Resources\StaticPages\Pages;

use App\Actions\Konten\AddPageToPrimaryNavigation;
use App\Actions\Konten\UpdateStaticPage;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\StaticPages\StaticPageResource;
use App\Models\StaticPage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditStaticPage extends EditRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = StaticPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->addToPrimaryNavigationAction(),
            StaticPageResource::deleteAction(),
        ];
    }

    /**
     * "Tambahkan ke menu utama" (F043): hanya untuk pengelola profil lembaga
     * (`view:school-profile`), karena menu diatur di halaman Navigasi. Tidak untuk halaman `psb-info`.
     */
    private function addToPrimaryNavigationAction(): Action
    {
        return Action::make('addToPrimaryNavigation')
            ->label(__('pondok.static_page.add_to_navigation'))
            ->icon(Heroicon::OutlinedBars3)
            ->color('gray')
            // Halaman `psb-info` hanya tampil lewat item menu Info PSB; item `page:` untuknya selalu ditolak (F038).
            ->visible(fn (): bool => (bool) auth()->user()?->can('view:school-profile') && ! $this->getRecord()->isPsbInfo())
            ->action(function (): void {
                abort_unless((bool) auth()->user()?->can('view:school-profile'), 403);

                /** @var StaticPage $page */
                $page = $this->getRecord();
                /** @var User $user */
                $user = auth()->user();

                try {
                    $result = app(AddPageToPrimaryNavigation::class)->handle($page, $user);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(__('pondok.static_page.add_to_navigation_failed'))
                        ->body(collect($exception->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    return;
                }

                $notification = Notification::make()->title(__('pondok.static_page.add_to_navigation_'.$result, ['title' => $page->title]));

                ($result === AddPageToPrimaryNavigation::ADDED ? $notification->success() : $notification->info())->send();
            });
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var StaticPage $record */
        return $this->runShowingPageFormErrors(fn (): StaticPage => app(UpdateStaticPage::class)->handle($record, $data));
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('pondok.static_page.saved', ['title' => $this->getRecord()->title]);
    }
}
