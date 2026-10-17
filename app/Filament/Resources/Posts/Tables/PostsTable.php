<?php

namespace App\Filament\Resources\Posts\Tables;

use App\Actions\Konten\DeletePost;
use App\Actions\Konten\RestorePost;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Category;
use App\Models\Post;
use App\Models\Unit;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PostsTable
{
    /** Nilai filter jenjang untuk post umum (`unit_id` NULL). */
    private const GENERAL_UNIT = 'umum';

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'unit', 'author']))
            ->columns([
                TextColumn::make('title')
                    ->label(__('pondok.post.title'))
                    ->description(fn (Post $record): string => $record->slug)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('category.name')
                    ->label(__('pondok.post.category'))
                    ->placeholder('-'),
                TextColumn::make('unit.name')
                    ->label(__('pondok.post.unit'))
                    ->placeholder(__('pondok.post.unit_general')),
                TextColumn::make('author.name')
                    ->label(__('pondok.post.author'))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('published_at')
                    ->label(__('pondok.post.published_at'))
                    ->dateTime('j M Y, H.i')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('pondok.post.status'))
                    ->state(fn (Post $record): string => $record->displayStatus())
                    ->badge()
                    ->color(fn (string $state): string => Post::STATUS_COLORS[$state] ?? 'gray')
                    ->formatStateUsing(fn (string $state): string => __("pondok.post.statuses.{$state}")),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('pondok.post.status'))
                    ->options([
                        Post::STATUS_DRAFT => __('pondok.post.statuses.draft'),
                        Post::STATUS_PUBLISHED => __('pondok.post.statuses.terbit'),
                    ]),
                SelectFilter::make('category_id')
                    ->label(__('pondok.post.category'))
                    ->options(fn (): array => Category::query()->ofType(Category::TYPE_POST)->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('unit_id')
                    ->label(__('pondok.post.unit'))
                    ->options(fn (): array => [
                        self::GENERAL_UNIT => __('pondok.post.unit_general'),
                        ...Unit::query()->orderBy('sort_order')->pluck('name', 'id')->all(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        self::GENERAL_UNIT => $query->whereNull('unit_id'),
                        default => $query->where('unit_id', $data['value']),
                    }),
                TrashedFilter::make(),
            ])
            ->emptyStateHeading(__('pondok.post.empty'))
            ->emptyStateIcon(Heroicon::OutlinedNewspaper)
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('pondok.post.create'))
                    ->url(fn (): string => PostResource::getUrl('create')),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    self::deleteAction(),
                    self::restoreAction(),
                ]),
            ]);
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(__('pondok.post.delete_description'))
            ->using(fn (Post $record, DeletePost $deletePost): bool => tap(true, fn () => $deletePost->handle($record)));
    }

    public static function restoreAction(): RestoreAction
    {
        return RestoreAction::make()
            ->using(fn (Post $record, RestorePost $restorePost): bool => tap(true, fn () => $restorePost->handle($record)));
    }
}
