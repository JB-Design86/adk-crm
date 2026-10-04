<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\Lead;
use App\Models\Participant;
use App\Models\User;
use App\Support\Adk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Ablage der Dokumente: verschlüsselt mit dem APP_KEY (AES-256), Prüfsumme gegen
 * unbemerkte Veränderung, jeder Abruf und jede Löschung im Protokoll.
 */
class DocumentService
{
    public const DISK = 'documents';

    /**
     * @param  array{category: string, title?: ?string, document_date?: mixed, notes?: ?string}  $data
     */
    public function store(Lead $lead, UploadedFile $file, array $data, ?Participant $participant = null, ?User $user = null): Document
    {
        $user ??= auth()->user();
        $category = $data['category'] ?? null;

        if (! array_key_exists((string) $category, config('adk.documents.categories'))) {
            throw ValidationException::withMessages(['category' => 'Bitte wählen Sie eine Dokumentart.']);
        }

        if (Adk::isHealthCategory($category)) {
            if (! $user?->hasPermission('health.view')) {
                throw ValidationException::withMessages(['category' => 'Gesundheitsangaben darf nur die Verwaltung ablegen.']);
            }

            $contact = $participant?->contact ?? $lead->contact;

            if (! $contact?->health_consent_at) {
                throw ValidationException::withMessages(['category' => 'Gesundheitsangaben nur mit eingetragener Einwilligung am Kontakt (Datum und Nachweis).']);
            }
        }

        if ($file->getSize() > config('adk.documents.max_kb') * 1024) {
            throw ValidationException::withMessages(['file' => 'Die Datei ist zu groß (höchstens '.(int) (config('adk.documents.max_kb') / 1024).' MB).']);
        }

        $mime = $file->getMimeType() ?: 'application/octet-stream';

        if (! in_array($mime, config('adk.documents.mime_types'), true)) {
            throw ValidationException::withMessages(['file' => 'Dieser Dateityp ist nicht erlaubt. Erlaubt sind PDF, JPG, PNG, Word, Excel, ODT und E-Mails.']);
        }

        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            throw new RuntimeException('Die Datei konnte nicht gelesen werden.');
        }

        $path = now()->format('Y').'/'.Str::uuid().'.enc';
        Storage::disk(self::DISK)->put($path, Crypt::encryptString($contents));

        try {
            $document = DB::transaction(function () use ($lead, $participant, $file, $data, $category, $mime, $contents, $path, $user) {
                $document = Document::create([
                    'lead_id' => $lead->id,
                    'participant_id' => $participant?->id,
                    'category' => $category,
                    'title' => filled($data['title'] ?? null) ? $data['title'] : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
                    'mime_type' => $mime,
                    'size' => strlen($contents),
                    'path' => $path,
                    'sha256' => hash('sha256', $contents),
                    'document_date' => $data['document_date'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'uploaded_by' => $user?->id,
                ]);

                $this->log($document, 'uploaded', 'Dokument hochgeladen', $user);

                return $document;
            });
        } catch (\Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);

            throw $exception;
        }

        return $document;
    }

    /** Entschlüsselter Inhalt; der Abruf wird protokolliert. */
    public function contents(Document $document, ?User $user = null, bool $log = true): string
    {
        $contents = Crypt::decryptString(Storage::disk(self::DISK)->get($document->path));

        if (! hash_equals($document->sha256, hash('sha256', $contents))) {
            throw new RuntimeException("Prüfsumme von Dokument {$document->id} stimmt nicht.");
        }

        if ($log) {
            $this->log($document, 'downloaded', 'Dokument abgerufen', $user);
        }

        return $contents;
    }

    public function delete(Document $document, ?User $user = null): void
    {
        DB::transaction(function () use ($document, $user) {
            $this->log($document, 'deleted', 'Dokument gelöscht', $user);
            $document->delete();
        });

        Storage::disk(self::DISK)->delete($document->path);
    }

    /**
     * Für den Löschlauf: Dateien und Zeilen ohne Protokolleintrag je Dokument
     * (der Löschlauf protokolliert die Anzahl).
     *
     * @param  iterable<Document>  $documents
     */
    public function purge(iterable $documents): int
    {
        $count = 0;

        foreach ($documents as $document) {
            Storage::disk(self::DISK)->delete($document->path);
            $document->delete();
            $count++;
        }

        return $count;
    }

    private function log(Document $document, string $event, string $description, ?User $user): void
    {
        activity('documents')
            ->causedBy($user ?? auth()->user())
            ->performedOn($document)
            ->event($event)
            ->withProperties([
                'lead_id' => $document->lead_id,
                'participant_id' => $document->participant_id,
                'category' => $document->category,
                'title' => $document->isHealth() ? null : $document->title,
            ])
            ->log($description);
    }
}
