<?php

namespace App\Filament\Resources\LeaveRequests;

use App\Actions\Perizinan\CancelLeaveRequest;
use App\Actions\Perizinan\CheckInLeaveRequest;
use App\Actions\Perizinan\CheckOutLeaveRequest;
use App\Actions\Perizinan\DecideLeaveRequest;
use App\Enums\LeaveRequestStatus;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\LeaveRequests\Pages\CreateLeaveRequest;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Resources\LeaveRequests\Schemas\LeaveRequestForm;
use App\Filament\Resources\LeaveRequests\Schemas\LeaveRequestInfolist;
use App\Filament\Resources\LeaveRequests\Tables\LeaveRequestsTable;
use App\Models\LeaveRequest;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Izin keluar/pulang santri (F024). Ajukan lewat `SubmitLeaveRequest`; setujui, tolak, catat keluar/kembali,
 * dan batalkan lewat aksi baris yang memanggil `App\Actions\Perizinan\*`. Tanpa ubah dan tanpa hapus.
 * Cakupan jenjang, musyrif, dan pos dari global scope `LeaveRequest`.
 */
class LeaveRequestResource extends Resource
{
    use BelongsToModule;
    use ShowsActionErrorsOnForm;

    protected static ?string $model = LeaveRequest::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightStartOnRectangle;

    protected static ?int $navigationSort = 1;

    public static function getModule(): string
    {
        return 'perizinan';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.leave_request.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.leave_request.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.perizinan');
    }

    public static function form(Schema $schema): Schema
    {
        return LeaveRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LeaveRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeaveRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeaveRequests::route('/'),
            'create' => CreateLeaveRequest::route('/create'),
        ];
    }

    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label(__('pondok.leave_request.actions.approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('pondok.leave_request.actions.approve_description'))
            ->authorize(fn (LeaveRequest $record): bool => Gate::allows('decide', $record))
            ->action(fn (LeaveRequest $record, Action $action, DecideLeaveRequest $decide) => static::runLeaveAction(
                $action,
                fn () => $decide->handle($record, static::user(), true),
                __('pondok.leave_request.notifications.approved', ['name' => $record->student?->name]),
            ));
    }

    public static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label(__('pondok.leave_request.actions.reject'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalDescription(__('pondok.leave_request.actions.reject_description'))
            ->modalSubmitActionLabel(__('pondok.leave_request.actions.reject'))
            ->authorize(fn (LeaveRequest $record): bool => Gate::allows('decide', $record))
            ->schema([
                Textarea::make('note')
                    ->label(__('pondok.leave_request.actions.reject_note'))
                    ->rows(3)
                    ->maxLength(LeaveRequest::TEXT_MAX_LENGTH)
                    ->required(),
            ])
            ->action(fn (LeaveRequest $record, array $data, Action $action, DecideLeaveRequest $decide) => static::runLeaveAction(
                $action,
                fn () => $decide->handle($record, static::user(), false, $data['note'] ?? null),
                __('pondok.leave_request.notifications.rejected', ['name' => $record->student?->name]),
            ));
    }

    public static function checkOutAction(): Action
    {
        return Action::make('checkOut')
            ->label(__('pondok.leave_request.actions.check_out'))
            ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
            ->color('info')
            ->requiresConfirmation()
            ->modalDescription(__('pondok.leave_request.actions.check_out_description'))
            ->authorize(fn (LeaveRequest $record): bool => Gate::allows('checkInOut', $record))
            ->visible(fn (LeaveRequest $record): bool => $record->status === LeaveRequestStatus::Approved)
            ->action(fn (LeaveRequest $record, Action $action, CheckOutLeaveRequest $checkOut) => static::runLeaveAction(
                $action,
                fn () => $checkOut->handle($record, static::user()),
                __('pondok.leave_request.notifications.checked_out', ['name' => $record->student?->name]),
            ));
    }

    public static function checkInAction(): Action
    {
        return Action::make('checkIn')
            ->label(__('pondok.leave_request.actions.check_in'))
            ->icon(Heroicon::OutlinedArrowRightEndOnRectangle)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('pondok.leave_request.actions.check_in_description'))
            ->authorize(fn (LeaveRequest $record): bool => Gate::allows('checkInOut', $record))
            ->visible(fn (LeaveRequest $record): bool => $record->status === LeaveRequestStatus::Out)
            ->action(fn (LeaveRequest $record, Action $action, CheckInLeaveRequest $checkIn) => static::runLeaveAction(
                $action,
                fn () => $checkIn->handle($record, static::user()),
                __('pondok.leave_request.notifications.checked_in', ['name' => $record->student?->name]),
            ));
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('pondok.leave_request.actions.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('pondok.leave_request.actions.cancel_description'))
            ->modalSubmitActionLabel(__('pondok.leave_request.actions.cancel'))
            ->authorize(fn (LeaveRequest $record): bool => Gate::allows('cancel', $record))
            ->visible(fn (LeaveRequest $record): bool => in_array($record->status, [LeaveRequestStatus::Submitted, LeaveRequestStatus::Approved], true))
            ->schema([
                Textarea::make('note')
                    ->label(__('pondok.leave_request.actions.cancel_note'))
                    ->rows(2)
                    ->maxLength(LeaveRequest::TEXT_MAX_LENGTH),
            ])
            ->action(fn (LeaveRequest $record, array $data, Action $action, CancelLeaveRequest $cancel) => static::runLeaveAction(
                $action,
                fn () => $cancel->handle($record, static::user(), $data['note'] ?? null),
                __('pondok.leave_request.notifications.cancelled', ['name' => $record->student?->name]),
            ));
    }

    /**
     * Jalankan transisi izin. Galat catatan tampil di field; galat lain (status sudah berubah di tempat lain,
     * hak putus berpindah) menjadi notifikasi `danger` lalu modal ditutup.
     */
    protected static function runLeaveAction(Action $action, Closure $run, string $successTitle): void
    {
        try {
            $run();
        } catch (ValidationException $exception) {
            if (array_key_exists('note', $exception->errors())) {
                static::runShowingFormErrors($action, fn () => throw $exception);
            }

            static::failAction($action, collect($exception->errors())->flatten()->first());

            return;
        } catch (AuthorizationException $exception) {
            static::failAction($action, $exception->getMessage());

            return;
        }

        Notification::make()->title($successTitle)->success()->send();
    }

    protected static function failAction(Action $action, ?string $message): void
    {
        Notification::make()->title($message ?: __('pondok.leave_request.errors.forbidden'))->danger()->send();

        $action->cancel();
    }

    public static function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
