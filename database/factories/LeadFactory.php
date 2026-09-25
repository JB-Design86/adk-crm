<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Lead;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'target_group' => 'company_open',
            'channel' => 'cold_call',
            'status' => 'new',
        ];
    }

    /** Eingehende Anfrage einer Privatperson. */
    public function inboundPrivate(string $channel = 'website_form'): static
    {
        return $this->state(fn () => [
            'organization_id' => null,
            'contact_id' => Contact::factory()->private(),
            'target_group' => fake()->randomElement(['A', 'B', 'E', 'self_payer']),
            'channel' => $channel,
        ]);
    }

    /** Privatperson aus Kaltakquise (z. B. Empfehlung ohne Einwilligung). */
    public function coldPrivate(): static
    {
        return $this->state(fn () => [
            'organization_id' => null,
            'contact_id' => Contact::factory()->private(),
            'target_group' => 'self_payer',
            'channel' => 'cold_call',
        ]);
    }
}
