<?php

namespace App\Filament\Resources\Dormitories\RelationManagers;

use App\Actions\Asrama\SaveRoom;
use App\Models\Dormitory;
use App\Models\Room;
use App\Models\Scopes\UnitScope;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

/**
 * Kamar milik asrama. Simpan selalu lewat Action `SaveRoom` agar aturan
 * kapasitas berlaku.
 */
class RoomsRelationManager extends RelationManager
{
    protected static string $relationship = 'rooms';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('pondok.room.plural');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('pondok.room.name'))
                    ->helperText(__('pondok.room.name_helper'))
                    ->required()
                    ->maxLength(50)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('dormitory_id', $this->getOwnerRecord()->getKey()),
                    )
                    ->validationMessages(['unique' => __('pondok.room.unique')]),
                TextInput::make('floor')
                    ->label(__('pondok.room.floor'))
                    ->integer()
                    ->minValue(0)
                    ->maxValue(255),
                TextInput::make('capacity')
                    ->label(__('pondok.room.capacity'))
                    ->helperText(__('pondok.room.capacity_helper'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->required(),
                Toggle::make('is_active')
                    ->label(__('pondok.common.is_active'))
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        $withoutUnitScope = fn (Builder $query) => $query->withoutGlobalScope(UnitScope::class);

        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'assignments' => $withoutUnitScope,
                'activeAssignments' => $withoutUnitScope,
                'supervisors',
            ]))
            ->columns([
                TextColumn::make('name')
                    ->label(__('pondok.room.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('floor')
                    ->label(__('pondok.room.floor'))
                    ->placeholder('-'),
                TextColumn::make('active_assignments_count')
                    ->label(__('pondok.room.occupancy'))
                    ->formatStateUsing(fn (Room $record): string => "{$record->active_assignments_count} / {$record->capacity}"),
                IconColumn::make('is_active')
                    ->label(__('pondok.common.is_active'))
                    ->boolean(),
            ])
            ->emptyStateHeading(__('pondok.room.empty'))
            ->headerActions([
                CreateAction::make()
                    ->using(fn (array $data, SaveRoom $saveRoom, Action $action): Room => $this->saveRoom(
                        $action,
                        fn (): Room => $saveRoom->handle($this->dormitory(), $data),
                    )),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (Room $record, array $data, SaveRoom $saveRoom, Action $action): Room => $this->saveRoom(
                        $action,
                        fn (): Room => $saveRoom->handle($this->dormitory(), $data, $record),
                    )),
                // Riwayat penempatan dan musyrif menahan penghapusan (FK restrict).
                DeleteAction::make()
                    ->hidden(fn (Room $record): bool => $record->assignments_count > 0 || $record->supervisors_count > 0),
            ]);
    }

    private function dormitory(): Dormitory
    {
        /** @var Dormitory */
        return $this->getOwnerRecord();
    }

    /**
     * @param  Closure(): Room  $save
     */
    private function saveRoom(Action $action, Closure $save): Room
    {
        try {
            return $save();
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->validator->errors()->first())->danger()->send();
            $action->halt();
        }
    }
}
