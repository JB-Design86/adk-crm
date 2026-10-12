{{-- Vorschau einer E-Mail aus HTML-Code (App\Filament\Forms\MailCode::preview): bereinigt, ohne Signatur, im iframe ohne Skripte. --}}
<div style="display: grid; gap: 0.375rem;">
    <div style="font-size: 0.875rem; font-weight: 500;">Vorschau</div>

    @if (filled($html))
        <iframe
            sandbox
            srcdoc="{{ $html }}"
            title="Vorschau der E-Mail"
            style="width: 100%; height: 28rem; background: #fff; border: 1px solid rgba(127, 127, 127, 0.3); border-radius: 0.5rem;"
        ></iframe>
        <div style="font-size: 0.75rem; opacity: 0.7;">So geht der Text heraus, darunter hängt das CRM Ihre Signatur an. Platzhalter wie {anrede} ersetzt das CRM beim Senden. Die Vorschau aktualisiert sich, wenn Sie das Code-Feld verlassen.</div>
    @else
        <div style="font-size: 0.875rem; opacity: 0.7;">Erscheint, sobald im Code-Feld Text steht.</div>
    @endif
</div>
