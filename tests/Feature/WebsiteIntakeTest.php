<?php

use App\Filament\Pages\CallList;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\BlocklistEntry;
use App\Models\Contact;
use App\Models\DuplicateCandidate;
use App\Models\Lead;
use App\Services\Duplicates\DuplicateResolver;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 6)->setTime(17, 0));
    config(['services.website.intake_secret' => 'geheim-test']);
});

/**
 * Anfrage wie von der Website: JSON-Rohtext, signiert mit dem Testschlüssel. Signatur '' = ohne Kopf.
 * call() übernimmt keine withHeaders(), daher steht der Kopf X-ADK-Signatur direkt in den Server-Variablen.
 */
function websiteEingang(string $body, ?string $signature = null): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    if ($signature !== '') {
        $server['HTTP_X_ADK_SIGNATUR'] = $signature ?? hash_hmac('sha256', $body, 'geheim-test');
    }

    return test()->call('POST', '/api/eingang', [], [], [], $server, $body);
}

function websiteJson(array $payload): string
{
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function kontaktDaten(array $overrides = []): array
{
    return [
        'art' => 'kontakt',
        'vorgang_id' => 17,
        'anliegen' => 'bildungsgutschein',
        'name' => 'Max Muster',
        'email' => 'max@example.org',
        'telefon' => '06131 123456',
        'nachricht' => 'Ich interessiere mich für den Kurs Digitale Büroorganisation.',
        'quelle' => 'kontaktformular',
        'tel_ok' => 1,
        'tel_ok_text' => 'Sie dürfen mich dazu auch anrufen. (freiwillig, jederzeit widerrufbar)',
        'seite' => 'https://adk-akademie.de/kontakt.html',
        'erstellt' => '2026-10-06 16:33:40',
        'ip_gekuerzt' => '91.12.34.0',
        ...$overrides,
    ];
}

function kursheftDaten(array $overrides = []): array
{
    return [
        'art' => 'kursheft',
        'vorgang_id' => 5,
        'name' => 'Erika Muster',
        'email' => 'erika@example.org',
        'telefon' => '0170 1234567',
        'finanzierung' => 'agentur',
        'quelle' => 'website_formular',
        'tel_ok' => 1,
        'tel_ok_text' => 'Sie dürfen mich dazu auch anrufen. (freiwillig, jederzeit widerrufbar)',
        'kontakt_text' => 'Ich möchte das Kursheft per E-Mail erhalten und bin einverstanden, dass die ADK mich dazu per E-Mail berät.',
        'seite' => 'https://adk-akademie.de/weiterbildung.html',
        'angefragt' => '2026-10-06 16:34:18',
        'angefragt_ip' => '91.12.34.0',
        'bestaetigt' => '2026-10-06 16:40:02',
        'bestaetigt_ip' => '91.12.34.0',
        ...$overrides,
    ];
}

it('antwortet mit 503, solange kein Schlüssel eingerichtet ist', function () {
    config(['services.website.intake_secret' => null]);

    websiteEingang(websiteJson(kontaktDaten()))
        ->assertStatus(503)
        ->assertExactJson(['ok' => false, 'fehler' => 'Schnittstelle nicht eingerichtet.']);

    expect(Lead::count())->toBe(0);
});

it('weist Anfragen ohne oder mit falscher Signatur ab', function () {
    $body = websiteJson(kontaktDaten());

    websiteEingang($body, '')->assertStatus(401)->assertExactJson(['ok' => false, 'fehler' => 'Signatur ungültig.']);
    websiteEingang($body, hash_hmac('sha256', $body, 'falscher-schluessel'))->assertStatus(401);
    // Veränderter Inhalt mit der Signatur des Originals
    websiteEingang(str_replace('Max Muster', 'Moritz Muster', $body), hash_hmac('sha256', $body, 'geheim-test'))->assertStatus(401);

    expect(Lead::count())->toBe(0)->and(Contact::count())->toBe(0);
});

it('weist ungültiges JSON und zu große Anfragen ab', function () {
    websiteEingang('{kein json')->assertStatus(400)->assertJson(['ok' => false]);
    websiteEingang('"nur ein Text"')->assertStatus(400);
    websiteEingang(websiteJson(kontaktDaten(['nachricht' => str_repeat('x', 70000)])))->assertStatus(413);

    expect(Lead::count())->toBe(0);
});

it('meldet fehlende Angaben nur mit Feldnamen, ohne Werte', function () {
    $response = websiteEingang(websiteJson(kontaktDaten(['email' => 'max-ohne-at.example.org', 'anliegen' => null, 'erstellt' => null])))
        ->assertStatus(422)
        ->assertJson(['ok' => false, 'fehler' => 'Angaben unvollständig.']);

    expect($response->json('felder'))->toEqualCanonicalizing(['email', 'anliegen', 'erstellt'])
        ->and($response->getContent())->not->toContain('max-ohne-at')->not->toContain('Max Muster');

    expect(websiteEingang(websiteJson(['art' => 'umfrage', 'vorgang_id' => 1]))->assertStatus(422)->json('felder'))->toContain('art', 'email', 'tel_ok');

    expect(Lead::count())->toBe(0)->and(Contact::count())->toBe(0);
});

it('legt aus dem Kontaktformular Kontakt, Vorgang und Notiz an', function () {
    $response = websiteEingang(websiteJson(kontaktDaten()))->assertOk();

    $lead = Lead::sole();
    $contact = $lead->contact;

    $response->assertExactJson(['ok' => true, 'crm_id' => "V-{$lead->id}"]);

    expect($lead)
        ->channel->toBe('website_form')
        ->status->toBe('new')
        ->target_group->toBe('open')
        ->intake_ref->toBe('kontakt-17')
        ->organization_id->toBeNull()
        ->assigned_to->toBeNull()
        ->and($lead->next_action_at->toDateString())->toBe('2026-10-06')
        ->and($lead->last_contact_at->format('Y-m-d H:i:s'))->toBe('2026-10-06 16:33:40');

    expect($contact)
        ->is_private->toBeTrue()
        ->first_name->toBe('Max')
        ->last_name->toBe('Muster')
        ->email->toBe('max@example.org')
        ->phone_e164->toBe('+496131123456')
        ->phone_refused->toBeFalse()
        ->email_consent_at->toBeNull()
        ->and($contact->phone_consent_at->toDateString())->toBe('2026-10-06')
        ->and($contact->phone_consent_proof)
        ->toContain('Website-Kontaktformular, abgesendet am 06.10.2026 um 16:33 Uhr, gekürzte IP 91.12.34.0, Seite https://adk-akademie.de/kontakt.html.')
        ->toContain('Wortlaut: „Sie dürfen mich dazu auch anrufen. (freiwillig, jederzeit widerrufbar)“.')
        ->toContain('Website-Vorgang kontakt-17.')
        ->and($contact->hasPhoneConsent())->toBeTrue();

    $note = $lead->activities()->sole();
    expect($note)
        ->type->toBe('note')
        ->user_id->toBeNull()
        ->and($note->occurred_at->format('Y-m-d H:i'))->toBe('2026-10-06 16:33')
        ->and($note->body)
        ->toStartWith('Eingang über das Website-Kontaktformular')
        ->toContain('Anliegen: Weiterbildung mit Bildungsgutschein')
        ->toContain('Rückruf erlaubt: ja')
        ->toContain('Ich interessiere mich für den Kurs Digitale Büroorganisation.')
        ->toContain('Website-Vorgang: kontakt-17');

    $log = AuditLog::where('log_name', 'intake')->sole();
    expect($log)
        ->event->toBe('received')
        ->subject_id->toBe($lead->id)
        ->and($log->properties->all())->toBe(['ref' => 'kontakt-17', 'art' => 'kontakt']);

    loginAs('staff');
    expect($lead->isCallable())->toBeTrue()
        ->and((new CallList)->queue()->pluck('leads.id')->all())->toBe([$lead->id]);
});

it('ordnet das Anliegen aus dem Kontaktformular einer Zielgruppe zu', function (string $anliegen, string $targetGroup) {
    websiteEingang(websiteJson(kontaktDaten(['anliegen' => $anliegen])))->assertOk();

    expect(Lead::sole()->target_group)->toBe($targetGroup);
})->with([
    ['bildungsgutschein', 'open'],
    ['betrieb', 'company_open'],
    ['einzelkurs', 'self_payer'],
    ['kostentraeger', 'open'],
    ['sonstiges', 'open'],
]);

it('sperrt Anrufe, wenn im Formular kein Rückruf erlaubt wurde', function () {
    websiteEingang(websiteJson(kontaktDaten(['tel_ok' => 0, 'tel_ok_text' => null])))->assertOk();

    $lead = Lead::sole();

    expect($lead->contact)
        ->phone_refused->toBeTrue()
        ->phone_consent_at->toBeNull()
        ->phone_consent_proof->toBeNull()
        ->phone_e164->toBe('+496131123456')
        ->and($lead->callBlockReason())->toContain('nicht angerufen')
        ->and($lead->isCallable())->toBeFalse()
        ->and($lead->activities()->sole()->body)->toContain('Rückruf erlaubt: nein');

    loginAs('staff');
    expect((new CallList)->queue()->pluck('leads.id')->all())->not->toContain($lead->id);
});

it('zeigt am Vorgang Website-Vorgang, „Kein Anruf gewünscht“ und die Einwilligung in E-Mails', function () {
    websiteEingang(websiteJson(kursheftDaten(['tel_ok' => 0, 'telefon' => null])))->assertOk();
    loginAs('staff');

    Livewire::test(ViewLead::class, ['record' => Lead::sole()->id])
        ->assertOk()
        ->assertSee('kursheft-5')
        ->assertSee('Kein Anruf gewünscht')
        ->assertSee('Einwilligung Kontakt per E-Mail am')
        ->assertSee('über den Link in der Bestätigungsmail');
});

it('pflegt „Kein Anruf gewünscht“ und die Einwilligung in E-Mails im Kontaktformular', function () {
    loginAs('staff');
    $contact = Contact::factory()->private()->create();

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->fillForm(['phone_refused' => true, 'email_consent_at' => '2026-10-01'])
        ->call('save')
        ->assertHasFormErrors(['email_consent_proof'])
        ->fillForm(['email_consent_proof' => 'E-Mail vom 01.10.2026'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->fresh())
        ->phone_refused->toBeTrue()
        ->and($contact->fresh()->hasEmailConsent())->toBeTrue();
});

it('legt denselben Website-Vorgang nur einmal an', function () {
    $body = websiteJson(kontaktDaten());

    $first = websiteEingang($body)->assertOk()->json('crm_id');
    $second = websiteEingang($body)->assertOk()->json('crm_id');

    expect($second)->toBe($first)
        ->and(Lead::count())->toBe(1)
        ->and(Contact::count())->toBe(1)
        ->and(Activity::count())->toBe(1)
        ->and(AuditLog::where('log_name', 'intake')->count())->toBe(1);

    // Kursheft mit derselben Nummer ist ein anderer Website-Vorgang.
    websiteEingang(websiteJson(kursheftDaten(['vorgang_id' => 17])))->assertOk();
    expect(Lead::count())->toBe(2);
});

it('legt nichts an, wenn E-Mail oder Telefon auf der Sperrliste stehen', function (array $entry) {
    BlocklistEntry::create([...$entry, 'reason' => 'Werbewiderspruch']);

    websiteEingang(websiteJson(kontaktDaten()))
        ->assertOk()
        ->assertExactJson(['ok' => true, 'crm_id' => null, 'gesperrt' => true]);

    expect(Contact::count())->toBe(0)->and(Lead::count())->toBe(0);

    $log = AuditLog::where('log_name', 'intake')->sole();
    expect($log->event)->toBe('blocked')
        ->and($log->properties->all())->toBe(['ref' => 'kontakt-17'])
        ->and($log->description)->not->toContain('example.org');
})->with([
    'E-Mail' => [['email' => 'MAX@example.org']],
    'Telefon' => [['phone_e164' => '+49 6131 123456']],
]);

it('übernimmt das Kursheft mit Einwilligung in E-Mails und Finanzierung', function () {
    websiteEingang(websiteJson(kursheftDaten()))->assertOk();

    $lead = Lead::sole();
    $contact = $lead->contact;

    expect($lead)
        ->target_group->toBe('B')
        ->intake_ref->toBe('kursheft-5')
        ->channel->toBe('website_form')
        ->and($lead->last_contact_at->format('Y-m-d H:i:s'))->toBe('2026-10-06 16:40:02');

    expect($contact)
        ->first_name->toBe('Erika')
        ->last_name->toBe('Muster')
        ->phone_refused->toBeFalse()
        ->and($contact->email_consent_at->toDateString())->toBe('2026-10-06')
        ->and($contact->hasEmailConsent())->toBeTrue()
        ->and($contact->email_consent_proof)
        ->toContain('Kursheft angefordert am 06.10.2026 um 16:34 Uhr (gekürzte IP 91.12.34.0)')
        ->toContain('bestätigt am 06.10.2026 um 16:40 Uhr (gekürzte IP 91.12.34.0) über den Link in der Bestätigungsmail.')
        ->toContain('Wortlaut: „Ich möchte das Kursheft per E-Mail erhalten')
        ->toContain('Website-Vorgang kursheft-5.')
        ->and($contact->phone_consent_at->toDateString())->toBe('2026-10-06')
        ->and($contact->phone_consent_proof)->toContain('Kursheft-Anforderung mit Bestätigung (Double-Opt-in) am 06.10.2026 um 16:40 Uhr');

    expect($lead->activities()->sole()->body)
        ->toStartWith('Kursheft über die Website angefordert (bestätigt)')
        ->toContain('Finanzierung: Bildungsgutschein der Agentur für Arbeit')
        ->toContain('Website-Vorgang: kursheft-5');
});

it('ordnet die Finanzierung aus dem Kursheft einer Zielgruppe zu', function (?string $finanzierung, string $targetGroup) {
    websiteEingang(websiteJson(kursheftDaten(['finanzierung' => $finanzierung])))->assertOk();

    expect(Lead::sole()->target_group)->toBe($targetGroup);
})->with([
    ['agentur', 'B'],
    ['jobcenter', 'A'],
    ['betrieb', 'company_open'],
    ['selbst', 'self_payer'],
    ['offen', 'open'],
    [null, 'open'],
]);

it('verlangt eine Telefonnummer, wenn Rückruf erlaubt ist', function () {
    $response = websiteEingang(websiteJson(kursheftDaten(['telefon' => null])))->assertStatus(422);
    expect($response->json('felder'))->toBe(['telefon'])->and(Lead::count())->toBe(0);

    // Ohne Rückruf geht es ohne Nummer; ohne Namen wird die E-Mail-Adresse zum Nachnamen.
    websiteEingang(websiteJson(kursheftDaten(['telefon' => null, 'tel_ok' => 0, 'name' => null])))->assertOk();

    expect(Contact::sole())
        ->first_name->toBeNull()
        ->last_name->toBe('erika@example.org')
        ->phone_refused->toBeTrue();
});

it('nimmt eine bereits bekannte Person in die Dublettenprüfung', function () {
    $known = Contact::factory()->private()->create(['first_name' => 'Max', 'last_name' => 'Muster', 'email' => 'max@example.org']);

    websiteEingang(websiteJson(kontaktDaten()))->assertOk();

    $new = Contact::whereKeyNot($known->id)->sole();

    expect(DuplicateCandidate::sole())
        ->type->toBe('contact')
        ->subject_id->toBe($new->id)
        ->match_id->toBe($known->id)
        ->state->toBe('open');

    // Bis zur Entscheidung nicht in der Anrufliste.
    loginAs('staff');
    expect((new CallList)->queue()->pluck('leads.id')->all())->not->toContain(Lead::sole()->id);
});

it('behält beim Zusammenführen „Kein Anruf gewünscht“ und die Einwilligung in E-Mails', function () {
    $known = Contact::factory()->private()->create(['email' => 'erika@example.org']);
    Lead::factory()->create(['organization_id' => null, 'contact_id' => $known->id, 'channel' => 'phone', 'status' => 'interested']);

    websiteEingang(websiteJson(kursheftDaten(['tel_ok' => 0])))->assertOk();
    $user = loginAs('admin');

    app(DuplicateResolver::class)->merge(DuplicateCandidate::sole(), $user);

    $known->refresh();
    expect($known->phone_refused)->toBeTrue()
        ->and($known->hasEmailConsent())->toBeTrue()
        ->and(Lead::where('intake_ref', 'kursheft-5')->sole()->contact_id)->toBe($known->id)
        ->and(Contact::count())->toBe(1);
});
