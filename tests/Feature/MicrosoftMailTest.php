<?php

use App\Filament\Pages\CallList;
use App\Filament\Pages\EmailAccount;
use App\Filament\Resources\EmailTemplates\Pages\CreateEmailTemplate;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\BlocklistEntry;
use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\MailConnection;
use App\Models\Organization;
use App\Models\User;
use App\Services\Microsoft\GraphMailer;
use App\Services\Microsoft\LeadEmail;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    // Donnerstag, 08.10.2026, 10:00 Uhr
    $this->travelTo(now()->setDate(2026, 10, 8)->setTime(10, 0));
    config([
        'services.microsoft.client_id' => 'test-client',
        'services.microsoft.client_secret' => 'test-secret',
        'services.microsoft.tenant' => 'test-tenant',
        'services.microsoft.redirect' => 'https://crm.test/microsoft/callback',
    ]);
    Storage::fake(EmailTemplate::DISK);
    $this->user = loginAs('staff');
});

function mailConnection(User $user, array $attributes = []): MailConnection
{
    return MailConnection::create([
        'user_id' => $user->id,
        'mailbox' => 'max.muster@adk-akademie.test',
        'display_name' => 'Max Muster',
        'access_token' => 'access-alt',
        'refresh_token' => 'refresh-alt',
        'expires_at' => now()->addHour(),
        'signature' => "Max Muster\nVertrieb ADK",
        ...$attributes,
    ]);
}

function mailLead(array $contact = [], array $organization = []): Lead
{
    return Lead::factory()->create([
        'status' => 'interested',
        'organization_id' => Organization::factory()->state(['name' => 'Muster & Söhne GmbH', 'postal_code' => '55116', ...$organization]),
        'contact_id' => Contact::factory()->state(['salutation' => 'Frau', 'first_name' => 'Erika', 'last_name' => 'Muster', 'email' => 'erika.muster@example.org', ...$contact]),
        'next_action_at' => '2026-10-09',
    ]);
}

