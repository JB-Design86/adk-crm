<?php

use App\Filament\Pages\CallList;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Livewire\BrandSwitcher;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\LeadStatusService;
use App\Services\OfferSuggestion;
use App\Support\Brand;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

beforeEach(function () {
    // Freitag, 23.10.2026, 10:00 Uhr
    $this->travelTo(now()->setDate(2026, 10, 23)->setTime(10, 0));
});

/** ADK-Vorgang zu einer Firma, auf Wunsch geschlossen vor einigen Tagen und mit gesendeter E-Mail. */
function adkHistory(string $status, ?int $closedDaysAgo = null, bool $email = false, array $attributes = []): Lead
{
    $lead = Lead::factory()->create([
        'status' => $status,
        'closed_at' => $closedDaysAgo === null ? null : now()->subDays($closedDaysAgo),
        'organization_id' => Organization::factory()->state(['priority' => 'A']),
        ...$attributes,
    ]);

    if ($email) {
        Activity::create(['lead_id' => $lead->id, 'type' => 'email', 'body' => 'Unterlagen']);
    }

    return $lead;
}

function jbLeadFor(Lead $other, array $attributes = []): Lead
{
    return Lead::factory()->create(['brand' => 'jb', 'organization_id' => $other->organization_id, ...$attributes]);
}

it('bewertet die Ampel am Vorgang und in der Anrufliste gleich', function (string $status, ?int $closedDaysAgo, bool $email, string $color, array $attributes = []) {
    loginAs('admin');
    $jb = jbLeadFor(adkHistory($status, $closedDaysAgo, $email, $attributes));

    expect($jb->crossBrandLight()->color)->toBe($color)
        ->and(Lead::whereKey($jb->id)->freeAcrossBrands()->exists())->toBe($color === 'green')
        ->and($jb->isCallable())->toBe($color === 'green');
})->with([
    'Werbewiderspruch' => ['objection', 5, false, 'red'],
    'kein Interesse nach E-Mail vor 2 Monaten' => ['no_interest', 60, true, 'red'],
    'kein Interesse nach E-Mail vor 13 Monaten' => ['no_interest', 400, true, 'green'],
    'kein Interesse ohne E-Mail vor 1 Monat' => ['no_interest', 30, false, 'yellow'],
    'kein Bedarf ohne E-Mail vor 4 Monaten' => ['no_need', 120, false, 'green'],
    'Termin bei der ADK' => ['appointment', null, false, 'blue'],
    'Förderfall bei der ADK (Kunde)' => ['handed_over', null, false, 'blue', ['contracted_at' => '2026-09-01']],
    'ADK erreicht die Firma noch nicht' => ['not_reached', null, false, 'blue'],
    'Datensatz falsch bei der ADK' => ['wrong_data', 10, false, 'green', ['close_reason' => 'wrong_number']],
]);

it('nennt bei gelb und rot den Grund und das Datum, ab dem die Firma frei ist', function () {
    loginAs('admin');
    $yellow = jbLeadFor(adkHistory('no_interest', 30));
    $red = jbLeadFor(adkHistory('no_interest', 60, email: true));

    expect($yellow->crossBrandLight()->until->toDateString())->toBe('2026-12-23')
        ->and($yellow->callBlockReason())->toContain('Ampel: später')->toContain('Frei ab 23.12.2026')
        ->and($red->crossBrandLight()->until->toDateString())->toBe('2027-08-24')
        ->and($red->callBlockReason())->toContain('Ampel: gesperrt')->toContain('E-Mails');
});

it('lässt den älteren Vorgang anrufen und den jüngeren warten, damit niemand zweimal angerufen wird', function () {
    loginAs('admin');
    $adk = adkHistory('new');
    $jb = jbLeadFor($adk);

    expect($adk->crossBrandLight()->isGreen())->toBeTrue()
        ->and($jb->crossBrandLight()->color)->toBe('blue')
        ->and(Lead::freeAcrossBrands()->pluck('id')->all())->toBe([$adk->id]);
});

it('sperrt verknüpfte Vorgänge (Paket) nicht gegenseitig', function () {
    loginAs('admin');
    $adk = adkHistory('interested', attributes: ['next_action_at' => '2026-10-23']);
    $jb = app(LeadStatusService::class)->createForOtherBrand($adk, 'interested', ['next_action_at' => '2026-10-23']);

    expect($jb->crossBrandLight()->isGreen())->toBeTrue()
        ->and($adk->fresh()->crossBrandLight()->isGreen())->toBeTrue()
        ->and(Lead::whereKey([$adk->id, $jb->id])->freeAcrossBrands()->count())->toBe(2);
});

it('lässt eingehende Anfragen trotz Ampel anrufen', function () {
    loginAs('admin');
    $adk = adkHistory('appointment');
    $inbound = jbLeadFor($adk, ['channel' => 'phone']);

    expect($inbound->crossBrandLight()->color)->toBe('blue')
        ->and($inbound->callBlockReason())->toBeNull();
});

