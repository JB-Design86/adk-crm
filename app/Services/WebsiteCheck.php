<?php

namespace App\Services;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Website-Check (config adk.website_check): ruft die Startseite eines Betriebs einmal auf, wie ein
 * Browser, und liest nur, was jeder Besucher sieht: die PHP-Version aus den Kopfzeilen (X-Powered-By,
 * Server) und das System aus <meta name="generator">. Keine weiteren Seiten, kein Scannen.
 *
 * Der Server ruft hier Adressen aus Leadlisten auf. Damit niemand über eine präparierte Adresse
 * Dienste auf dem Server selbst oder im internen Netz erreicht, gehen nur http/https auf Port 80/443
 * an öffentliche IP-Adressen, auch bei Weiterleitungen, und die geprüfte IP wird fest verwendet.
 */
class WebsiteCheck
{
    private const MAX_REDIRECTS = 3;

    private const MAX_BODY = 300_000;

    /** @var Closure(string): list<string> Hostname → IP-Adressen */
    private Closure $resolver;

    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? fn (string $host): array => array_values(array_unique(array_merge(
            gethostbynamel($host) ?: [],
            array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        )));
    }

    /** Prüft die Website und speichert das Ergebnis an der Organisation. */
    public function check(Organization $organization): void
    {
        $result = ['php' => null, 'generator' => null, 'note' => null];

        try {
            $result = [...$result, ...self::parse($this->fetch((string) self::url($organization->website)))];
        } catch (RuntimeException $exception) {
            $result['note'] = Str::limit($exception->getMessage(), 250);
        }

        $organization->forceFill([
            'website_php' => $result['php'],
            'website_generator' => $result['generator'],
            'website_check_note' => $result['note'],
            'website_checked_at' => now(),
        ])->saveQuietly();
    }

    /**
     * PHP-Version und System aus Kopfzeilen und HTML.
     *
     * @return array{php: ?string, generator: ?string, note: ?string}
     */
    public static function parse(Response $response): array
    {
        $headers = $response->header('X-Powered-By')."\n".$response->header('Server');
        $php = preg_match('#PHP/(\d+\.\d+(?:\.\d+)?)#i', $headers, $match) ? $match[1] : null;

        $html = substr($response->body(), 0, self::MAX_BODY);
        preg_match_all('#<meta\b[^>]*\bname\s*=\s*["\']generator["\'][^>]*>#i', $html, $tags);
        $generators = collect($tags[0])
            ->map(fn (string $tag) => preg_match('#\bcontent\s*=\s*["\']([^"\']{1,80})["\']#i', $tag, $m) ? trim(html_entity_decode($m[1])) : null)
            ->filter()
            ->unique()
            ->take(2)
            ->implode(', ');

        return [
            'php' => $php,
            'generator' => $generators !== '' ? Str::limit($generators, 100, '') : null,
            'note' => $php === null && $generators === '' ? 'Keine Version sichtbar.' : null,
        ];
    }

    /** Website-Adresse zum Öffnen, mit https:// davor, wenn das Schema fehlt. */
    public static function url(?string $website): ?string
    {
        $website = trim((string) $website);

        if ($website === '') {
            return null;
        }

        return preg_match('#^https?://#i', $website) ? $website : 'https://'.$website;
    }

    /**
     * „eol“ = Zweig ohne Sicherheitsupdates, „unpatched“ = Zweig gepflegt, aber Sicherheitsupdate fehlt, sonst null.
     */
    public static function phpStatus(?string $version): ?string
    {
        if ($version === null || ! preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $version, $m)) {
            return null;
        }

        $config = config('adk.website_check');
        $branch = "{$m[1]}.{$m[2]}";

        if (version_compare($branch, $config['php_supported_from'], '<')) {
            return 'eol';
        }

        $patched = $config['php_patched'][$branch] ?? null;

        // Nur mit Patch-Nummer vergleichbar: „8.3“ allein sagt nichts über den Stand.
        return $patched !== null && isset($m[3]) && version_compare($version, $patched, '<') ? 'unpatched' : null;
    }

    public static function isOutdated(?Organization $organization): bool
    {
        return self::phpStatus($organization?->website_php) !== null;
    }

    /** Kurzfassung für Vorgang und Anrufliste, z. B. „PHP 7.4.33, ohne Sicherheitsupdates · WordPress 6.2 · geprüft am 23.10.2026“. */
    public static function summary(?Organization $organization): ?string
    {
        if ($organization?->website_checked_at === null) {
            return null;
        }

        $php = $organization->website_php;
        $parts = array_filter([
            $php ? 'PHP '.$php.match (self::phpStatus($php)) {
                'eol' => ', bekommt keine Sicherheitsupdates mehr',
                'unpatched' => ', Sicherheitsupdate Oktober 2026 fehlt (mind. '.config('adk.website_check.php_patched')[preg_replace('/^(\d+\.\d+).*/', '$1', $php)].')',
                default => '',
            } : null,
            $organization->website_generator,
            $organization->website_check_note,
            'geprüft am '.$organization->website_checked_at->format('d.m.Y'),
        ]);

        return implode(' · ', $parts);
    }

    /** Startseite holen, Weiterleitungen einzeln prüfen. */
    private function fetch(string $url): Response
    {
        $config = config('adk.website_check');

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $port, $ip] = $this->target($url);

            try {
                $response = Http::withHeaders(['User-Agent' => $config['user_agent'], 'Accept' => 'text/html,*/*;q=0.5'])
                    ->timeout((int) $config['timeout'])
                    ->connectTimeout(5)
                    ->withOptions([
                        'allow_redirects' => false,
                        // Genau die geprüfte Adresse verwenden, keine zweite DNS-Abfrage.
                        'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($ip, ':') ? "[{$ip}]" : $ip)]],
                    ])
                    ->get($url);
            } catch (ConnectionException) {
                throw new RuntimeException('Website nicht erreichbar.');
            }

            if ($response->redirect() && filled($location = $response->header('Location'))) {
                $url = (string) Str::of($location)->trim()->whenStartsWith('/', fn ($path) => $path->prepend(self::origin($url)));

                continue;
            }

            if ($response->failed()) {
                throw new RuntimeException('Website antwortet mit Fehler '.$response->status().'.');
            }

            return $response;
        }

        throw new RuntimeException('Zu viele Weiterleitungen.');
    }

    /** @return array{0: string, 1: int, 2: string} Host, Port, öffentliche IP-Adresse */
    private function target(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Keine gültige Website-Adresse.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, [80, 443], true)) {
            throw new RuntimeException('Nur die Ports 80 und 443 werden aufgerufen.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);
        $public = array_values(array_filter($ips, fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)));

        // Zeigt eine Adresse auch nur teilweise ins interne Netz, wird nichts aufgerufen.
        if ($ips === [] || count($public) !== count($ips)) {
            throw new RuntimeException($ips === [] ? 'Domain nicht gefunden.' : 'Adresse nicht öffentlich erreichbar.');
        }

        return [$host, $port, $public[0]];
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
