<?php

namespace App\Providers;

use App\Models\Setting;
use App\Models\User;
use ArrayObject;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(Setting::CACHE_BINDING, fn (): ArrayObject => new ArrayObject);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureTrustedProxies();

        Event::listen(function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });
    }

    /**
     * `pondok.trusted_proxies` (env `TRUSTED_PROXIES`): `*` atau daftar dipisah koma.
     */
    private function configureTrustedProxies(): void
    {
        $proxies = trim((string) config('pondok.trusted_proxies'));

        if ($proxies === '') {
            return;
        }

        TrustProxies::at($proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
    }
}
