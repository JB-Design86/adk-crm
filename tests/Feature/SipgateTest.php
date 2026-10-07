<?php

use App\Filament\Pages\CallList;
use App\Filament\Pages\Reports;
use App\Filament\Pages\Telephony;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\BlocklistEntry;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\SipgateConnection;
use App\Models\User;
use App\Services\ReportService;
use App\Services\Sipgate\SipgateSync;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 28)->setTime(10, 0));
    config([
        'services.sipgate.client_id' => 'test-client',
        'services.sipgate.client_secret' => 'test-secret',
        'services.sipgate.redirect' => 'https://crm.test/sipgate/callback',
    ]);
    $this->user = loginAs('staff');
});

function sipgateConnection(User $user, array $attributes = []): SipgateConnection
{
    return SipgateConnection::create([
        'user_id' => $user->id,
        'sipgate_user_id' => 'w0',
        'device_id' => 'e0',
        'device_alias' => 'Bürotelefon',
        'access_token' => 'access-alt',
        'refresh_token' => 'refresh-alt',
        'expires_at' => now()->addMinutes(5),
        ...$attributes,
    ]);
}

function leadWithPhone(string $phone = '06131 123456', array $organization = []): Lead
{
    return Lead::factory()->create([
        'organization_id' => Organization::factory()->state(['phone_display' => $phone, 'priority' => 'A', ...$organization]),
        'contact_id' => null,
        'next_action_at' => null,
    ]);
}

it('erklärt auf der Seite Telefonie, dass sipgate noch nicht eingerichtet ist', function () {
    config(['services.sipgate.client_id' => null]);

    Livewire::test(Telephony::class)
        ->assertSee('sipgate ist noch nicht eingerichtet')
        ->assertDontSee('sipgate/connect');

    $this->get(route('filament.crm.sipgate.connect'))->assertNotFound();

    expect(Telephony::shouldRegisterNavigation())->toBeFalse();
    config(['services.sipgate.client_id' => 'test-client']);
    expect(Telephony::shouldRegisterNavigation())->toBeTrue();
});

it('leitet zur Anmeldung bei sipgate weiter und merkt sich den Prüfwert', function () {
    $response = $this->get(route('filament.crm.sipgate.connect'));

    $state = session('sipgate_oauth_state');
    $response->assertRedirect();

    expect($state)->toHaveLength(40)
        ->and($response->headers->get('Location'))
        ->toStartWith('https://login.sipgate.com/auth/realms/third-party/protocol/openid-connect/auth?')
        ->toContain('client_id=test-client')
        ->toContain('state='.$state)
        ->toContain('redirect_uri='.urlencode('https://crm.test/sipgate/callback'));
});

it('verbindet sipgate nach dem Rückruf und speichert die Tokens verschlüsselt', function () {
    Http::fake([
        'login.sipgate.com/*' => Http::response(['access_token' => 'access-neu', 'refresh_token' => 'refresh-neu', 'expires_in' => 3600]),
        'api.sipgate.com/v2/authorization/userinfo' => Http::response(['sub' => 'w7']),
    ]);

    $this->withSession(['sipgate_oauth_state' => 'pruefwert'])
        ->get(route('filament.crm.sipgate.callback', ['code' => 'abc', 'state' => 'pruefwert']))
        ->assertRedirect(Telephony::getUrl());

    $connection = $this->user->sipgateConnection;
    $raw = DB::table('sipgate_connections')->first();

    expect($connection->sipgate_user_id)->toBe('w7')
        ->and($connection->access_token)->toBe('access-neu')
        ->and($raw->access_token)->not->toContain('access-neu')
        ->and($raw->refresh_token)->not->toContain('refresh-neu');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/token')
        && $request['grant_type'] === 'authorization_code' && $request['code'] === 'abc');

    $log = AuditLog::where('log_name', 'sipgate')->where('event', 'connected')->sole();
    expect(json_encode($log->properties))->not->toContain('access-neu');
});

it('lehnt einen Rückruf mit falschem Prüfwert ab', function () {
    Http::fake();

    $this->withSession(['sipgate_oauth_state' => 'pruefwert'])
        ->get(route('filament.crm.sipgate.callback', ['code' => 'abc', 'state' => 'gefälscht']))
        ->assertRedirect(Telephony::getUrl());

    expect(SipgateConnection::count())->toBe(0);
    Http::assertNothingSent();
});

