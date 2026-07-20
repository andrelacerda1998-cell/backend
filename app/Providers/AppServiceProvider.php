<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Common\PhoneLoginSmsService;
use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;
use Event;
use GuzzleHttp\Client;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // O spatie/geocoder resolve o Client daqui sem timeout (default Guzzle = espera
        // infinita); com o Google inacessível cada geocode prendia um worker do FPM até
        // ao fatal de 30s. Falhar em segundos deixa os try/catch dos callers atuar.
        $this->app->bind(Client::class, fn () => new Client([
            'timeout' => 5,
            'connect_timeout' => 3,
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // --- Rate limiting da API (ativado por throttleApi() em bootstrap/app.php) ---
        // Base segmentada: autenticado → por utilizador (CGNAT/IP partilhado irrelevante);
        // guest → por IP com teto ALTO, para não gerar 429 falsos em login/signup/checkout
        // atrás de CGNAT de operadora. É a rede que impede o DoS não autenticado.
        RateLimiter::for('api', fn (Request $request) => $request->user()
            ? Limit::perMinute(120)->by('user:'.$request->user()->id)
            : Limit::perMinute(300)->by('ip:'.$request->ip()));

        // POST /credit-card: não autenticado e ~30s/worker — a ponta afiada do DoS (forma
        // do incidente 504). Limite apertado por IP; adicionar cartão é raro mesmo partilhado.
        RateLimiter::for('credit-card', fn (Request $request) =>
            Limit::perMinute(6)->by('ip:'.$request->ip()));

        // OTP: chaveado por TELEMÓVEL (o IP roda-se trivialmente — é o que torna o abuso de
        // OTP explorável). Complementa o bloqueio de 5 min por número no controlador; o teto
        // de IP vem da base 'api'.
        RateLimiter::for('otp', fn (Request $request) =>
            Limit::perMinute(5)->by('phone:'.PhoneLoginSmsService::normalizePhoneNumber((string) $request->input('phone_number'))));

        // Rotas que chamam o geocoder Google (pago): cálculo de preço e pesquisa de zona.
        RateLimiter::for('geocode', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.$request->ip()));

        LanguageSwitch::configureUsing(function (LanguageSwitch $switch) {
            $switch
                ->labels([
                    'pt-pt' => 'Português',
                ])
                ->locales(['pt-pt', 'en']);
        });

        Gate::define('viewPulse', function (User $user) {
            return $user->hasRole('super-admin');
        });

        URL::useOrigin(config('app.url'));

        Storage::disk('local')->buildTemporaryUrlsUsing(function ($path, $expiration, $options) {
            return URL::temporarySignedRoute(
                'storage.local.temp',
                $expiration,
                array_merge($options, ['path' => $path])
            );
        });
    }
}
