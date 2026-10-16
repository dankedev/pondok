<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Actions\Prestasi\PublishAchievementAsPost;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Achievement;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ListPosts extends ListRecords
{
    use ShowsActionErrorsOnForm;

    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            static::fromAchievementAction(),
            CreateAction::make()->label(__('pondok.post.create')),
        ];
    }

    /**
     * "Buat berita dari prestasi" (F015): draf berita dari prestasi publik terverifikasi
     * yang belum diberitakan. Butuh modul prestasi, `publish:achievement`, dan `create:post`.
     */
    public static function fromAchievementAction(): Action
    {
        return Action::make('fromAchievement')
            ->label(__('pondok.achievement.from_achievement_action'))
            ->icon(Heroicon::OutlinedTrophy)
            ->color('gray')
            ->authorize(fn (): bool => Setting::moduleEnabled('prestasi')
                && (bool) auth()->user()?->can('publish:achievement')
                && PostResource::canCreate())
            ->modalHeading(__('pondok.achievement.from_achievement_action'))
            ->modalDescription(__('pondok.achievement.publish_description'))
            ->schema([
                Select::make('achievement_id')
                    ->label(__('pondok.achievement.label'))
                    ->options(fn (): array => static::publishableOptions())
                    // Label dari database agar id yang dikirim langsung sampai ke pemeriksaan server.
                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value) ? static::optionLabel(Achievement::query()->find((int) $value)) : null)
                    ->helperText(fn (): ?string => static::publishableOptions() === [] ? __('pondok.achievement.from_achievement_empty') : null)
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data, PublishAchievementAsPost $publish, Action $action): void {
                /** @var User $user */
                $user = auth()->user();

                $post = static::saveShowingFormErrors($action, function () use ($data, $publish, $user): Post {
                    $achievement = is_numeric($data['achievement_id'] ?? null) ? Achievement::query()->find((int) $data['achievement_id']) : null;

                    if ($achievement === null || ! $achievement->isPublishable() || ! Gate::allows('publish', $achievement)) {
                        throw ValidationException::withMessages(['achievement_id' => __('pondok.prestasi.errors.not_publishable')]);
                    }

                    try {
                        return $publish->handle($achievement, $user);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages(['achievement_id' => collect($exception->errors())->flatten()->all()]);
                    }
                });

                Notification::make()->title(__('pondok.achievement.published', ['title' => $post->title]))->success()->send();

                $action->redirect(PostResource::getUrl('edit', ['record' => $post]));
            });
    }

    /**
     * @return array<int, string>
     */
    private static function publishableOptions(): array
    {
        return Achievement::query()
            ->publishable()
            ->orderByDesc('achieved_on')
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (Achievement $achievement): array => [$achievement->id => static::optionLabel($achievement)])
            ->all();
    }

    private static function optionLabel(?Achievement $achievement): ?string
    {
        return $achievement === null ? null : __('pondok.achievement.from_achievement_option', [
            'title' => $achievement->title,
            'date' => $achievement->achieved_on->locale('id')->translatedFormat('j M Y'),
        ]);
    }
}
