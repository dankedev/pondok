<?php

namespace App\Providers;

use App\Models\Document;
use App\Models\Setting;
use App\Models\User;
use ArrayObject;
use Carbon\Carbon;
use Carbon\Translator as CarbonTranslator;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(Setting::CACHE_BINDING, fn (): ArrayObject => new ArrayObject);

        $this->configurePublicPath();
    }

    /**
     * `pondok.public_path` (env `PONDOK_PUBLIC_PATH`): document root terpisah
     * (`public_html`), berlaku untuk web dan CLI agar `@vite` dan storage link
     * memakai folder itu.
     */
    private function configurePublicPath(): void
    {
        $path = rtrim((string) config('pondok.public_path'), '/');

        if ($path !== '') {
            $this->app->usePublicPath($path);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureTrustedProxies();
        $this->forceHttpsInProduction();

        // Data lembaga di production tidak boleh terhapus oleh migrate:fresh/refresh/reset atau db:wipe (F064).
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Tidak dipaksakan (`enforceMorphMap`): Spatie Permission dan Activitylog menyimpan nama kelas.
        Relation::morphMap(Document::DOCUMENTABLE_TYPES);

        $this->configureIndonesianMonthAbbreviations();

        // Halaman verifikasi surat publik (F014): 30 permintaan per menit per IP, form dan hasil berbagi batas.
        RateLimiter::for('letter-verification', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->ip()));

        $this->configurePortalWaliAuthUrls();

        Event::listen(function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });
    }

    /**
     * Tautan notifikasi reset password dan verifikasi email bawaan Laravel menuju portal wali (F079).
     * Panel admin tidak terdampak: notifikasi Filament meng-override `resetUrl()`/`verificationUrl()`
     * dengan URL panel. Callback ini global: notifikasi dasar Laravel untuk pengguna lain kelak
     * juga menuju portal wali.
     */
    private function configurePortalWaliAuthUrls(): void
    {
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => route('portal-wali.password.reset', [
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]));

        VerifyEmail::createUrlUsing(fn (User $user): string => URL::temporarySignedRoute(
            'portal-wali.verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        ));
    }

    /**
     * Singkatan bulan Indonesia untuk format `j M Y` (design guide §5): Carbon memakai
     * "Agt" untuk Agustus, panduan memakai tiga huruf awal ("17 Agu 2026").
     */
    private function configureIndonesianMonthAbbreviations(): void
    {
        $messages = ['months_short' => ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des']];

        foreach ([CarbonTranslator::get('id'), Carbon::getTranslator()] as $translator) {
            if ($translator instanceof CarbonTranslator) {
                $translator->setMessages('id', $messages);
            }
        }
    }

    /**
     * Produksi dengan `APP_URL` https: semua URL buatan aplikasi (aset Vite, redirect, link)
     * memakai https walau HTTPS diakhiri di proxy yang tidak meneruskan skemanya.
     */
    private function forceHttpsInProduction(): void
    {
        if ($this->app->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
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
