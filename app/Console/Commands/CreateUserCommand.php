<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Ersteinrichtung: Konto über die Kommandozeile anlegen (z. B. das erste Verwaltungskonto).
 * Die Zwei-Faktor-Anmeldung richtet die Person bei der ersten Anmeldung selbst ein.
 */
class CreateUserCommand extends Command
{
    protected $signature = 'adk:benutzer-anlegen
        {--name= : Name}
        {--email= : E-Mail-Adresse (Anmeldename)}
        {--role=admin : Rolle (admin oder staff)}';

    protected $description = 'Legt ein Benutzerkonto an (Ersteinrichtung: erstes Verwaltungskonto)';

    public function handle(): int
    {
        $name = $this->option('name') ?: text('Name', required: true);
        $email = $this->option('email') ?: text('E-Mail-Adresse', required: true);
        $role = $this->option('role') ?: select('Rolle', array_keys(config('adk.roles')), default: 'admin');
        $plain = password('Kennwort (mindestens 10 Zeichen)', required: true);
        $confirmation = password('Kennwort wiederholen', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'role' => $role, 'password' => $plain, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', Rule::unique('users', 'email')],
                'role' => ['required', Rule::in(array_keys(config('adk.roles')))],
                'password' => ['required', 'confirmed', Password::min(10)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'password' => $plain,
        ]);

        $this->info("Konto {$user->email} ({$user->roleLabel()}) wurde angelegt.");
        $this->line('Bei der ersten Anmeldung wird die Zwei-Faktor-Anmeldung eingerichtet.');

        return self::SUCCESS;
    }
}