function templateWithAttachment(string $contents = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n"): EmailTemplate
{
    Storage::disk(EmailTemplate::DISK)->put('email-templates/kursheft.pdf', $contents);

    return EmailTemplate::create([
        'name' => 'Kursheft',
        'subject' => 'Kursheft für {firma}',
        'body' => "{anrede},\n\nanbei das Kursheft.\n\nViele Grüße\n{absender}",
        'attachment_path' => 'email-templates/kursheft.pdf',
        'attachment_name' => 'ADK_Kursheft.pdf',
    ]);
}

function graphAccepts(): void
{
    Http::fake(['graph.microsoft.com/v1.0/me/sendMail' => Http::response(null, 202)]);
}

/** Pflichtangaben für „E-Mail schreiben“. */
function emailData(array $data = []): array
{
    return ['email_subject' => 'Ihre Unterlagen', 'email_text' => 'Guten Tag', 'consent_confirmed' => true, ...$data];
}

it('blendet E-Mail aus dem CRM aus, solange Microsoft 365 nicht eingerichtet ist', function () {
    config(['services.microsoft.client_id' => null]);
    $lead = mailLead();

    Livewire::test(EmailAccount::class)
        ->assertSee('E-Mail aus dem CRM ist noch nicht eingerichtet')
        ->assertDontSee('microsoft/connect');
    Livewire::test(ViewLead::class, ['record' => $lead->id])->assertActionHidden('sendEmail');
    Livewire::test(CallList::class)->assertDontSee('E-Mail schreiben');

    $this->get(route('filament.crm.microsoft.connect'))->assertNotFound();
    $this->get(route('microsoft.callback'))->assertNotFound();
    $this->get(route('filament.crm.microsoft.complete'))->assertNotFound();

    expect(EmailAccount::shouldRegisterNavigation())->toBeFalse();
    config(['services.microsoft.client_id' => 'test-client']);
    expect(EmailAccount::shouldRegisterNavigation())->toBeTrue();
});

it('leitet zur Anmeldung bei Microsoft weiter und merkt sich den Prüfwert', function () {
    $response = $this->get(route('filament.crm.microsoft.connect'));

    $state = session('microsoft_oauth_state');
    $location = $response->assertRedirect()->headers->get('Location');

    expect($state)->toHaveLength(40)
        ->and($location)->toStartWith('https://login.microsoftonline.com/test-tenant/oauth2/v2.0/authorize?')
        ->toContain('client_id=test-client')
        ->toContain('response_type=code')
        ->toContain('response_mode=query')
        ->toContain('prompt=select_account')
        ->toContain('state='.$state)
        ->toContain('redirect_uri='.rawurlencode('https://crm.test/microsoft/callback'))
        ->toContain('scope='.rawurlencode('offline_access openid profile email User.Read Mail.Send'));
});

it('leitet die Rückkehr von Microsoft ohne Sitzung innerhalb des CRM weiter', function () {
    auth()->logout();

    $response = $this->get(route('microsoft.callback', ['code' => 'abc', 'state' => 'pruefwert', 'session_state' => 'x']));

    $target = route('filament.crm.microsoft.complete', ['code' => 'abc', 'state' => 'pruefwert']);
    $response->assertOk()
        ->assertSee('url='.e($target), false)
        ->assertSee('Weiter zum CRM')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    // Kein neues Sitzungscookie, sonst wäre die Anmeldung im CRM überschrieben.
    expect(collect($response->headers->getCookies())->map->getName()->all())->not->toContain(config('session.cookie'));
});

it('verbindet das Postfach nach dem Rückruf und speichert die Tokens verschlüsselt', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'access-neu', 'refresh_token' => 'refresh-neu', 'expires_in' => 3599, 'scope' => 'openid profile email https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read']),
        'graph.microsoft.com/v1.0/me*' => Http::response(['displayName' => 'Max Muster', 'mail' => 'Max.Muster@adk-akademie.test', 'userPrincipalName' => 'max@adk-akademie.test']),
    ]);

    $this->withSession(['microsoft_oauth_state' => 'pruefwert'])
        ->get(route('filament.crm.microsoft.complete', ['code' => 'abc', 'state' => 'pruefwert']))
        ->assertRedirect(EmailAccount::getUrl());

    $connection = $this->user->mailConnection;
    $raw = DB::table('mail_connections')->first();

    expect($connection->mailbox)->toBe('max.muster@adk-akademie.test')
        ->and($connection->display_name)->toBe('Max Muster')
        ->and($connection->access_token)->toBe('access-neu')
        ->and($connection->expires_at->format('H:i'))->toBe('10:59')
        ->and($raw->access_token)->not->toContain('access-neu')
        ->and($raw->refresh_token)->not->toContain('refresh-neu');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://login.microsoftonline.com/test-tenant/oauth2/v2.0/token'
        && $request['grant_type'] === 'authorization_code' && $request['code'] === 'abc'
        && $request['client_secret'] === 'test-secret' && $request['redirect_uri'] === 'https://crm.test/microsoft/callback');
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://graph.microsoft.com/v1.0/me?')
        && $request->hasHeader('Authorization', 'Bearer access-neu'));

    $log = AuditLog::where('log_name', 'microsoft')->where('event', 'connected')->sole();
    expect(json_encode($log->properties))->not->toContain('access-neu')->not->toContain('refresh-neu')->toContain('max.muster@adk-akademie.test');
});

it('lehnt einen Rückruf mit falschem Prüfwert ab', function () {
    Http::fake();

    $this->withSession(['microsoft_oauth_state' => 'pruefwert'])
        ->get(route('filament.crm.microsoft.complete', ['code' => 'abc', 'state' => 'gefälscht']))
        ->assertRedirect(EmailAccount::getUrl());

    expect(MailConnection::count())->toBe(0);
    Http::assertNothingSent();
});

it('verbindet nicht, wenn Microsoft das Recht zum Senden nicht freigibt', function () {
    Http::fake(['login.microsoftonline.com/*' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600, 'scope' => 'openid profile User.Read'])]);

    $this->withSession(['microsoft_oauth_state' => 'pruefwert'])
        ->get(route('filament.crm.microsoft.complete', ['code' => 'abc', 'state' => 'pruefwert']))
        ->assertRedirect(EmailAccount::getUrl());

    expect(MailConnection::count())->toBe(0)
        ->and(json_encode(session('filament.notifications')))->toContain('Mail.Send');
});

