<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Participant;
use App\Models\User;
use App\Services\Documents\DocumentService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Akte vollständig als ZIP: PDF-Übersicht (Stammdaten, Förderweg, Checkliste, Dokumentliste,
 * Verlauf) und alle Dokumente im Original. So bleibt die Akte auch ohne das CRM lesbar.
 * Gesundheitsangaben nur, wenn die ausgebende Person sie sehen darf.
 */
class ParticipantArchive
{
    public function __construct(private DocumentService $documents) {}

    /** @return string Pfad zur temporären ZIP-Datei */
    public function build(Participant $participant, ?User $user = null): string
    {
        $user ??= auth()->user();
        $participant->loadMissing(['contact.organization', 'fundingCase.completedSteps.step', 'lead.activities.user', 'checks.item', 'checks.user']);

        $documents = $participant->lead->documents()
            ->with('uploader')
            ->where(fn ($q) => $q->whereNull('participant_id')->orWhere('participant_id', $participant->id))
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Document $document) => $document->isVisibleTo($user))
            ->values();

        $path = tempnam(sys_get_temp_dir(), 'akte').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP-Datei konnte nicht angelegt werden.');
        }

        $names = [];

        foreach ($documents as $index => $document) {
            $name = $this->fileName($document, $index + 1);
            $names[$document->id] = $name;
            // Einzelabrufe nicht je Datei protokollieren, sondern einmal die Ausgabe der Akte.
            $zip->addFromString('Dokumente/'.$name, $this->documents->contents($document, $user, log: false));
        }

        $zip->addFromString('00_Uebersicht_'.$participant->number.'.pdf', $this->overviewPdf($participant, $documents, $names, $user));
        $zip->close();

        activity('participants')
            ->causedBy($user)
            ->performedOn($participant)
            ->event('zip_export')
            ->withProperties(['documents' => $documents->count()])
            ->log('Teilnehmerakte als ZIP ausgegeben');

        return $path;
    }

    private function fileName(Document $document, int $number): string
    {
        $extension = pathinfo($document->original_name, PATHINFO_EXTENSION) ?: 'bin';
        $date = ($document->document_date ?? $document->created_at)->format('Y-m-d');

        return sprintf('%02d_%s_%s.%s', $number, $date, Str::limit(Str::slug($document->title, '_', 'de'), 60, ''), Str::lower($extension));
    }

    /**
     * @param  Collection<int, Document>  $documents
     * @param  array<int, string>  $names
     */
    private function overviewPdf(Participant $participant, $documents, array $names, ?User $user): string
    {
        $html = view('pdf.participant-overview', [
            'participant' => $participant,
            'checklist' => $participant->checklist(),
            'documents' => $documents,
            'names' => $names,
            'showHealth' => (bool) $user?->hasPermission('health.view'),
            'createdBy' => $user?->name,
        ])->render();

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }
}
