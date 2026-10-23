<?php

namespace App\Filament\Resources\HomecomingPeriods;

use App\Actions\Perizinan\GenerateHomecomingLeaves;
use App\Actions\Perizinan\SaveHomecomingPeriod;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\HomecomingPeriods\Pages\ManageHomecomingPeriods;
use App\Models\AcademicYear;
use App\Models\HomecomingPeriod;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Periode pulang massal (F024). Simpan lewat `SaveHomecomingPeriod`; aksi "Buat izin pulang massal" memanggil
 * `GenerateHomecomingLeaves` dan melaporkan jumlah izin dibuat serta santri yang dilewati. Tanpa hapus.
 */
class HomecomingPeriodResource extends Resource
{
    use BelongsToModule;
    use ShowsActionErrorsOnForm;

    protected static ?string $model = HomecomingPeriod::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 4;

    public static function getModule(): string
    {
        return 'perizinan';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.homecoming_period.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.homecoming_period.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.perizinan');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label(__('pondok.homecoming_period.fields.name'))
                    ->placeholder(__('pondok.homecoming_period.name_placeholder'))
                    ->maxLength(255)
                    ->required(),
                Select::make('academic_year_id')
                    ->label(__('pondok.homecoming_period.fields.academic_year_id'))
                    ->options(fn (): array => AcademicYear::query()->orderByDesc('starts_on')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => AcademicYear::current()?->id)
                    ->required(),
                DatePicker::make('depart_on')
                    ->label(__('pondok.homecoming_period.fields.depart_on'))
                    ->required(),
                DatePicker::make('return_on')
                    ->label(__('pondok.homecoming_period.fields.return_on'))
                    ->helperText(__('pondok.homecoming_period.return_on_helper'))
                    ->afterOrEqual('depart_on')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('academicYear')->withCount('leaveRequests'))
            ->defaultSort('depart_on', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('pondok.homecoming_period.fields.name'))
                    ->searchable(),
                TextColumn::make('academicYear.name')
                    ->label(__('pondok.homecoming_period.fields.academic_year_id')),
                TextColumn::make('depart_on')
                    ->label(__('pondok.homecoming_period.fields.depart_on'))
                    ->date('j M Y')
                    ->sortable(),
                TextColumn::make('return_on')
                    ->label(__('pondok.homecoming_period.fields.return_on'))
                    ->date('j M Y'),
                TextColumn::make('leave_requests_count')
                    ->label(__('pondok.homecoming_period.leave_count'))
                    ->alignEnd(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedCalendarDays)
            ->emptyStateHeading(__('pondok.homecoming_period.empty'))
            ->recordActions([
                static::editAction(),
                ActionGroup::make([
                    static::generateAction(),
                ]),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->label(__('pondok.homecoming_period.create_action'))
            ->using(fn (array $data, CreateAction $action, SaveHomecomingPeriod $save): HomecomingPeriod => static::saveShowingFormErrors(
                $action,
                fn (): HomecomingPeriod => $save->handle($data, static::user()),
            ));
    }

    public static function editAction(): EditAction
    {
        return EditAction::make()
            ->using(fn (HomecomingPeriod $record, array $data, EditAction $action, SaveHomecomingPeriod $save): HomecomingPeriod => static::saveShowingFormErrors(
                $action,
                fn (): HomecomingPeriod => $save->handle($data, static::user(), $record),
            ));
    }

    public static function generateAction(): Action
    {
        return Action::make('generate')
            ->label(__('pondok.homecoming_period.generate.action'))
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->requiresConfirmation()
            ->modalHeading(fn (HomecomingPeriod $record): string => __('pondok.homecoming_period.generate.heading', ['name' => $record->name]))
            ->modalDescription(__('pondok.homecoming_period.generate.description'))
            ->modalSubmitActionLabel(__('pondok.homecoming_period.generate.action'))
            ->authorize(fn (HomecomingPeriod $record): bool => Gate::allows('generate', $record))
            ->action(function (HomecomingPeriod $record, Action $action, GenerateHomecomingLeaves $generate): void {
                try {
                    $result = $generate->handle($record, static::user());
                } catch (ValidationException|AuthorizationException $exception) {
                    $message = $exception instanceof ValidationException
                        ? collect($exception->errors())->flatten()->first()
                        : $exception->getMessage();

                    Notification::make()->title($message)->danger()->send();
                    $action->cancel();

                    return;
                }

                Notification::make()
                    ->title(__('pondok.homecoming_period.generate.done', ['name' => $record->name]))
                    ->body(__('pondok.homecoming_period.generate.result', $result))
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageHomecomingPeriods::route('/'),
        ];
    }

    public static function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
