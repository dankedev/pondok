<?php

namespace App\Livewire\PortalWali;

use App\Enums\Module;
use App\Models\User;
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
 */
#[Layout('layouts.public', ['noindex' => true])]
class ResetPassword extends Component
{
    #[Locked]
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
    }

    public function resetPassword(): void
    {
        // `/livewire/update` tidak melewati middleware route: saklar modul dicek di setiap aksi.
        abort_unless(Module::PortalWali->isEnabled(), 404);

        $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            ['email' => $this->email, 'password' => $this->password, 'password_confirmation' => $this->password_confirmation, 'token' => $this->token],
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->addError('email', __($status));

            return;
        }

        session()->flash('status', __('pondok.portal_wali.reset_done'));

        $this->redirectRoute('portal-wali.login');
    }

    public function render(): View
    {
        return view('livewire.portal-wali.reset-password')->title(__('pondok.portal_wali.reset_title'));
    }
}
