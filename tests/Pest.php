<?php

use App\Models\FundingCase;
use App\Models\User;
use App\Services\Documents\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
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

/** Kleines, echtes PDF zum Hochladen (fileinfo erkennt application/pdf). */
function fakePdf(string $name = 'test.pdf'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

    return new UploadedFile($path, $name, 'application/pdf', null, true);
}

/** Pflichtunterlagen eines Förderfalls hochladen, damit die Einschreibung möglich ist. */
function uploadRequiredDocuments(FundingCase $case): void
{
    foreach (array_keys($case->missingDocuments()) as $category) {
        app(DocumentService::class)->store($case->lead, fakePdf($category.'.pdf'), ['category' => $category]);
    }
}

/** PDF für Livewire-Uploads in Tests (Filament-Formulare). */
function fakePdfUpload(string $name = 'test.pdf'): File
{
    return UploadedFile::fake()->createWithContent($name, '%PDF-1.4
1 0 obj << /Type /Catalog >> endobj
trailer << /Root 1 0 R >>
%%EOF
');
}
