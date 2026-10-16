<?php

namespace App\Filament\Resources\Achievements\Tables;

use App\Filament\Resources\Achievements\AchievementResource;
use App\Models\AcademicYear;
use App\Models\Achievement;
use App\Models\Scopes\UnitScope;
use App\Models\Unit;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AchievementsTable
{
    /** Nama peserta yang ditampilkan per baris sebelum "dan :count lainnya". */
    public const VISIBLE_PARTICIPANTS = 3;

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('achieved_on')->orderByDesc('id'))
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['students' => fn ($query) => $query->orderBy('name'), 'academicYear', 'recorder', 'verifier', 'post'])
                ->withExists(['documents as has_documents' => fn (Builder $query) => $query->withoutGlobalScope(UnitScope::class)])
                ->withOutsideParticipantsFor(AchievementResource::user()))
            ->columns([
                TextColumn::make('achieved_on')
                    ->label(__('pondok.achievement.achieved_on'))
                    ->date('j M Y')
                    ->sortable(),
                TextColumn::make('title')
                    ->label(__('pondok.achievement.title'))
                    ->description(fn (Achievement $record): string => $record->event_name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => static::search($query, $search))
                    ->wrap(),
                TextColumn::make('participants')
                    ->label(__('pondok.achievement.participants'))
                    ->state(fn (Achievement $record): ?string => static::participantNames($record, self::VISIBLE_PARTICIPANTS))
                    ->placeholder('-')
                    ->wrap(),
                TextColumn::make('category')
                    ->label(__('pondok.achievement.category'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.achievement.categories.{$state}")),
                TextColumn::make('level')
                    ->label(__('pondok.achievement.level'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.achievement.levels.{$state}")),
                TextColumn::make('verification_status')
                    ->label(__('pondok.achievement.verification_status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("pondok.achievement.verification_statuses.{$state}"))
                    ->color(fn (string $state): string => Achievement::VERIFICATION_COLORS[$state] ?? 'gray'),
                IconColumn::make('is_public')
                    ->label(__('pondok.achievement.is_public'))
                    ->boolean(),
                TextColumn::make('post_status')
                    ->label(__('pondok.achievement.post'))
                    ->state(fn (Achievement $record): ?string => $record->post === null ? null : __('pondok.achievement.post_statuses.'.$record->post->status))
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('academicYear.name')
                    ->label(__('pondok.achievement.academic_year'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('recorder.name')
                    ->label(__('pondok.achievement.recorded_by'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('verifier.name')
                    ->label(__('pondok.achievement.verified_by'))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('academic_year_id')
                    ->label(__('pondok.achievement.academic_year'))
                    ->options(fn (): array => AcademicYear::query()->orderByDesc('starts_on')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => AcademicYear::current()?->id),
                SelectFilter::make('category')
                    ->label(__('pondok.achievement.category'))
                    ->options(__('pondok.achievement.categories')),
                SelectFilter::make('level')
                    ->label(__('pondok.achievement.level'))
                    ->options(__('pondok.achievement.levels')),
                SelectFilter::make('kind')
                    ->label(__('pondok.achievement.kind'))
                    ->options(__('pondok.achievement.kinds')),
                SelectFilter::make('unit')
                    ->label(__('pondok.common.unit'))
                    ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query : $query->forUnit((int) $data['value'])),
                TernaryFilter::make('verified')
                    ->label(__('pondok.achievement.verification_filter'))
                    ->trueLabel(__('pondok.achievement.verification_statuses.terverifikasi'))
                    ->falseLabel(__('pondok.achievement.verification_statuses.belum'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('verified_by'),
                        false: fn (Builder $query): Builder => $query->whereNull('verified_by'),
                    ),
                TernaryFilter::make('is_public')
                    ->label(__('pondok.achievement.is_public')),
                SelectFilter::make('student')
                    ->label(__('pondok.achievement.student_filter'))
                    ->relationship('students', 'name')
                    ->searchable(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedTrophy)
            ->emptyStateHeading(__('pondok.achievement.empty'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('pondok.achievement.create_action'))
                    ->url(fn (): string => AchievementResource::getUrl('create')),
            ])
            ->recordActions([
                EditAction::make()
                    ->authorize(fn (Achievement $record): bool => AchievementResource::canEdit($record)),
                ActionGroup::make([
                    AchievementResource::verifyAction(),
                    AchievementResource::publishAction(),
                    AchievementResource::deleteAction(),
                ]),
            ]);
    }

    /**
     * Nama peserta yang terlihat (urut nama), maksimal `$limit` lalu "dan :count lainnya".
     */
    public static function participantNames(Achievement $record, int $limit): ?string
    {
        $names = $record->students->pluck('name');

        if ($names->isEmpty()) {
            return null;
        }

        $text = $names->take($limit)->implode(', ');

        return $names->count() > $limit ? $text.' '.__('pondok.achievement.others', ['count' => $names->count() - $limit]) : $text;
    }

    /**
     * Judul, nama acara, atau nama/NIS peserta yang terlihat.
     */
    private static function search(Builder $query, string $search): Builder
    {
        $like = '%'.$search.'%';

        return $query->where(fn (Builder $query) => $query
            ->where('title', 'like', $like)
            ->orWhere('event_name', 'like', $like)
            ->orWhereHas('students', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('name', 'like', $like)->orWhere('nis', 'like', $like))));
    }
}
