<?php

namespace App\Livewire\PortalWali;

use App\Enums\Module;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Lupa password portal wali (F079). Tautan hanya dikirim ke wali aktif; pesan layar selalu
 * `passwords.sent` apa pun hasilnya agar keberadaan akun tidak bocor. 5 permintaan per IP per menit.
 */
#[Layout('layouts.public', ['noindex' => true])]
class ForgotPassword extends Component
{
    public const MAX_ATTEMPTS = 5;

    public string $email = '';

    public ?string $status = null;

    public function send(): void
    {
        // `/livewire/update` tidak melewati middleware route: saklar modul dicek di setiap aksi.
        abort_unless(Module::PortalWali->isEnabled(), 404);

        $this->status = null;
        $this->validate(['email' => ['required', 'string', 'email']]);

        $key = 'portal-wali-forgot:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $this->addError('email', __('pondok.portal_wali.errors.too_many_requests', ['seconds' => RateLimiter::availableIn($key)]));

            return;
        }

        RateLimiter::hit($key);

        // Akun dicek sebelum broker: staf tidak dibuatkan token, jadi tautan reset admin mereka tidak tertimpa.
        $user = Password::getUser(['email' => $this->email]);

        if ($user instanceof User && $user->is_active && $user->hasRole('wali')) {
            Password::sendResetLink(['email' => $user->email]);
        }

        $this->reset('email');
        $this->status = __('passwords.sent');
    }

    public function render(): View
    {
        return view('livewire.portal-wali.forgot-password')->title(__('pondok.portal_wali.forgot_title'));
    }
}