it('zeigt auf der Seite E-Mail-Konto den Stand und speichert die Signatur', function () {
    Livewire::test(EmailAccount::class)->assertSee('Nicht verbunden')->assertSee('microsoft/connect')->assertSee('So funktioniert es');

    $connection = mailConnection($this->user, ['signature' => null]);
    $this->user->unsetRelation('mailConnection');

    Livewire::test(EmailAccount::class)
        ->assertSee('Verbunden')
        ->assertSee('max.muster@adk-akademie.test')
        ->set('data.signature_html', '<p><strong>Max Muster</strong><br>Vertrieb · ADK</p><script>alert(1)</script>')
        ->set('data.signature_logo_width', 180)
        ->call('saveSignature')
        ->assertNotified('Signatur gespeichert');

    $connection->refresh();
    expect($connection->signature_html)->toContain('<strong>Max Muster</strong>')->not->toContain('script')
        ->and($connection->signature)->toBeNull()
        ->and($connection->signature_logo_width)->toBe(180);
});

it('übernimmt die bisherige Text-Signatur beim Öffnen in den Editor', function () {
    mailConnection($this->user, ['signature' => "Max Muster\nVertrieb ADK"]);

    Livewire::test(EmailAccount::class)->assertSee('Max Muster');
});

it('sendet die HTML-Signatur bereinigt und das Logo als eingebettetes Bild', function () {
    Storage::fake(MailConnection::LOGO_DISK);
    Storage::disk(MailConnection::LOGO_DISK)->put('signaturen/logo.png', 'PNGDATEN');
    mailConnection($this->user, [
        'signature' => null,
        'signature_html' => '<p><strong>Max Muster</strong></p><p></p><p>ADK</p><script>alert(1)</script>',
        'signature_logo_path' => 'signaturen/logo.png',
        'signature_logo_width' => 220,
    ]);
    $lead = mailLead();
    graphAccepts();

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('sendEmail', emailData(['email_text' => 'Hallo']))
        ->assertHasNoActionErrors();

    Http::assertSent(function (Request $request) {
        $body = $request['message']['body']['content'] ?? '';
        $logo = collect($request['message']['attachments'] ?? [])->firstWhere('isInline', true);

        return str_contains($body, 'Hallo<br>')
            && str_contains($body, '<p style="margin:0 0 12px"><strong>Max Muster</strong></p><p style="margin:0">&nbsp;</p><p style="margin:0 0 12px">ADK</p>')
            && ! str_contains($body, 'script')
            && str_contains($body, '<img src="cid:adk-signatur-logo" alt="Logo" width="220"')
            && ($logo['contentId'] ?? null) === 'adk-signatur-logo'
            && ($logo['contentBytes'] ?? null) === base64_encode('PNGDATEN');
    });
});

it('trennt das Postfach auf Wunsch und protokolliert das', function () {
    mailConnection($this->user);

    Livewire::test(EmailAccount::class)
        ->callAction('disconnect')
        ->assertNotified('Ihr Postfach ist getrennt');

    expect(MailConnection::count())->toBe(0)
        ->and(AuditLog::where('log_name', 'microsoft')->where('event', 'disconnected')->exists())->toBeTrue();
});

it('trennt das Postfach, wenn ein Konto gesperrt wird', function () {
    $other = User::factory()->state(['role' => 'staff'])->create();
    mailConnection($other);

    $other->block();

    expect(MailConnection::count())->toBe(0);
});

it('setzt Anrede und Platzhalter aus dem Vorgang ein', function () {
    mailConnection($this->user, ['display_name' => 'Max Muster (ADK)']);
    $lead = mailLead();

    expect(LeadEmail::render('{anrede}, {vorname} {nachname} von {firma}. Gruß {absender} {unbekannt}', $lead, $this->user))
        ->toBe('Sehr geehrte Frau Muster, Erika Muster von Muster & Söhne GmbH. Gruß Max Muster (ADK) {unbekannt}')
        ->and(LeadEmail::salutation(new Contact(['salutation' => 'Herr', 'first_name' => 'Hans', 'last_name' => 'Beispiel'])))->toBe('Sehr geehrter Herr Beispiel')
        ->and(LeadEmail::salutation(new Contact(['salutation' => 'divers', 'first_name' => 'Alex', 'last_name' => 'Beispiel'])))->toBe('Guten Tag Alex Beispiel')
        ->and(LeadEmail::salutation(new Contact(['salutation' => null, 'first_name' => null, 'last_name' => 'Beispiel'])))->toBe('Guten Tag Beispiel')
        ->and(LeadEmail::salutation(null))->toBe('Sehr geehrte Damen und Herren');

    // Ohne Ansprechperson und ohne Postfach: neutrale Anrede, Name aus dem CRM-Konto.
    $withoutContact = Lead::factory()->create(['contact_id' => null]);
    $other = User::factory()->create(['name' => 'Erika Vertrieb']);

    expect(LeadEmail::render('{anrede} · {vorname}{nachname} · {absender}', $withoutContact, $other))
        ->toBe('Sehr geehrte Damen und Herren ·  · Erika Vertrieb');
});

