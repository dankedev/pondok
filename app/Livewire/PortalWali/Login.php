<?php

namespace App\Livewire\PortalWali;

use App\Enums\Module;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Halaman masuk portal wali (F079), guard `web`. Hanya wali aktif yang bukan staf: staf
 * (termasuk staf yang juga wali) masuk lewat `/admin` agar MFA panel admin tidak terlewati.
 * Semua penolakan memakai pesan umum `auth.failed`; 5 gagal per email+IP dikunci sementara.
 */
#[Layout('layouts.public', ['noindex' => true])]
class Login extends Component
{
    public const MAX_ATTEMPTS = 5;

    public string $email = '';

    public string $password = '';

    public function authenticate(): void
    {
        // `/livewire/update` tidak melewati middleware route: saklar modul dicek di setiap aksi.
        abort_unless(Module::PortalWali->isEnabled(), 404);

        $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = Str::transliterate(Str::lower($this->email).'|'.request()->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $eligible = fn (User $user): bool => $user->is_active && $user->hasRole('wali') && ! $user->can('view-dashboard');

        if (! Auth::guard('web')->attemptWhen(['email' => $this->email, 'password' => $this->password], $eligible)) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);
        session()->regenerate();

        $this->redirectIntended(route('portal-wali.home'));
    }

    public function render(): View
    {
        return view('livewire.portal-wali.login')->title(__('pondok.portal_wali.login_title'));
    }
}