it('zeigt die Geräte und speichert das Gerät für „Anrufen“', function () {
    $connection = sipgateConnection($this->user, ['device_id' => null, 'device_alias' => null]);
    Http::fake(['api.sipgate.com/v2/w0/devices' => Http::response(['items' => [
        ['id' => 'e0', 'alias' => 'Bürotelefon', 'type' => 'REGISTER', 'online' => true],
        ['id' => 'x1', 'alias' => 'Handy', 'type' => 'MOBILE', 'online' => false],
    ]])]);

    Livewire::test(Telephony::class)
        ->assertSee('Bürotelefon')
        ->assertSee('Mobiltelefon')
        ->set('deviceId', 'x1')
        ->call('saveDevice');

    expect($connection->fresh()->device_id)->toBe('x1')
        ->and($connection->fresh()->device_alias)->toBe('Handy');
});

it('startet einen Anruf per Klick auf der Vorgangsseite', function () {
    sipgateConnection($this->user);
    $lead = leadWithPhone('06131 123456');
    Http::fake(['api.sipgate.com/v2/sessions/calls' => Http::response(['sessionId' => 'S1'])]);

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->assertActionVisible('sipgateCall')
        ->callAction('sipgateCall')
        ->assertNotified('Ihr Telefon klingelt gleich');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.sipgate.com/v2/sessions/calls'
        && $request['deviceId'] === 'e0' && $request['caller'] === 'e0' && $request['callee'] === '+496131123456'
        && $request->hasHeader('Authorization', 'Bearer access-alt'));

    expect(AuditLog::where('log_name', 'sipgate')->where('event', 'call_started')->where('subject_id', $lead->id)->exists())->toBeTrue();
});

it('zeigt „Über sipgate anrufen“ nur mit verbundenem Konto', function () {
    $lead = leadWithPhone();

    Livewire::test(ViewLead::class, ['record' => $lead->id])->assertActionHidden('sipgateCall');
    Livewire::test(CallList::class)->assertDontSee('Über sipgate anrufen');
    Livewire::test(Telephony::class)->assertSee('Nicht verbunden')->assertSee('sipgate/connect');
});

it('ruft Nummern auf der Sperrliste auch über sipgate nicht an', function () {
    sipgateConnection($this->user);
    $lead = leadWithPhone('06131 999999');
    BlocklistEntry::create(['phone_e164' => '+496131999999', 'reason' => 'Werbewiderspruch']);
    Http::fake();

    Livewire::test(ViewLead::class, ['record' => $lead->id])->assertActionDisabled('sipgateCall');

    Livewire::test(CallList::class)
        ->set('leadId', $lead->id)
        ->call('callViaSipgate')
        ->assertNotified('Anruf nicht gestartet');

    Http::assertNothingSent();
});

it('startet den Anruf aus der Anrufliste', function () {
    sipgateConnection($this->user);
    $lead = leadWithPhone('0611 445566');
    Http::fake(['api.sipgate.com/v2/sessions/calls' => Http::response(['sessionId' => 'S2'])]);

    Livewire::test(CallList::class)
        ->assertSet('leadId', $lead->id)
        ->assertSee('Über sipgate anrufen')
        ->call('callViaSipgate')
        ->assertNotified('Ihr Telefon klingelt gleich');

    Http::assertSent(fn (Request $request) => $request['callee'] === '+49611445566');
});

it('erneuert ein abgelaufenes Token vor dem Anruf', function () {
    $connection = sipgateConnection($this->user, ['expires_at' => now()->subMinute()]);
    $lead = leadWithPhone();
    Http::fake([
        'login.sipgate.com/*' => Http::response(['access_token' => 'access-frisch', 'refresh_token' => 'refresh-frisch', 'expires_in' => 3600]),
        'api.sipgate.com/v2/sessions/calls' => Http::response(['sessionId' => 'S3']),
    ]);

    Livewire::test(ViewLead::class, ['record' => $lead->id])->callAction('sipgateCall');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/token') && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'refresh-alt');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/sessions/calls') && $request->hasHeader('Authorization', 'Bearer access-frisch'));
    expect($connection->fresh()->refresh_token)->toBe('refresh-frisch');
});

/** Zugriffstoken wie von sipgate (JWT), Signatur egal. */
function sipgateJwt(array $claims): string
{
    $part = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

    return $part(['alg' => 'RS256']).'.'.$part($claims).'.signatur';
}