it('füllt Betreff und Text aus der Vorlage und schlägt die Adresse der Ansprechperson vor', function () {
    mailConnection($this->user);
    $lead = mailLead();
    $template = EmailTemplate::where('name', 'Unterlagen nach Telefonat')->sole();

    $component = Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->assertActionVisible('sendEmail')
        ->mountAction('sendEmail');

    // Das Formular kommt als Livewire-Partial: Absender, Vorlagen und Hinweis zu § 7 UWG.
    expect(json_encode($component->effects['partials'] ?? [], JSON_UNESCAPED_UNICODE))
        ->toContain('Geht von max.muster@adk-akademie.test')
        ->toContain('Unterlagen nach Telefonat')
        ->toContain('§ 7 UWG');

    $component
        ->assertActionDataSet(['email_to' => 'erika.muster@example.org'])
        ->setActionData(['email_template_id' => $template->id])
        ->assertActionDataSet(function (array $state) {
            expect($state['email_subject'])->toBe('Ihre Unterlagen zur Weiterbildung bei der ADK')
                ->and($state['email_text'])->toStartWith("Sehr geehrte Frau Muster,\n\nvielen Dank")->toEndWith("Mit freundlichen Grüßen\nMax Muster");

            return [];
        });

    $withoutContact = Lead::factory()->create(['contact_id' => null, 'organization_id' => Organization::factory()->state(['email' => 'info@betrieb.example'])]);

    Livewire::test(ViewLead::class, ['record' => $withoutContact->id])
        ->mountAction('sendEmail')
        ->assertActionDataSet(['email_to' => 'info@betrieb.example']);
});

it('sendet die E-Mail mit Anhang über Microsoft Graph und dokumentiert sie im Vorgang', function () {
    mailConnection($this->user);
    $lead = mailLead();
    $template = templateWithAttachment();
    graphAccepts();

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->mountAction('sendEmail')
        ->setActionData(['email_template_id' => $template->id])
        ->assertActionDataSet(['attach_template_file' => true, 'email_subject' => 'Kursheft für Muster & Söhne GmbH'])
        ->setActionData(['email_text' => "Sehr geehrte Frau Muster,\n\n<b>Preise</b> & Termine anbei.", 'consent_confirmed' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('E-Mail an erika.muster@example.org gesendet');

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $message = $request['message'];
        $attachment = $message['attachments'][0];

        return $request->url() === 'https://graph.microsoft.com/v1.0/me/sendMail'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer access-alt')
            && $request['saveToSentItems'] === true
            && $message['subject'] === 'Kursheft für Muster & Söhne GmbH'
            && $message['toRecipients'] === [['emailAddress' => ['address' => 'erika.muster@example.org']]]
            && $message['body']['contentType'] === 'HTML'
            && str_contains($message['body']['content'], "Sehr geehrte Frau Muster,<br>\n<br>\n&lt;b&gt;Preise&lt;/b&gt; &amp; Termine anbei.<br>\n<br>\nMax Muster<br>\nVertrieb ADK</div>")
            && ! str_contains($message['body']['content'], '<b>')
            && $attachment['@odata.type'] === '#microsoft.graph.fileAttachment'
            && $attachment['name'] === 'ADK_Kursheft.pdf'
            && $attachment['contentType'] === 'application/pdf'
            && base64_decode($attachment['contentBytes']) === Storage::disk(EmailTemplate::DISK)->get('email-templates/kursheft.pdf')
            && $message['internetMessageHeaders'][0]['name'] === GraphMailer::REFERENCE_HEADER;
    });

    $activity = $lead->activities()->sole();
    $lead->refresh();

    expect($activity->type)->toBe('email')
        ->and($activity->user_id)->toBe($this->user->id)
        ->and($activity->external_id)->toStartWith('m365:')
        ->and($activity->body)->toBe(implode("\n", [
            'An: erika.muster@example.org · Betreff: Kursheft für Muster & Söhne GmbH',
            'Vorlage: Kursheft',
            'Anhang: ADK_Kursheft.pdf',
            'Einwilligung: beim Senden bestätigt (Bitte oder Einwilligung der Person)',
            '',
            'Sehr geehrte Frau Muster,',
            '',
            '<b>Preise</b> & Termine anbei.',
        ]))
        ->and($lead->status)->toBe('interested')
        ->and($lead->next_action_at->toDateString())->toBe('2026-10-09')
        ->and($lead->last_contact_at->format('d.m.Y H:i'))->toBe('08.10.2026 10:00');

    // Protokoll: „E-Mail gesendet“ ohne Text, die Aktivität selbst ohne eigenen Eintrag mit Text.
    $log = AuditLog::where('log_name', 'microsoft')->where('event', 'email_sent')->sole();
    expect($log->subject_id)->toBe($lead->id)
        ->and($log->causer_id)->toBe($this->user->id)
        ->and($log->properties['to'])->toBe('erika.muster@example.org')
        ->and($log->properties['attachment'])->toBe('ADK_Kursheft.pdf')
        ->and(json_encode($log->properties, JSON_UNESCAPED_UNICODE))->not->toContain('Preise')
        ->and(AuditLog::where('subject_type', Activity::class)->where('subject_id', $activity->id)->exists())->toBeFalse();
});

