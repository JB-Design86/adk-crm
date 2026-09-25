<?php

namespace App\Http\Middleware;

use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Auth\AuthenticationException;

/**
 * Anmeldeprüfung des Panels. Gesperrte Konten werden sofort abgemeldet,
 * auch mitten in einer laufenden Sitzung, und zur Anmeldung umgeleitet.
 */
class EnsureUserIsNotBlocked extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $guard = Filament::auth();

        if ($guard->check() && $guard->user()->is_blocked) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new AuthenticationException('Ihr Konto ist gesperrt.', $guards, $this->redirectTo($request));
        }

        parent::authenticate($request, $guards);
    }
}
