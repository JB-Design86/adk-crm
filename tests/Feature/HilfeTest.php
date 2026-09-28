<?php

use App\Models\Organization;
use App\Support\Hilfe;

it('liefert Hilfetexte für Felder, Seiten und Status', function () {
    expect(Hilfe::feld('wz_code'))->toContain('Statistischen Bundesamts')
        ->and(Hilfe::feld('organization.priority'))->toBe(Hilfe::feld('priority'))
        ->and(Hilfe::feld('gibt_es_nicht'))->toBeNull()
        ->and(Hilfe::seite('anrufliste'))->toContain('Enter')
        ->and(Hilfe::status('not_reached'))->toContain('2 Arbeitstagen');
});

it('zeigt das Fragezeichen mit Erklärung im Formular der Organisation', function () {
    loginAs('staff');
    $organization = Organization::factory()->create();

    $this->get("/organisationen/{$organization->id}/edit")
        ->assertOk()
        ->assertSee(Hilfe::feld('wz_code'), escape: true)
        ->assertSee(Hilfe::feld('source_url'), escape: true);
});

it('zeigt oben auf jeder Seite, wofür sie da ist', function (string $url, string $key) {
    loginAs('admin');

    $this->get($url)->assertOk()->assertSee(Hilfe::seite($key), escape: true);
})->with([
    ['/', 'heute'],
    ['/anrufliste', 'anrufliste'],
    ['/vorgaenge', 'vorgaenge'],
    ['/kalender', 'kalender'],
    ['/auswertung', 'auswertung'],
    ['/sperrliste', 'sperrliste'],
    ['/import', 'import'],
    ['/pruefstufen', 'pruefstufen'],
]);