it('sendet ohne Anhang, wenn das Häkchen fehlt, und ohne Signatur, wenn keine eingetragen ist', function () {
    mailConnection($this->user, ['signature' => null]);
    $lead = mailLead();
    $template = templateWithAttachment();
    graphAccepts();

    // Erst die Vorlage wählen (füllt Betreff und Text), dann ändern, wie in der Oberfläche.
    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->mountAction('sendEmail')
        ->setActionData(['email_template_id' => $template->id])
        ->setActionData(['attach_template_file' => false, 'email_text' => 'Kurz und knapp', 'consent_confirmed' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    Http::assertSent(fn (Request $request) => ! isset($request['message']['attachments'])
        && $request['message']['body']['content'] === '<div style="font-family: Calibri, Arial, Helvetica, sans-serif; font-size: 11pt;">Kurz und knapp</div>');
    expect($lead->activities()->sole()->body)->not->toContain('Anhang:');
});

it('sendet nur mit bestätigter Einwilligung', function () {
    mailConnection($this->user);
    $lead = mailLead();
    Http::fake();

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('sendEmail', emailData(['consent_confirmed' => false]))
        ->assertHasActionErrors(['consent_confirmed']);

    Http::assertNothingSent();
    expect($lead->activities()->count())->toBe(0);
});

it('sendet keine E-Mail an Adressen oder Vorgänge auf der Sperrliste', function () {
    mailConnection($this->user);
    $lead = mailLead();
    BlocklistEntry::create(['email' => 'gesperrt@example.org', 'reason' => 'Werbewiderspruch']);
    Http::fake();

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('sendEmail', emailData(['email_to' => 'Gesperrt@Example.org']))
        ->assertHasActionErrors(['email_to']);

    expect(fn () => app(LeadEmail::class)->send($this->user, $lead, emailData(['email_to' => 'gesperrt@example.org'])))
        ->toThrow(RuntimeException::class, 'steht auf der Sperrliste');

    // Firma mit PLZ auf der Sperrliste: Knopf gesperrt.
    BlocklistEntry::create(['company_name' => 'Muster & Söhne GmbH', 'postal_code' => '55116', 'reason' => 'Werbewiderspruch']);
    Livewire::test(ViewLead::class, ['record' => $lead->id])->assertActionDisabled('sendEmail');

    Http::assertNothingSent();
    expect($lead->activities()->count())->toBe(0);
});

it('setzt die Wiedervorlage gleich beim Senden und vermerkt sie in der Aktivität', function () {
    mailConnection($this->user);
    $lead = mailLead();
    graphAccepts();

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('sendEmail', emailData(['next_action_at' => '2026-10-15', 'next_action_time' => '09:30']))
        ->assertHasNoActionErrors();

    $lead->refresh();
    expect($lead->next_action_at->toDateString())->toBe('2026-10-15')
        ->and($lead->next_action_time)->toBe('09:30')
        ->and($lead->status)->toBe('interested')
        ->and($lead->activities()->sole()->body)->toContain('Wiedervorlage: 09.10.2026 → 15.10.2026, 09:30 Uhr')
        ->and(AuditLog::where('event', 'email_sent')->sole()->properties['follow_up'])->toBe('15.10.2026, 09:30 Uhr');
});

it('verlangt zur Uhrzeit ein Datum und lehnt eine Wiedervorlage in der Vergangenheit ab', function () {
    mailConnection($this->user);
    $lead = mailLead();
    Http::fake();

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('sendEmail', emailData(['next_action_time' => '09:30']))
        ->assertHasActionErrors(['next_action_at']);

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('sendEmail', emailData(['next_action_at' => '2026-10-07']))
        ->assertHasActionErrors(['next_action_at']);

    Http::assertNothingSent();
});

it('erneuert ein abgelaufenes Token vor dem Senden', function () {
    $connection = mailConnection($this->user, ['expires_at' => now()->subMinute()]);
    $lead = mailLead();
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'access-frisch', 'refresh_token' => 'refresh-frisch', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/me/sendMail' => Http::response(null, 202),
    ]);

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('sendEmail', emailData())
        ->assertNotified('E-Mail an erika.muster@example.org gesendet');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/oauth2/v2.0/token')
        && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'refresh-alt'
        && str_contains($request['scope'], 'Mail.Send'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/me/sendMail') && $request->hasHeader('Authorization', 'Bearer access-frisch'));
    expect($connection->fresh())
        ->refresh_token->toBe('refresh-frisch')
        ->access_token->toBe('access-frisch');
});

it('bittet bei abgelaufener Freigabe um eine neue Verbindung und sendet nichts', function () {
    mailConnection($this->user, ['expires_at' => now()->subMinute()]);
    $lead = mailLead();
    Http::fake(['login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => "AADSTS70008: The refresh token has expired.\r\nTrace ID: 1"], 400)]);

    expect(fn () => app(LeadEmail::class)->send($this->user, $lead, emailData(['email_to' => 'erika.muster@example.org'])))
        ->toThrow(RuntimeException::class, 'Bitte verbinden Sie Ihr Postfach unter „E-Mail-Konto“ neu. AADSTS70008: The refresh token has expired.');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'graph.microsoft.com'));
});

it('meldet Ablehnungen von Microsoft Graph verständlich und schreibt dann keine Aktivität', function (int $status, array $body, string $message) {
    mailConnection($this->user);
    $lead = mailLead();
    Http::fake(['graph.microsoft.com/v1.0/me/sendMail' => Http::response($body, $status)]);

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('sendEmail', emailData())
        ->assertNotified('E-Mail nicht gesendet');

    expect(fn () => app(LeadEmail::class)->send($this->user, $lead, emailData(['email_to' => 'erika.muster@example.org'])))
        ->toThrow(RuntimeException::class, $message);
    expect($lead->activities()->count())->toBe(0)
        ->and(AuditLog::where('event', 'email_sent')->exists())->toBeFalse();
})->with([
    '401' => [401, ['error' => ['code' => 'InvalidAuthenticationToken', 'message' => 'Access token has expired.']], 'Bitte verbinden Sie Ihr Postfach unter „E-Mail-Konto“ neu.'],
    '403' => [403, ['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied.']], 'erlaubt den Versand aus Ihrem Postfach nicht (403). Bitte verbinden Sie Ihr Postfach unter „E-Mail-Konto“ neu. Access is denied.'],
    '400' => [400, ['error' => ['code' => 'ErrorInvalidRecipients', 'message' => 'At least one recipient is not valid.']], 'Microsoft 365 hat die E-Mail nicht angenommen (400). At least one recipient is not valid.'],
]);

