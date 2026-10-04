<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\Documents\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Abruf eines Dokuments: Rechte prüfen, entschlüsseln, ausliefern, protokollieren.
 * ?download=1 lädt herunter, sonst werden PDF und Bilder im Browser angezeigt.
 */
class DocumentController extends Controller
{
    public function __invoke(Request $request, Document $document, DocumentService $documents): Response
    {
        abort_unless($document->isVisibleTo($request->user()), 403);

        $inline = $document->isInlineViewable() && ! $request->boolean('download');
        $disposition = HeaderUtils::makeDisposition(
            $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $document->original_name,
            str_replace('%', '', Str::ascii($document->original_name)) ?: 'dokument',
        );

        return response($documents->contents($document, $request->user()), 200, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
