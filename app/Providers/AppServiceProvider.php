<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        if ($this->app->isProduction() || config('app.force_https')) {
            URL::forceHttps();
        }

        Password::defaults(fn () => Password::min(10));

        // Jedes Recht aus config/adk.php als Gate, geprüft über die Rolle.
        foreach (array_keys(config('adk.permissions')) as $permission) {
            Gate::define($permission, fn (User $user) => ! $user->is_blocked && $user->hasPermission($permission));
        }

        $this->registerAuthLogging();
    }

    /** An- und Abmeldungen sowie Fehlversuche ins Protokoll. */
    private function registerAuthLogging(): void
    {
        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }

            activity('auth')->causedBy($event->user)->event('login')
                ->withProperties(['ip' => request()->ip()])
                ->log('Anmeldung');
        });

        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user) {
                activity('auth')->causedBy($event->user)->event('logout')->log('Abmeldung');
            }
        });

        Event::listen(Failed::class, function (Failed $event) {
            activity('auth')->causedBy($event->user)->event('failed')
                ->withProperties(['email' => $event->credentials['email'] ?? null, 'ip' => request()->ip()])
                ->log('Fehlgeschlagene Anmeldung');
        });

        Event::listen(Lockout::class, function (Lockout $event) {
            activity('auth')->event('lockout')
                ->withProperties(['ip' => $event->request->ip()])
                ->log('Anmeldung vorübergehend gesperrt (zu viele Versuche)');
        });
    }
}