it('lehnt Anhänge über 3 MB ab, bevor etwas gesendet wird', function () {
    mailConnection($this->user);
    $lead = mailLead();
    $template = templateWithAttachment(str_repeat('x', GraphMailer::MAX_ATTACHMENT_BYTES + 1));
    Http::fake();

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('sendEmail', emailData(['email_template_id' => $template->id, 'attach_template_file' => true]))
        ->assertNotified('E-Mail nicht gesendet');

    expect(fn () => app(LeadEmail::class)->send($this->user, $lead, emailData(['email_to' => 'erika.muster@example.org', 'email_template_id' => $template->id, 'attach_template_file' => true])))
        ->toThrow(RuntimeException::class, 'Der Anhang ist zu groß (3,0 MB, höchstens 3 MB).');

    Http::assertNothingSent();
    expect($lead->activities()->count())->toBe(0);
});

it('zeigt „E-Mail schreiben“ ohne verbundenes Postfach mit Hinweis auf „E-Mail-Konto“', function () {
    $lead = mailLead();
    Http::fake();

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->assertActionVisible('sendEmail')
        ->callAction('sendEmail')
        ->assertNotified('Bitte verbinden Sie zuerst Ihr Postfach');

    Http::assertNothingSent();
    expect($lead->activities()->count())->toBe(0);
});

