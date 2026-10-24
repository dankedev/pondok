<?php

namespace App\Filament\Resources\StudentViolations;

use App\Actions\Kedisiplinan\CancelStudentViolation;
use App\Enums\Module;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\StudentViolations\Pages\CreateStudentViolation;
use App\Filament\Resources\StudentViolations\Pages\EditStudentViolation;
use App\Filament\Resources\StudentViolations\Pages\ListStudentViolations;
use App\Filament\Resources\StudentViolations\Pages\ViewStudentViolation;
use App\Filament\Resources\StudentViolations\Schemas\StudentViolationForm;
use App\Filament\Resources\StudentViolations\Schemas\StudentViolationInfolist;
use App\Filament\Resources\StudentViolations\Tables\StudentViolationsTable;
use App\Models\StudentViolation;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Pelanggaran santri (F026). Catat, ubah, dan batal lewat `App\Actions\Kedisiplinan\*`; tanpa hapus.
 * Cakupan jenjang dan musyrif dari global scope `visible` pada `StudentViolation`.
 */
class StudentViolationResource extends Resource
{
    use BelongsToModule;
    use ShowsActionErrorsOnForm;

    protected static ?string $model = StudentViolation::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?int $navigationSort = 1;

    public static function getModule(): string
    {
        return Module::Kedisiplinan->value;
    }

    public static function getModelLabel(): string
    {
        return __('pondok.kedisiplinan.violation.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.kedisiplinan.violation.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.kedisiplinan');
    }

    public static function form(Schema $schema): Schema
    {
        return StudentViolationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StudentViolationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StudentViolationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudentViolations::route('/'),
            'create' => CreateStudentViolation::route('/create'),
            'view' => ViewStudentViolation::route('/{record}'),
            'edit' => EditStudentViolation::route('/{record}/edit'),
        ];
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('pondok.kedisiplinan.violation.cancel.action'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('pondok.kedisiplinan.violation.cancel.heading'))
            ->modalDescription(__('pondok.kedisiplinan.violation.cancel.description'))
            ->modalSubmitActionLabel(__('pondok.kedisiplinan.violation.cancel.action'))
            ->authorize(fn (StudentViolation $record): bool => Gate::allows('cancel', $record))
            ->visible(fn (StudentViolation $record): bool => ! $record->isCancelled())
            ->schema([
                Textarea::make('cancel_reason')
                    ->label(__('pondok.kedisiplinan.fields.cancel_reason'))
                    ->placeholder(__('pondok.kedisiplinan.violation.cancel.reason_placeholder'))
                    ->rows(2)
                    ->maxLength(StudentViolation::CANCEL_REASON_MAX_LENGTH)
                    ->required(),
            ])
            ->action(function (StudentViolation $record, array $data, Action $action, CancelStudentViolation $cancel): void {
                static::runShowingFormErrors($action, fn () => $cancel->handle(static::user(), $record, (string) $data['cancel_reason']));

                Notification::make()
                    ->title(__('pondok.kedisiplinan.violation.cancel.done', ['name' => $record->student?->name]))
                    ->success()
                    ->send();
            });
    }

    public static function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
