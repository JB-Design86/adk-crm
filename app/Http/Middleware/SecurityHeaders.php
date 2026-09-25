<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nur HTTPS (HTTP wird umgeleitet) und strenge Sicherheits-Header.
 * Die Content-Security-Policy lässt nur Quellen vom eigenen Server zu.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $httpsRequired = app()->isProduction() || config('app.force_https');

        if ($httpsRequired && ! $request->isSecure()) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        $response = $next($request);

        $headers = [
            // Livewire und Alpine (Filament) benötigen Inline-Skripte und eval.
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "frame-ancestors 'none'",
                "form-action 'self'",
                "base-uri 'self'",
                "object-src 'none'",
            ]),
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];

        if ($httpsRequired) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
