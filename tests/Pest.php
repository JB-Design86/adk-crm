<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/** Angemeldetes Konto mit eingerichteter Zwei-Faktor-Anmeldung. */
function loginAs(string $role = 'staff'): User
{
    $user = User::factory()->state(['role' => $role])->withTwoFactor()->create();
    test()->actingAs($user);

    return $user;
}