it('zeigt „E-Mail schreiben“ nur bei offenen Vorgängen', function () {
    mailConnection($this->user);
    $closed = mailLead();
    $closed->forceFill(['status' => 'no_interest', 'closed_at' => now()])->save();

    Livewire::test(ViewLead::class, ['record' => $closed->id])->assertActionHidden('sendEmail');
});

it('schreibt die E-Mail auch aus der Anrufliste', function () {
    mailConnection($this->user);
    $lead = mailLead();
    $lead->forceFill(['next_action_at' => '2026-10-08'])->save();
    graphAccepts();

    Livewire::test(CallList::class)
        ->assertSet('leadId', $lead->id)
        ->assertSee('E-Mail schreiben')
        ->callAction('sendEmail', emailData())
        ->assertNotified('E-Mail an erika.muster@example.org gesendet')
        ->assertSet('leadId', $lead->id)
        ->assertSee('Betreff: Ihre Unterlagen');

    expect($lead->activities()->sole()->type)->toBe('email');
});

it('legt zwei Startvorlagen an', function () {
    expect(EmailTemplate::ordered()->pluck('name')->all())->toBe(['Unterlagen nach Telefonat', 'Nachfassen'])
        ->and(EmailTemplate::where('name', 'Nachfassen')->value('body'))->toStartWith('{anrede},')->toContain('ADK');
});

it('lässt nur die Verwaltung E-Mail-Vorlagen pflegen, Anhänge liegen privat', function () {
    $this->get('/e-mail-vorlagen')->assertForbidden();

    loginAs('admin');
    $this->get('/e-mail-vorlagen')->assertOk()->assertSee('Unterlagen nach Telefonat');
    $this->get('/e-mail-vorlagen/create')->assertOk()->assertSee('{anrede}', escape: false);

    Livewire::test(CreateEmailTemplate::class)
        ->fillForm([
            'name' => 'Terminbestätigung',
            'subject' => 'Unser Termin',
            'body' => '{anrede}, hiermit bestätige ich unseren Termin.',
            'attachment_path' => fakePdfUpload('Anfahrt.pdf'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $template = EmailTemplate::where('name', 'Terminbestätigung')->sole();

    expect($template->attachment_name)->toBe('Anfahrt.pdf')
        ->and($template->attachment_path)->toStartWith('email-templates/')
        ->and($template->sort_order)->toBe(3)
        ->and(Storage::disk(EmailTemplate::DISK)->exists($template->attachment_path))->toBeTrue()
        ->and(Storage::disk('public')->exists($template->attachment_path))->toBeFalse();

    $path = $template->attachment_path;
    $template->delete();
    expect(Storage::disk(EmailTemplate::DISK)->exists($path))->toBeFalse();
});

it('bietet inaktive Vorlagen nicht an', function () {
    mailConnection($this->user);
    $lead = mailLead();
    EmailTemplate::where('name', 'Unterlagen nach Telefonat')->update(['is_active' => false]);

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->mountAction('sendEmail')
        ->assertFormFieldExists('email_template_id', fn ($field) => array_values($field->getOptions()) === ['Nachfassen']);
});
