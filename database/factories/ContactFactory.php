<?php

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    public function definition(): array
    {
        $salutation = fake()->randomElement(['Frau', 'Herr']);

        return [
            'salutation' => $salutation,
            'first_name' => $salutation === 'Frau' ? fake()->firstNameFemale() : fake()->firstNameMale(),
            'last_name' => fake()->lastName(),
            'position' => fake()->randomElement(['Geschäftsführung', 'Personalleitung', 'Büroleitung', 'Inhaber/in', null]),
            'phone_display' => '06131 '.fake()->numerify('######'),
            'email' => fake()->unique()->userName().'@example.org',
            'is_private' => false,
        ];
    }

    public function private(): static
    {
        return $this->state(fn () => [
            'position' => null,
            'is_private' => true,
            'phone_display' => '0151 '.fake()->numerify('########'),
            'organization_id' => null,
        ]);
    }

    public function withPhoneConsent(): static
    {
        return $this->state(fn () => [
            'phone_consent_at' => today()->subDays(fake()->numberBetween(1, 60)),
            'phone_consent_proof' => 'Website-Formular, Häkchen Rückrufwunsch (Testdaten)',
        ]);
    }
}
