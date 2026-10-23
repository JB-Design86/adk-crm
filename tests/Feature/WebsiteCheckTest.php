<?php

use App\Models\Lead;
use App\Models\Organization;
use App\Services\WebsiteCheck;
use Illuminate\Support\Facades\Http;

/** Website-Check mit fester Namensauflösung (keine echten DNS-Abfragen im Test). */
function websiteChecker(array $ips = ['93.184.216.34']): WebsiteCheck
{
    return new WebsiteCheck(fn (string $host) => $ips);
}

it('liest PHP-Version und System von der Startseite', function () {
    Http::fake(['alt.example*' => Http::response(
        '<html><head><meta name="generator" content="WordPress 5.8.1" /><meta name="generator" content="Elementor 3.5"></head></html>',
        200,
        ['X-Powered-By' => 'PHP/7.4.33'],
    )]);
    $organization = Organization::factory()->create(['website' => 'alt.example']);

    websiteChecker()->check($organization);

    expect($organization->fresh())
        ->website_php->toBe('7.4.33')
        ->website_generator->toBe('WordPress 5.8.1, Elementor 3.5')
        ->website_checked_at->not->toBeNull()
        ->and(WebsiteCheck::isOutdated($organization->fresh()))->toBeTrue()
        ->and(WebsiteCheck::summary($organization->fresh()))->toContain('PHP 7.4.33, bekommt keine Sicherheitsupdates mehr')->toContain('WordPress 5.8.1');

    Http::assertSent(fn ($request) => $request->url() === 'https://alt.example' && str_contains($request->header('User-Agent')[0], 'JB-IT-Design-Websitecheck'));
});

it('erkennt fehlende Sicherheitsupdates je PHP-Zweig', function () {
    expect(WebsiteCheck::phpStatus('7.4.33'))->toBe('eol')
        ->and(WebsiteCheck::phpStatus('8.1.30'))->toBe('eol')
        ->and(WebsiteCheck::phpStatus('8.3.20'))->toBe('unpatched')
        ->and(WebsiteCheck::phpStatus('8.3.36'))->toBeNull()
        ->and(WebsiteCheck::phpStatus('8.4'))->toBeNull()
        ->and(WebsiteCheck::phpStatus(null))->toBeNull();
});

it('ruft keine internen Adressen auf, auch nicht über Weiterleitungen', function () {
    Http::fake(['*' => Http::response('', 301, ['Location' => 'http://127.0.0.1/admin'])]);

    $redirect = Organization::factory()->create(['website' => 'https://umleitung.example']);
    websiteChecker()->check($redirect);

    $otherPort = Organization::factory()->create(['website' => 'https://plesk.example:8443']);
    websiteChecker()->check($otherPort);

    $internal = Organization::factory()->create(['website' => 'https://intern.example']);
    websiteChecker(['93.184.216.34', '10.0.0.5'])->check($internal);

    expect($redirect->fresh()->website_check_note)->toBe('Adresse nicht öffentlich erreichbar.')
        ->and($otherPort->fresh()->website_check_note)->toBe('Nur die Ports 80 und 443 werden aufgerufen.')
        ->and($internal->fresh()->website_check_note)->toBe('Adresse nicht öffentlich erreichbar.');

    // Nur die erste Anfrage an umleitung.example ging raus, nichts an 127.0.0.1, Port 8443 oder 10.0.0.5.
    Http::assertSentCount(1);
});

it('setzt den Website-Check zurück, wenn sich die Website ändert', function () {
    $organization = Organization::factory()->create(['website_php' => '7.4.33', 'website_checked_at' => now()]);

    $organization->update(['website' => 'https://neu.example']);

    expect($organization->fresh())->website_php->toBeNull()->website_checked_at->toBeNull();
});

it('prüft per Befehl die Websites offener Vorgänge von JB IT-Design', function () {
    Http::fake(['*' => Http::response('<html></html>', 200, ['X-Powered-By' => 'PHP/8.2.10'])]);
    app()->instance(WebsiteCheck::class, websiteChecker());
    $jb = Lead::factory()->create(['brand' => 'jb', 'organization_id' => Organization::factory()->state(['website' => 'https://jb.example'])]);
    $adk = Lead::factory()->create(['organization_id' => Organization::factory()->state(['website' => 'https://adk.example'])]);

    $this->artisan('adk:website-check')->assertSuccessful();

    expect(Organization::find($jb->organization_id)->website_php)->toBe('8.2.10')
        ->and(Organization::find($adk->organization_id)->website_checked_at)->toBeNull();
});
