<?php

namespace App\Filament\Resources\Achievements;

use App\Actions\Prestasi\DeleteAchievement;
use App\Actions\Prestasi\PublishAchievementAsPost;
use App\Actions\Prestasi\VerifyAchievement;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\Achievements\Pages\CreateAchievement;
use App\Filament\Resources\Achievements\Pages\EditAchievement;
use App\Filament\Resources\Achievements\Pages\ListAchievements;
use App\Filament\Resources\Achievements\Schemas\AchievementForm;
use App\Filament\Resources\Achievements\Tables\AchievementsTable;
use App\Filament\Resources\Documents\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Achievement;
use App\Models\Setting;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Prestasi santri (F015). Simpan lewat `SaveAchievement`, verifikasi, hapus,
 * dan terbitkan sebagai draf berita lewat Action masing-masing. Cakupan
 * jenjang lewat santri peserta (global scope `Achievement`).
 *
 * Super-admin melewati policy (gate `before`), sehingga syarat data aksi
 * (sudah diverifikasi, layak terbit, boleh dihapus) juga dicek di sini.
 */
class AchievementResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Achievement::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 1;

    public static function getModule(): string
    {
        return 'prestasi';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.achievement.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.achievement.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.prestasi');
    }

    /**
     * Policy ditambah cakupan penuh: super-admin bercakupan juga tidak mengubah prestasi lintas jenjang.
     */
    public static function canEdit(Model $record): bool
    {
        return $record instanceof Achievement && $record->isFullyVisibleTo(static::user()) && parent::canEdit($record);
    }

    /**
     * Policy ditambah syarat data dan cakupan penuh (super-admin melewati policy).
     */
    public static function canDelete(Model $record): bool
    {
        return $record instanceof Achievement
            && $record->isDeletable()
            && $record->isFullyVisibleTo(static::user())
            && parent::canDelete($record);
    }

    public static function form(Schema $schema): Schema
    {
        return AchievementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AchievementsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAchievements::route('/'),
            'create' => CreateAchievement::route('/create'),
            'edit' => EditAchievement::route('/{record}/edit'),
        ];
    }

    /**
     * Masukan `SaveAchievement` dari state form (peserta Repeater menjadi list).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function actionData(array $data): array
    {
        return [
            ...$data,
            'participants' => array_values(array_map(
                fn (mixed $item): array => is_array($item) ? $item : [],
                is_array($data['participants'] ?? null) ? $data['participants'] : [],
            )),
        ];
    }

    public static function verifyAction(): Action
    {
        return Action::make('verify')
            ->label(__('pondok.achievement.verify_action'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->authorize(fn (Achievement $record): bool => ! $record->is_verified
                && $record->isFullyVisibleTo(static::user())
                && Gate::allows('verify', $record))
            ->requiresConfirmation()
            ->modalHeading(__('pondok.achievement.verify_action'))
            ->modalDescription(__('pondok.achievement.verify_description'))
            ->action(function (Achievement $record, VerifyAchievement $verifyAchievement, Action $action): void {
                static::runNotifyingErrors($action, fn () => $verifyAchievement->handle($record, static::user()));

                Notification::make()->title(__('pondok.achievement.verified', ['title' => $record->title]))->success()->send();
            });
    }

    /**
     * "Terbitkan sebagai berita": modul konten aktif, policy `publish`, dan prestasi layak terbit.
     */
    public static function publishAction(): Action
    {
        return Action::make('publish')
            ->label(__('pondok.achievement.publish_action'))
            ->icon(Heroicon::OutlinedNewspaper)
            ->color('gray')
            ->authorize(fn (Achievement $record): bool => Setting::moduleEnabled('konten')
                && $record->isPublishable()
                && Gate::allows('publish', $record))
            ->requiresConfirmation()
            ->modalHeading(__('pondok.achievement.publish_action'))
            ->modalDescription(__('pondok.achievement.publish_description'))
            ->action(function (Achievement $record, PublishAchievementAsPost $publish, Action $action): void {
                $post = static::runNotifyingErrors($action, fn () => $publish->handle($record, static::user()));

                Notification::make()->title(__('pondok.achievement.published', ['title' => $record->title]))->success()->send();

                if (PostResource::canEdit($post)) {
                    $action->redirect(PostResource::getUrl('edit', ['record' => $post]));
                }
            });
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->label(__('pondok.achievement.delete_action'))
            ->modalHeading(__('pondok.achievement.delete_action'))
            ->modalDescription(__('pondok.achievement.delete_description'))
            ->authorize(fn (Achievement $record): bool => static::canDelete($record))
            ->successNotificationTitle(__('pondok.achievement.deleted'))
            ->using(function (Achievement $record, DeleteAchievement $deleteAchievement, DeleteAction $action): bool {
                static::runNotifyingErrors($action, fn () => $deleteAchievement->handle($record, static::user()));

                return true;
            });
    }

    /**
     * Galat Action tampil sebagai notifikasi `danger` dan aksi dihentikan.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $run
     * @return TResult
     */
    public static function runNotifyingErrors(Action $action, Closure $run): mixed
    {
        try {
            return $run();
        } catch (ValidationException $exception) {
            Notification::make()->title((string) collect($exception->errors())->flatten()->first())->danger()->send();
            $action->halt();
        }
    }

    public static function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
