<?php

namespace App\Livewire\PortalWali;

use App\Enums\Module;
use App\Models\User;
use App\Notifications\GuardianPortalInvitation;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Atur ulang password portal wali (F079) lewat broker Laravel. Berhasil → `PasswordReset`
 * (listener menandai email terverifikasi), remember token baru, lalu kembali ke halaman masuk
 * tanpa login otomatis. Sesi lain user gugur lewat `auth.session` karena hash password berubah.
 *
 * Penanda `undangan=1` (F081) memilih broker undangan (72 jam); tanpa penanda dipakai broker
 * bawaan (60 menit). Token hanya dikenali tabel brokernya sendiri. Setelah berhasil, token user
 * di broker lain dihapus. Semua kegagalan memakai satu pesan agar tidak membocorkan alasannya.
 */
#[Layout('layouts.public', ['noindex' => true])]
class ResetPassword extends Component
{
    #[Locked]
    public string $token = '';

    #[Locked]
    public bool $invitation = false;

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
        $this->invitation = request()->boolean('undangan');
    }

    public function resetPassword(): void
    {
        // `/livewire/update` tidak melewati middleware route: saklar modul dicek di setiap aksi.
        abort_unless(Module::PortalWali->isEnabled(), 404);

        $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        $broker = $this->invitation ? GuardianPortalInvitation::BROKER : null;
        $resetUser = null;

        $status = Password::broker($broker)->reset(
            ['email' => $this->email, 'password' => $this->password, 'password_confirmation' => $this->password_confirmation, 'token' => $this->token],
            function (User $user, string $password) use (&$resetUser): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

                event(new PasswordReset($user));

                $resetUser = $user;
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->addError('email', __('pondok.portal_wali.reset_invalid'));

            return;
        }

        Password::broker($this->invitation ? null : GuardianPortalInvitation::BROKER)->deleteToken($resetUser);

        session()->flash('status', __('pondok.portal_wali.reset_done'));

        $this->redirectRoute('portal-wali.login');
    }

    public function render(): View
    {
        return view('livewire.portal-wali.reset-password')->title(__('pondok.portal_wali.reset_title'));
    }
}
