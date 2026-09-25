<?php

use App\Models\AuditLog;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Livewire\Livewire;

it('leitet ohne Anmeldung zur Anmeldeseite um', function () {
    $this->get('/')->assertRedirect('/login');
});

it('bietet keine öffentliche Registrierung und kein Zurücksetzen per E-Mail an', function () {
    $this->get('/register')->assertNotFound();
    $this->get('/password-reset/request')->assertNotFound();
});

it('verlangt die Einrichtung der Zwei-Faktor-Anmeldung vor jedem Zugriff', function () {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('multi-factor-authentication');
});

it('lässt Konten mit eingerichteter Zwei-Faktor-Anmeldung zu', function () {
    $user = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($user)->get('/')->assertOk();
});

it('fragt nach dem Kennwort den Code der Authenticator-App ab', function () {
    $user = User::factory()->withTwoFactor()->create();

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password1234'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    // Ohne Code ist niemand angemeldet.
    $this->assertGuest();
});

it('speichert Kennwörter als bcrypt-Hash', function () {
    $user = User::factory()->create(['password' => 'geheim-und-lang']);

    expect($user->getAuthPassword())->toStartWith('$2y$');
});

it('lässt gesperrte Konten nicht zu und meldet sie ab', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user)->get('/')->assertOk();

    $user->block();

    $this->get('/')->assertRedirect('/login');
    $this->assertGuest();
    expect(User::find($user->id))->not->toBeNull();
});

it('lehnt die Anmeldung gesperrter Konten ab', function () {
    $user = User::factory()->blocked()->create();

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password1234'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});

it('begrenzt Anmeldeversuche', function () {
    $user = User::factory()->create();

    $component = Livewire::test(Login::class);

    foreach (range(1, 5) as $attempt) {
        $component->fillForm(['email' => $user->email, 'password' => 'falsch-falsch'])->call('authenticate');
    }

    $component->fillForm(['email' => $user->email, 'password' => 'password1234'])
        ->call('authenticate')
        ->assertNotified();

    $this->assertGuest();
});

it('protokolliert fehlgeschlagene Anmeldungen', function () {
    $user = User::factory()->create();

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'falsch-falsch'])
        ->call('authenticate');

    expect(AuditLog::where('log_name', 'auth')->where('event', 'failed')->exists())->toBeTrue();
});

it('setzt Sicherheits-Header', function () {
    $response = $this->get('/login');

    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'");
});

it('leitet HTTP auf HTTPS um, wenn HTTPS verlangt ist', function () {
    config(['app.force_https' => true]);

    $this->get('http://localhost/login')->assertRedirect('https://localhost/login');
});