it('startet den Anruf bei der neuen sipgate-Anlage (Neo) über /calls', function () {
    sipgateConnection($this->user, ['access_token' => sipgateJwt(['featureScope' => 'NEO_PBX', 'scope' => 'sessions:calls:write rtcm:write history:read devices:read'])]);
    $lead = leadWithPhone('06131 123456');
    Http::fake(['api.sipgate.com/v2/calls' => Http::response(['callId' => 'C1'])]);

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('sipgateCall')
        ->assertNotified('Ihr Telefon klingelt gleich');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.sipgate.com/v2/calls'
        && $request['deviceId'] === 'e0' && $request['targetNumber'] === '+496131123456');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/sessions/calls'));
});

it('versucht die Nummer bei Neo ohne +, wenn sipgate das Format ablehnt', function () {
    sipgateConnection($this->user, ['access_token' => sipgateJwt(['featureScope' => 'NEO_PBX', 'scope' => 'rtcm:write'])]);
    $lead = leadWithPhone('06131 123456');
    Http::fake(['api.sipgate.com/v2/calls' => Http::sequence()->push(['message' => 'invalid'], 400)->push(['callId' => 'C2'])]);

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('sipgateCall')
        ->assertNotified('Ihr Telefon klingelt gleich');

    Http::assertSentInOrder([
        fn (Request $request) => $request['targetNumber'] === '+496131123456',
        fn (Request $request) => $request['targetNumber'] === '496131123456',
    ]);
});

it('bittet bei Neo ohne Recht zum Anrufen um eine neue Verbindung', function () {
    sipgateConnection($this->user, ['access_token' => sipgateJwt(['featureScope' => 'NEO_PBX', 'scope' => 'sessions:calls:write history:read devices:read'])]);
    $lead = leadWithPhone();
    Http::fake();

    Livewire::test(CallList::class)
        ->set('leadId', $lead->id)
        ->call('callViaSipgate')
        ->assertNotified('Anruf nicht gestartet');

    Http::assertNothingSent();
});

it('fordert bei der Anmeldung das Recht für Anrufe auf der neuen sipgate-Anlage an', function () {
    $location = $this->get(route('filament.crm.sipgate.connect'))->headers->get('Location');

    expect($location)->toContain('rtcm%3Awrite')->toContain('sessions%3Acalls%3Awrite');
});

it('übernimmt Anrufe aus sipgate einmal als Aktivität am passenden Vorgang', function () {
    $connection = sipgateConnection($this->user);
    $lead = leadWithPhone('06131 123456');
    $lead->forceFill(['last_contact_at' => '2026-09-28 09:50:00'])->saveQuietly();

    Http::fake(['api.sipgate.com/v2/history*' => Http::response(['items' => [
        ['id' => 'H1', 'type' => 'CALL', 'direction' => 'OUTGOING', 'source' => '+4961319990', 'target' => '+496131123456', 'status' => 'PICKUP', 'duration' => 192, 'created' => '2026-09-28T07:40:00Z'],
        ['id' => 'H2', 'type' => 'CALL', 'direction' => 'MISSED_INCOMING', 'source' => '496131123456', 'target' => '+4961319990', 'status' => 'NOPICKUP', 'created' => '2026-09-28T07:55:00Z'],
        ['id' => 'H3', 'type' => 'CALL', 'direction' => 'OUTGOING', 'source' => '+4961319990', 'target' => '+4930111111', 'status' => 'PICKUP', 'duration' => 60, 'created' => '2026-09-28T07:56:00Z'],
        ['id' => 'H4', 'type' => 'CALL', 'direction' => 'INCOMING', 'source' => 'anonymous', 'target' => '+4961319990', 'status' => 'PICKUP', 'created' => '2026-09-28T07:57:00Z'],
    ]])]);

    expect(app(SipgateSync::class)->sync($connection))->toBe(2)
        ->and(app(SipgateSync::class)->sync($connection->fresh()))->toBe(0);

    $activities = Activity::where('type', 'phone_log')->orderBy('occurred_at')->get();

    expect($activities)->toHaveCount(2)
        ->and($activities[0]->lead_id)->toBe($lead->id)
        ->and($activities[0]->user_id)->toBe($this->user->id)
        ->and($activities[0]->body)->toBe('Ausgehender Anruf, angenommen, 3:12 Min.')
        ->and($activities[0]->duration_seconds)->toBe(192)
        ->and($activities[1]->body)->toBe('Verpasster Anruf, nicht angenommen')
        // Nachträglich übernommen, aber neuer als der letzte Kontakt: 07:55 UTC = 09:55 in Mainz.
        ->and($lead->fresh()->last_contact_at->format('H:i'))->toBe('09:55')
        ->and($connection->fresh()->last_synced_at)->not->toBeNull();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'types=CALL') && str_contains($request->url(), 'from=2026-09-27T07%3A45%3A00Z'));
});

