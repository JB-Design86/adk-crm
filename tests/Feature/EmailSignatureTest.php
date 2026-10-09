<?php

use App\Filament\Pages\EmailAccount;
use App\Models\MailConnection;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'services.microsoft.client_id' => 'test-client',
        'services.microsoft.client_secret' => 'test-secret',
        'services.microsoft.tenant' => 'test-tenant',
    ]);
    $this->user = loginAs('staff');
});

function signatureConnection(array $attributes = []): MailConnection
{
    return MailConnection::create([
        'user_id' => test()->user->id,
        'mailbox' => 'max.muster@adk-akademie.test',
        'display_name' => 'Max Muster',
        'access_token' => 'access',
        'refresh_token' => 'refresh',
        'expires_at' => now()->addHour(),
        ...$attributes,
    ]);
}

it('speichert die Signatur als HTML-Code mit Tabelle und entfernt Skripte und Bilder', function () {
    $connection = signatureConnection();

    Livewire::test(EmailAccount::class)
        ->set('data.signature_as_code', true)
        ->set('data.signature_code', '<p><strong>Max Muster</strong></p><table style="border-collapse:collapse"><tr><td style="padding:0 12px 0 0">Festnetz:</td><td>+49 6131 0</td></tr></table><script>alert(1)</script><img src="data:image/png;base64,AAA">')
        ->call('saveSignature')
        ->assertNotified('Signatur gespeichert');

    expect($connection->fresh()->signature_html)
        ->toContain('<table style="border-collapse:collapse">')
        ->toContain('<td style="padding:0 12px 0 0">Festnetz:</td>')
        ->not->toContain('script')
        ->not->toContain('<img');
});

it('öffnet eine Signatur mit Tabelle direkt im HTML-Code, damit der Editor sie nicht verliert', function () {
    signatureConnection(['signature_html' => '<p>Max</p><table><tbody><tr><td>Web:</td><td>adk</td></tr></tbody></table>']);

    Livewire::test(EmailAccount::class)
        ->assertSet('data.signature_as_code', true)
        ->assertSet('data.signature_code', '<p>Max</p><table><tbody><tr><td>Web:</td><td>adk</td></tr></tbody></table>');
});

it('öffnet eine einfache Signatur im Editor', function () {
    signatureConnection(['signature_html' => '<p><strong>Max</strong><br>ADK</p>']);

    Livewire::test(EmailAccount::class)->assertSet('data.signature_as_code', false);
});

it('gibt Absätzen der Signatur Abstand wie im Editor und lässt Tabellen stehen', function () {
    $connection = signatureConnection(['signature_html' => '<p><strong>Max Muster</strong><br>Inhaber</p><p>ADK<br>Mainz</p><table><tbody><tr><td style="padding:0 12px 0 0">Web:</td><td>adk</td></tr></tbody></table><img src="x.png">']);

    expect($connection->signatureHtml())
        ->toContain('<p style="margin:0 0 12px"><strong>Max Muster</strong><br />Inhaber</p><p style="margin:0 0 12px">ADK<br />Mainz</p>')
        ->toContain('<td style="padding:0 12px 0 0">Web:</td>')
        ->not->toContain('<img');
});
