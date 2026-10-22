<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Core\MarkUserEmailVerified;
use App\Actions\Core\ResetUserMultiFactor;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // F055: operasi keamanan akun khusus super-admin, tidak untuk akun sendiri.
            Action::make('resetMultiFactor')
                ->label(__('pondok.user.security.reset_mfa'))
                ->icon('heroicon-o-shield-exclamation')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(fn (User $record): string => __('pondok.user.security.reset_mfa_heading', ['name' => $record->name]))
                ->modalDescription(__('pondok.user.security.reset_mfa_description'))
                ->modalSubmitActionLabel(__('pondok.user.security.reset_mfa_submit'))
                ->visible(fn (User $record): bool => $this->canManageSecurityOf($record) && filled($record->getAppAuthenticationSecret()))
                ->action(function (User $record): void {
                    app(ResetUserMultiFactor::class)->handle(auth()->user(), $record);

                    Notification::make()
                        ->title(__('pondok.user.security.reset_mfa_done', ['name' => $record->name]))
                        ->success()
                        ->send();
                }),
            Action::make('markEmailVerified')
                ->label(__('pondok.user.security.mark_email_verified'))
                ->icon('heroicon-o-check-badge')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(fn (User $record): string => __('pondok.user.security.mark_email_verified_heading', ['name' => $record->name]))
                ->modalDescription(__('pondok.user.security.mark_email_verified_description'))
                ->modalSubmitActionLabel(__('pondok.user.security.mark_email_verified_submit'))
                ->visible(fn (User $record): bool => $this->canManageSecurityOf($record) && ! $record->hasVerifiedEmail())
                ->action(function (User $record): void {
                    app(MarkUserEmailVerified::class)->handle(auth()->user(), $record);

                    Notification::make()
                        ->title(__('pondok.user.security.mark_email_verified_done', ['name' => $record->name]))
                        ->success()
                        ->send();
                }),
            // Approver keringanan, pembuat tagihan, serta penerima dan pembatal pembayaran
            // dirujuk FK restrict; user itu dinonaktifkan, bukan dihapus. Data keuangan
            // dicek tanpa cakupan jenjang, begitu juga penerbit dan pembatal surat keluar serta
            // pencatat dan verifikator prestasi.
            DeleteAction::make()
                ->hidden(fn (): bool => $this->getRecord()->is(auth()->user())
                    || $this->getRecord()->approvedFeeOverrides()->exists()
                    || $this->getRecord()->generatedInvoices()->withoutGlobalScopes()->exists()
                    || $this->getRecord()->receivedPayments()->withoutGlobalScopes()->exists()
                    || $this->getRecord()->cancelledPayments()->withoutGlobalScopes()->exists()
                    || $this->getRecord()->issuedLetters()->withoutGlobalScopes()->exists()
                    || $this->getRecord()->cancelledLetters()->withoutGlobalScopes()->exists()
                    || $this->getRecord()->recordedAchievements()->withoutGlobalScopes()->exists()
                    || $this->getRecord()->verifiedAchievements()->withoutGlobalScopes()->exists()),
        ];
    }

    private function canManageSecurityOf(User $record): bool
    {
        return auth()->user()?->hasRole('super-admin') && ! $record->is(auth()->user());
    }
}
