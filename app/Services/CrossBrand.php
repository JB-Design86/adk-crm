<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Organization;
use App\Models\Scopes\AllowedBrandScope;
use App\Support\Adk;
use App\Support\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Ampel zwischen den Marken (config adk.cross_brand): Was hat die andere Marke mit derselben Firma
 * erlebt? Bei Firmen zählt die Organisation, bei Personen ohne Firma der Kontakt.
 * forLead()/forOrganization() für einen Vorgang, scopeFree() dieselben Regeln als Abfrage für Listen.
 * Beide Wege müssen dieselben Regeln haben, CrossBrandTest prüft das.
 */
class CrossBrand
{
    public function forLead(Lead $lead): TrafficLight
    {
        $others = $this->others($lead->brand, $lead->organization_id, $lead->organization_id === null ? $lead->contact_id : null)
            // Verknüpfte Vorgänge (Paket) sperren sich nicht gegenseitig.
            ->reject(fn (Lead $other) => $other->id === $lead->package_lead_id || $other->package_lead_id === $lead->id);

        return $this->evaluate($others, $lead->id);
    }

    /** Ampel für einen neuen Vorgang der Marke $brand an einer vorhandenen Firma (Import). */
    public function forOrganization(Organization $organization, string $brand): TrafficLight
    {
        return $this->evaluate($this->others($brand, $organization->id, null), null);
    }

    /**
     * Nur Vorgänge mit grüner Ampel (Anrufliste). Gleiche Regeln wie evaluate().
     *
     * @param  Builder<Lead>  $query  Abfrage auf „leads“
     */
    public static function scopeFree(Builder $query): Builder
    {
        $rules = config('adk.cross_brand');
        $now = CarbonImmutable::now();

        return $query->whereNotExists(fn (QueryBuilder $q) => $q
            ->selectRaw('1')
            ->from('leads as other')
            ->whereColumn('other.brand', '!=', 'leads.brand')
            ->where(fn (QueryBuilder $same) => $same
                ->where(fn (QueryBuilder $o) => $o->whereNotNull('leads.organization_id')->whereColumn('other.organization_id', 'leads.organization_id'))
                ->orWhere(fn (QueryBuilder $c) => $c->whereNull('leads.organization_id')->whereNotNull('leads.contact_id')->whereColumn('other.contact_id', 'leads.contact_id')))
            ->where(fn (QueryBuilder $p) => $p->whereNull('leads.package_lead_id')->orWhereColumn('other.id', '!=', 'leads.package_lead_id'))
            ->where(fn (QueryBuilder $p) => $p->whereNull('other.package_lead_id')->orWhereColumn('other.package_lead_id', '!=', 'leads.id'))
            ->where(fn (QueryBuilder $light) => $light
                // rot: Werbewiderspruch
                ->where('other.status', 'objection')
                // rot: kein Interesse nach E-Mail
                ->orWhere(fn (QueryBuilder $e) => $e
                    ->whereIn('other.status', $rules['declined_statuses'])
                    ->where('other.closed_at', '>', $now->subMonths($rules['after_email_months']))
                    ->whereExists(fn (QueryBuilder $a) => $a->selectRaw('1')->from('activities')->whereColumn('activities.lead_id', 'other.id')->where('activities.type', 'email')))
                // blau: Kunde der anderen Marke
                ->orWhereNotNull('other.contracted_at')
                ->orWhere(fn (QueryBuilder $w) => $w->where('other.status', 'won')->where('other.closed_at', '>', $now->subMonths($rules['customer_months'])))
                // blau: läuft dort gerade, oder dort wird die Firma schon länger angerufen
                ->orWhere(fn (QueryBuilder $o) => $o
                    ->whereNull('other.closed_at')
                    ->where(fn (QueryBuilder $x) => $x->whereIn('other.status', $rules['active_statuses'])->orWhereColumn('other.id', '<', 'leads.id')))
                // gelb: kein Interesse ohne E-Mail, Wartezeit
                ->orWhere(fn (QueryBuilder $y) => $y
                    ->whereIn('other.status', $rules['declined_statuses'])
                    ->where('other.closed_at', '>', $now->subMonths($rules['cooldown_months'])))));
    }

