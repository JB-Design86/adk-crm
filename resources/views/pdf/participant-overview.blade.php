<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Teilnehmerakte {{ $participant->number }}</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #111; }
        h1 { font-size: 15pt; margin: 0 0 2mm; }
        h2 { font-size: 11pt; margin: 6mm 0 2mm; border-bottom: 0.3mm solid #999; padding-bottom: 1mm; }
        .meta { color: #555; font-size: 8.5pt; margin-bottom: 4mm; }
        table { width: 100%; border-collapse: collapse; }
        td, th { text-align: left; vertical-align: top; padding: 1mm 1.5mm; border-bottom: 0.2mm solid #ddd; }
        th { background: #f1f1f1; font-weight: bold; }
        .label { width: 38%; color: #555; }
        .open { color: #999; }
    </style>
</head>
<body>
    @php($contact = $participant->contact)
    @php($case = $participant->fundingCase)

    <h1>Teilnehmerakte {{ $participant->number }}</h1>
    <div class="meta">ADK – Akademie für digitale Kompetenz · ausgegeben am {{ now()->format('d.m.Y H:i') }}@if ($createdBy) von {{ $createdBy }}@endif · Aufbewahrung bis zehn Jahre nach Ende der Maßnahme</div>

    <h2>Person</h2>
    <table>
        <tr><td class="label">Name</td><td>{{ $contact->fullName() }}</td></tr>
        @if ($contact->organization)<tr><td class="label">Betrieb</td><td>{{ $contact->organization->name }}</td></tr>@endif
        <tr><td class="label">Geburtsdatum</td><td>{{ $participant->birth_date?->format('d.m.Y') ?? '–' }}</td></tr>
        <tr><td class="label">Anschrift</td><td>{{ $participant->street ? $participant->street.', '.$participant->postal_code.' '.$participant->city : '–' }}</td></tr>
        <tr><td class="label">Telefon / E-Mail</td><td>{{ $contact->phone_display ?? '–' }} / {{ $contact->email ?? '–' }}</td></tr>
    </table>

    <h2>Kurs und Förderung</h2>
    <table>
        <tr><td class="label">Kurs bzw. Maßnahme</td><td>{{ $participant->course_name ?? '–' }}</td></tr>
        <tr><td class="label">Zeitraum</td><td>{{ $participant->course_starts_on?->format('d.m.Y') ?? '–' }} bis {{ $participant->course_ends_on?->format('d.m.Y') ?? '–' }}</td></tr>
        <tr><td class="label">Stand</td><td>{{ $participant->stateLabel() }}@if ($participant->left_on), letzter Kurstag {{ $participant->left_on->format('d.m.Y') }}@endif @if ($participant->exit_reason)({{ $participant->exit_reason }})@endif</td></tr>
        <tr><td class="label">Verbleib</td><td>{{ $participant->placement_status ? \App\Support\Adk::placementLabel($participant->placement_status).' (erhoben '.$participant->placement_recorded_on?->format('d.m.Y').')' : '–' }}</td></tr>
        @if ($case)
            <tr><td class="label">Förderweg</td><td>{{ $case->pathwayLabel() }}, eingeschrieben {{ $case->enrolled_at?->format('d.m.Y') ?? '–' }}</td></tr>
            <tr><td class="label">Kundennummer / Gutschein</td><td>{{ $case->customer_number ?? '–' }} / {{ $case->voucher_number ?? '–' }}@if ($case->voucher_valid_until), gültig bis {{ $case->voucher_valid_until->format('d.m.Y') }}@endif</td></tr>
            <tr><td class="label">Ansprechperson Kostenträger</td><td>{{ $case->funder_contact ?? '–' }}</td></tr>
        @endif
        @if ($showHealth && $participant->accommodation_notes)
            <tr><td class="label">Nachteilsausgleich</td><td>{{ $participant->accommodation_notes }}</td></tr>
        @endif
    </table>

    @if ($case && $case->completedSteps->isNotEmpty())
        <h2>Förderweg</h2>
        <table>
            <tr><th>Schritt</th><th>Ergebnis</th><th>Datum</th></tr>
            @foreach ($case->completedSteps as $entry)
                <tr><td>{{ $entry->step?->name }}</td><td>{{ $entry->resultLabel() }}</td><td>{{ $entry->completed_on->format('d.m.Y') }}</td></tr>
            @endforeach
        </table>
    @endif

    <h2>Checkliste</h2>
    <table>
        <tr><th>Phase</th><th>Punkt</th><th>erledigt</th><th>Dokument</th></tr>
        @foreach (config('adk.checklist_phases') as $phase => $phaseLabel)
            @foreach ($checklist->get($phase, collect()) as $row)
                @if ($row['checks']->isEmpty())
                    <tr class="open"><td>{{ $phaseLabel }}</td><td>{{ $row['item']->name }}</td><td>offen</td><td></td></tr>
                @else
                    @foreach ($row['checks'] as $check)
                        <tr>
                            <td>{{ $phaseLabel }}</td>
                            <td>{{ $row['item']->name }}@if ($check->note)<br><small>{{ $check->note }}</small>@endif</td>
                            <td>{{ $check->done_on->format('d.m.Y') }}@if ($check->user)<br><small>{{ $check->user->name }}</small>@endif</td>
                            <td>{{ $check->document_id && isset($names[$check->document_id]) ? $names[$check->document_id] : '' }}</td>
                        </tr>
                    @endforeach
                @endif
            @endforeach
        @endforeach
    </table>

    <h2>Dokumente ({{ $documents->count() }})</h2>
    <table>
        <tr><th>Datei im Ordner „Dokumente“</th><th>Art</th><th>Datum</th><th>hochgeladen</th></tr>
        @forelse ($documents as $document)
            <tr>
                <td>{{ $names[$document->id] }}<br><small>{{ $document->title }}</small></td>
                <td>{{ $document->categoryLabel() }}</td>
                <td>{{ $document->document_date?->format('d.m.Y') ?? '–' }}</td>
                <td>{{ $document->created_at->format('d.m.Y') }}@if ($document->uploader)<br><small>{{ $document->uploader->name }}</small>@endif</td>
            </tr>
        @empty
            <tr><td colspan="4">keine</td></tr>
        @endforelse
    </table>

    <h2>Verlauf</h2>
    <table>
        <tr><th>Datum</th><th>Art</th><th>Eintrag</th></tr>
        @foreach ($participant->lead->activities->sortBy('occurred_at') as $activity)
            <tr><td>{{ $activity->occurred_at->format('d.m.Y') }}</td><td>{{ $activity->typeLabel() }}</td><td>{{ $activity->body ?? \App\Support\Adk::statusLabel($activity->status_to) }}</td></tr>
        @endforeach
    </table>
</body>
</html>