it('datiert den letzten Kontakt durch alte sipgate-Anrufe nicht zurück', function () {
    $connection = sipgateConnection($this->user);
    $lead = leadWithPhone('06131 123456');
    $lead->forceFill(['last_contact_at' => '2026-09-28 09:59:00'])->saveQuietly();

    Http::fake(['api.sipgate.com/v2/history*' => Http::response(['items' => [
        ['id' => 'H9', 'type' => 'CALL', 'direction' => 'OUTGOING', 'target' => '+496131123456', 'status' => 'BUSY', 'created' => '2026-09-27T12:00:00Z'],
    ]])]);

    app(SipgateSync::class)->sync($connection);

    expect($lead->fresh()->last_contact_at->format('d.m. H:i'))->toBe('28.09. 09:59');
});

it('gleicht per Befehl alle verbundenen, nicht gesperrten Konten ab', function () {
    sipgateConnection($this->user);
    $blocked = User::factory()->state(['role' => 'staff'])->create();
    sipgateConnection($blocked, ['sipgate_user_id' => 'w9']);
    $blocked->forceFill(['is_blocked' => true])->save();
    leadWithPhone('06131 123456');

    Http::fake(['api.sipgate.com/v2/history*' => Http::response(['items' => [
        ['id' => 'H5', 'type' => 'CALL', 'direction' => 'OUTGOING', 'target' => '+496131123456', 'status' => 'PICKUP', 'duration' => 30, 'created' => '2026-09-28T07:00:00Z'],
    ]])]);

    $this->artisan('adk:sipgate-abgleich')->assertSuccessful();

    expect(Activity::where('type', 'phone_log')->count())->toBe(1);
    Http::assertSentCount(1);
});

it('trennt die sipgate-Verbindung, wenn ein Konto gesperrt wird', function () {
    $other = User::factory()->state(['role' => 'staff'])->create();
    sipgateConnection($other);

    $other->block();

    expect(SipgateConnection::count())->toBe(0);
});

it('trennt die Verbindung auf Wunsch und protokolliert das', function () {
    sipgateConnection($this->user);
    Http::fake(['api.sipgate.com/*' => Http::response(['items' => []])]);

    Livewire::test(Telephony::class)
        ->callAction('disconnect')
        ->assertNotified('sipgate ist getrennt');

    expect(SipgateConnection::count())->toBe(0)
        ->and(AuditLog::where('log_name', 'sipgate')->where('event', 'disconnected')->exists())->toBeTrue();
});

it('zeigt Telefonate laut sipgate in der Auswertung', function () {
    $lead = leadWithPhone();
    foreach ([['PICKUP', 120], ['PICKUP', 60], ['NOPICKUP', null]] as $i => [$status, $duration]) {
        Activity::create(['lead_id' => $lead->id, 'type' => 'phone_log', 'outcome' => $status, 'duration_seconds' => $duration, 'external_id' => "sipgate:R{$i}", 'occurred_at' => now()]);
    }

    expect(ReportService::forPeriod('2026-09-28', '2026-09-28')->phoneLog())
        ->toBe(['total' => 3, 'picked_up' => 2, 'talk_seconds' => 180, 'average_seconds' => 90]);

    loginAs('admin');
    Livewire::test(Reports::class)->assertSee('Telefonate laut sipgate')->assertSee('1:30 Min.');
});

it('liest Nummern aus sipgate in allen Schreibweisen', function () {
    expect(SipgateSync::number('+4961311234567'))->toBe('+4961311234567')
        ->and(SipgateSync::number('4961311234567'))->toBe('+4961311234567')
        ->and(SipgateSync::number('061311234567'))->toBe('+4961311234567')
        ->and(SipgateSync::number('anonymous'))->toBeNull()
        ->and(SipgateSync::number('e0'))->toBeNull()
        ->and(SipgateSync::number(null))->toBeNull();
});