    /** @return Collection<int, Lead> Vorgänge der anderen Marken, auch für Konten, die diese Marken nicht sehen */
    private function others(string $brand, ?int $organizationId, ?int $contactId): Collection
    {
        if ($organizationId === null && $contactId === null) {
            return collect();
        }

        return Lead::withoutGlobalScope(AllowedBrandScope::class)
            ->where('brand', '!=', $brand)
            ->when(
                $organizationId !== null,
                fn (Builder $q) => $q->where('organization_id', $organizationId),
                fn (Builder $q) => $q->where('contact_id', $contactId),
            )
            ->withCount(['activities as emails_count' => fn (Builder $q) => $q->where('type', 'email')])
            ->orderBy('id')
            ->get();
    }

    /** @param  Collection<int, Lead>  $others */
    private function evaluate(Collection $others, ?int $leadId): TrafficLight
    {
        $rules = config('adk.cross_brand');
        $now = CarbonImmutable::now();
        $declined = fn (Lead $o) => in_array($o->status, $rules['declined_statuses'], true) && $o->closed_at !== null;
        $closedOn = fn (Lead $o) => CarbonImmutable::instance($o->closed_at);

        if ($o = $others->first(fn (Lead $o) => $o->status === 'objection')) {
            return new TrafficLight('red', 'Werbewiderspruch bei '.Brand::label($o->brand).'. Die Firma darf von keiner Marke mehr angesprochen werden.', other: $o);
        }

        $o = $others->first(fn (Lead $o) => $declined($o) && $o->emails_count > 0 && $closedOn($o)->addMonths($rules['after_email_months'])->isFuture());

        if ($o) {
            $until = $closedOn($o)->addMonths($rules['after_email_months']);

            return new TrafficLight('red', Brand::label($o->brand).' hat E-Mails geschickt, am '.$closedOn($o)->format('d.m.Y').' „'.Adk::statusLabel($o->status).'“. Gesperrt bis '.$until->format('d.m.Y').'.', $until, $o);
        }

        $o = $others->first(fn (Lead $o) => $o->contracted_at !== null
            || ($o->status === 'won' && $o->closed_at !== null && $closedOn($o)->gt($now->subMonths($rules['customer_months']))));

        if ($o) {
            return new TrafficLight('blue', 'Kunde bei '.Brand::label($o->brand).'. Nicht kalt anrufen, im Kundengespräch persönlich ansprechen.', other: $o);
        }

        if ($o = $others->first(fn (Lead $o) => $o->closed_at === null && in_array($o->status, $rules['active_statuses'], true))) {
            return new TrafficLight('blue', 'Läuft gerade bei '.Brand::label($o->brand).' („'.Adk::statusLabel($o->status).'“). Nicht kalt anrufen, im Gespräch dort persönlich ansprechen.', other: $o);
        }

        if ($o = $others->first(fn (Lead $o) => $o->closed_at === null && ($leadId === null || $o->id < $leadId))) {
            return new TrafficLight('blue', Brand::label($o->brand).' versucht diese Firma schon zu erreichen. Beim Anruf dort gleich mit ansprechen (Paket), damit sie nicht zweimal angerufen wird.', other: $o);
        }

        $o = $others->first(fn (Lead $o) => $declined($o) && $closedOn($o)->addMonths($rules['cooldown_months'])->isFuture());

        if ($o) {
            $until = $closedOn($o)->addMonths($rules['cooldown_months']);

            return new TrafficLight('yellow', 'Bei '.Brand::label($o->brand).' am '.$closedOn($o)->format('d.m.Y').' „'.Adk::statusLabel($o->status).'“. Frei ab '.$until->format('d.m.Y').'.', $until, $o);
        }

        return TrafficLight::green();
    }
}