it('zeigt in der Anrufliste nur die gewählte Marke und nur Firmen mit grüner Ampel', function () {
    loginAs('admin');
    $adk = Lead::factory()->create(['organization_id' => Organization::factory()->state(['priority' => 'A'])]);
    $jb = Lead::factory()->create(['brand' => 'jb', 'organization_id' => Organization::factory()->state(['priority' => 'A'])]);
    $busy = adkHistory('interested', attributes: ['next_action_at' => '2026-11-30']);
    $waiting = jbLeadFor($busy);

    Brand::select('jb');
    expect((new CallList)->queue()->pluck('leads.id')->all())->toBe([$jb->id]);

    Brand::select('adk');
    expect((new CallList)->queue()->pluck('leads.id')->all())->toBe([$adk->id]);

    Brand::select(null);
    expect((new CallList)->queue()->pluck('leads.id')->sort()->values()->all())->toBe([$adk->id, $jb->id])
        ->and($waiting->fresh()->crossBrandLight()->color)->toBe('blue');
});

it('zeigt die Tasten der Marke: bei JB IT-Design ohne Förderweg, Taste 0 legt einen ADK-Vorgang an', function () {
    loginAs('admin');
    Lead::factory()->create(['brand' => 'jb', 'organization_id' => Organization::factory()->state(['priority' => 'A'])]);
    Brand::select('jb');

    Livewire::test(CallList::class)
        ->assertSee('JB IT-Design')
        ->assertSee('Vorgang für ADK')
        ->call('selectKey', '0')
        ->assertSet('crossSellingOpen', true)
        ->assertSee('ADK – Akademie für digitale Kompetenz');
});

it('schaltet oben zwischen den Marken um', function () {
    loginAs('admin');

    Livewire::test(BrandSwitcher::class)->assertSee('Alle Marken')->set('brand', 'jb')->assertRedirect();
    expect(Brand::current())->toBe('jb');

    Livewire::test(BrandSwitcher::class)->set('brand', '')->assertRedirect();
    expect(Brand::current())->toBeNull();
});

it('zeigt einem Konto nur für JB IT-Design keine ADK-Vorgänge, Firmen, Personen und Teilnehmerakten', function () {
    $adk = Lead::factory()->create(['contact_id' => Contact::factory()]);
    $jb = Lead::factory()->create(['brand' => 'jb', 'contact_id' => Contact::factory()]);
    $this->actingAs(User::factory()->state(['role' => 'sales', 'brands' => ['jb']])->withTwoFactor()->create());

    expect(Lead::pluck('id')->all())->toBe([$jb->id])
        ->and(Brand::current())->toBe('jb')
        ->and(OrganizationResource::getEloquentQuery()->pluck('id')->all())->toBe([$jb->organization_id])
        ->and(ContactResource::getEloquentQuery()->pluck('id')->all())->toBe([$jb->contact_id]);

    $this->get(LeadResource::getUrl('view', ['record' => $adk]))->assertNotFound();
    $this->get(LeadResource::getUrl('view', ['record' => $jb]))->assertOk()->assertDontSee('Vorgang für ADK');
    $this->get('/teilnehmer')->assertForbidden();
    $this->get('/foerderfaelle')->assertForbidden();

    expect(fn () => Lead::factory()->create())->toThrow(AuthorizationException::class);
});

it('sieht als Konto nur für JB IT-Design die Ampel, aber nicht den ADK-Vorgang dahinter', function () {
    $adk = adkHistory('appointment');
    $jb = jbLeadFor($adk);
    $this->actingAs(User::factory()->state(['role' => 'sales', 'brands' => ['jb']])->withTwoFactor()->create());

    expect($jb->crossBrandLight()->color)->toBe('blue');

    Livewire::test(ViewLead::class, ['record' => $jb->id])
        ->assertSee('persönlich')
        ->assertDontSee(LeadResource::getUrl('view', ['record' => $adk]));
});

it('schlägt je Firma ADK, JB IT-Design oder das Paket vor', function (array $organization, ?string $offer) {
    $lead = Lead::factory()->create(['organization_id' => Organization::factory()->state($organization)]);

    expect(OfferSuggestion::for($lead)?->offer)->toBe($offer);
})->with([
    'nur Inhaber' => [['employee_count' => 1, 'website' => 'https://solo.example'], 'jb'],
    'Angestellte und Website' => [['employee_count' => 12, 'website' => 'https://firma.example'], 'package'],
    'Angestellte ohne Website' => [['employee_count' => 12, 'website' => null], 'adk'],
    'unbekannt, veraltetes PHP' => [['employee_count' => null, 'website' => 'https://alt.example', 'website_php' => '7.4.33', 'website_checked_at' => '2026-10-20'], 'jb'],
    'nichts bekannt' => [['employee_count' => null, 'website' => null], null],
]);

it('macht aus dem alten Merkmal Cross-Selling einen verknüpften Vorgang von JB IT-Design', function () {
    $flagged = Lead::factory()->create(['cross_selling' => true, 'cross_selling_follow_up_at' => '2026-11-05', 'status' => 'no_interest', 'closed_at' => now()]);
    $overdue = Lead::factory()->create(['cross_selling' => true, 'cross_selling_follow_up_at' => '2026-10-01']);

    (require database_path('migrations/2026_10_23_110000_convert_cross_selling_flags.php'))->up();

    $jb = Lead::where('package_lead_id', $flagged->id)->sole();

    expect($jb)->brand->toBe('jb')->status->toBe('later')->channel->toBe('cross_selling')->next_action_at->toDateString()->toBe('2026-11-05')
        ->and($flagged->fresh())->cross_selling->toBeFalse()->package_lead_id->toBe($jb->id)
        ->and(Lead::where('package_lead_id', $overdue->id)->sole()->next_action_at->toDateString())->toBe('2026-10-23');

    loginAs('admin');
    expect($jb->crossBrandLight()->isGreen())->toBeTrue();
});
