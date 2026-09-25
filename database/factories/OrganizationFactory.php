<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Erfundene Betriebe aus Rhein-Main.
 *
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /** Orte in Rhein-Main mit Postleitzahl und Vorwahl. */
    public const CITIES = [
        ['Mainz', '55116', '06131'],
        ['Mainz', '55122', '06131'],
        ['Wiesbaden', '65183', '0611'],
        ['Wiesbaden', '65189', '0611'],
        ['Frankfurt am Main', '60311', '069'],
        ['Frankfurt am Main', '60486', '069'],
        ['Darmstadt', '64283', '06151'],
        ['Offenbach am Main', '63065', '069'],
        ['Rüsselsheim am Main', '65428', '06142'],
        ['Ingelheim am Rhein', '55218', '06132'],
        ['Bingen am Rhein', '55411', '06721'],
        ['Hanau', '63450', '06181'],
        ['Bad Homburg', '61348', '06172'],
        ['Hofheim am Taunus', '65719', '06192'],
        ['Nieder-Olm', '55268', '06136'],
        ['Oppenheim', '55276', '06133'],
    ];

    public const LEGAL_FORMS = ['GmbH', 'GmbH & Co. KG', 'UG (haftungsbeschränkt)', 'e.K.', 'GbR', 'PartG mbB', 'AG', 'KG'];

    public function definition(): array
    {
        $industries = config('adk.industries');
        $industry = fake()->randomElement(array_keys($industries));
        [$city, $postalCode, $areaCode] = fake()->randomElement(self::CITIES);
        $legalForm = fake()->randomElement(self::LEGAL_FORMS);
        $name = fake()->lastName().' '.fake()->randomElement(['& Partner', 'Service', 'Consulting', 'Büro', 'Gruppe', '']).' '.$legalForm;
        $slug = str($name)->before(' ')->ascii()->lower()->slug();

        return [
            'name' => trim(preg_replace('/\s+/', ' ', $name)),
            'legal_form' => $legalForm,
            'industry' => $industry,
            'wz_code' => $industries[$industry],
            'priority' => fake()->randomElement(['A', 'A', 'B', 'B', 'B', 'C']),
            'street' => fake()->streetAddress(),
            'postal_code' => $postalCode,
            'city' => $city,
            'phone_display' => $areaCode.' '.fake()->numerify(fake()->randomElement(['######', '#######', '### ###'])),
            'email' => "info@{$slug}-".fake()->unique()->numberBetween(100, 99999).'.example',
            'website' => "https://www.{$slug}-".fake()->numberBetween(100, 99999).'.example',
            'employee_count' => fake()->numberBetween(3, 250),
            'is_training_company' => fake()->boolean(40),
            'source' => fake()->randomElement(['Leadliste Rhein-Main 2026-09', 'IHK-Firmenverzeichnis (Test)', 'Branchenbuch (Test)']),
            'retrieved_at' => fake()->dateTimeBetween('-2 months', '-1 week'),
            'check_1_passed' => true,
            'check_2_passed' => true,
            'check_3_passed' => fake()->boolean(90),
            'check_4_passed' => fake()->boolean(80),
            'check_5_passed' => fake()->optional(0.7)->boolean(80),
        ];
    }
}
