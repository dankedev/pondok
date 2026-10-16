<?php

namespace App\Providers;

use App\Models\Document;
use App\Models\Setting;
use App\Models\User;
use ArrayObject;
use Carbon\Carbon;
use Carbon\Translator as CarbonTranslator;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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

        // Tidak dipaksakan (`enforceMorphMap`): Spatie Permission dan Activitylog menyimpan nama kelas.
        Relation::morphMap(Document::DOCUMENTABLE_TYPES);

        $this->configureIndonesianMonthAbbreviations();

        // Halaman verifikasi surat publik (F014): 30 permintaan per menit per IP, form dan hasil berbagi batas.
        RateLimiter::for('letter-verification', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->ip()));

        Event::listen(function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });
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
