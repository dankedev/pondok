<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Email undangan akun portal wali (F081): tautan atur password dari broker
 * `guardian_invitations` (berlaku 72 jam), terpisah dari alur "Lupa password".
 */
class GuardianPortalInvitation extends Notification
{
    public const BROKER = 'guardian_invitations';

    public function __construct(public string $token) {}

    /**
     * Membuat token baru di broker undangan (token lama email itu gugur) lalu mengirim undangan.
     * Dipakai saat akun dibuat (F081) dan saat undangan dikirim ulang (F084).
     */
    public static function sendTo(User $user): void
    {
        $token = Password::broker(self::BROKER)->createToken($user);

        $user->notify(new self($token));
    }

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = route('portal-wali.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
            'undangan' => 1,
        ]);

        return (new MailMessage)
            ->subject(__('pondok.portal_wali.invitation.subject'))
            ->greeting(__('pondok.portal_wali.invitation.greeting', ['name' => $notifiable->name]))
            ->line(__('pondok.portal_wali.invitation.intro'))
            ->action(__('pondok.portal_wali.invitation.action'), $url)
            ->line(__('pondok.portal_wali.invitation.expires', [
                'hours' => intdiv((int) config('auth.passwords.'.self::BROKER.'.expire'), 60),
            ]))
            ->line(__('pondok.portal_wali.invitation.expired_hint', ['url' => route('portal-wali.password.request')]))
            ->line(__('pondok.portal_wali.invitation.ignore'));
    }
}
