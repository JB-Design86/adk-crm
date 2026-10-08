<x-filament-panels::page>
    @php($connection = $this->connection())

    @if (! $this->configured())
        <x-filament::section icon="heroicon-o-wrench-screwdriver">
            <x-slot name="heading">E-Mail aus dem CRM ist noch nicht eingerichtet</x-slot>
            <p class="text-sm">
                Die Verwaltung registriert einmalig eine App in Microsoft Entra und trägt Client-ID, Secret und Mandanten-ID in die Server-Einstellungen ein.
                Die Anleitung steht in <code>docs/BETRIEB.md</code> unter „E-Mail aus dem CRM (Microsoft 365)“. Danach erscheint hier der Knopf „Mit Microsoft 365 verbinden“.
            </p>
        </x-filament::section>
    @elseif (! $connection)
        <x-filament::section icon="heroicon-o-link">
            <x-slot name="heading">Nicht verbunden</x-slot>
            <div class="space-y-3 text-sm">
                <p>Verbinden Sie einmal Ihr eigenes Microsoft-365-Postfach. Danach schreiben Sie E-Mails direkt aus dem Vorgang: Sie gehen von Ihrer Adresse, liegen in Outlook unter „Gesendete Elemente“, und Antworten kommen wie gewohnt in Outlook an.</p>
                <p>Sie werden zur Anmeldeseite von Microsoft weitergeleitet und bestätigen dort die Freigabe. Ihr Kennwort sieht das CRM dabei nicht. Das CRM darf nur senden, nicht Ihre E-Mails lesen.</p>
                <x-filament::button tag="a" :href="route('filament.crm.microsoft.connect')" icon="heroicon-o-link">Mit Microsoft 365 verbinden</x-filament::button>
            </div>
        </x-filament::section>
    @else
        <x-filament::section icon="heroicon-o-check-circle" icon-color="success">
            <x-slot name="heading">Verbunden</x-slot>
            <dl class="grid gap-2 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-500">Postfach</dt><dd class="font-medium">{{ $connection->mailbox }}</dd></div>
                <div><dt class="text-gray-500">Name</dt><dd class="font-medium">{{ $connection->display_name ?? '–' }}</dd></div>
                <div><dt class="text-gray-500">verbunden seit</dt><dd class="font-medium">{{ $connection->created_at->format('d.m.Y H:i') }}</dd></div>
            </dl>
        </x-filament::section>

        <x-filament::section icon="heroicon-o-pencil-square">
            <x-slot name="heading">Signatur</x-slot>
            <x-slot name="description">Steht nach einer Leerzeile unter jeder E-Mail aus dem CRM, mit Formatierung (fett, kursiv, Links) und auf Wunsch mit Logo.</x-slot>

            <form wire:submit="saveSignature" class="space-y-4">
                {{ $this->form }}
                <x-filament::button type="submit" icon="heroicon-o-check">Signatur speichern</x-filament::button>
            </form>
        </x-filament::section>
    @endif

    <x-filament::section icon="heroicon-o-question-mark-circle" collapsible collapsed>
        <x-slot name="heading">So funktioniert es</x-slot>
        <ul class="list-disc space-y-1 pl-5 text-sm">
            <li><strong>E-Mail schreiben:</strong> Auf jeder Vorgangsseite und in der Anrufliste gibt es „E-Mail schreiben“. Vorlage wählen, Text anpassen, senden. Die E-Mail geht aus Ihrem Postfach und liegt in Outlook unter „Gesendete Elemente“.</li>
            <li><strong>Vorlagen:</strong> Betreff, Text und Anhang (z. B. Kursheft) pflegt die Verwaltung unter Verwaltung → E-Mail-Vorlagen. Platzhalter wie {anrede} oder {firma} füllt das CRM aus dem Vorgang.</li>
            <li><strong>Einwilligung:</strong> Werbung per E-Mail ist nur erlaubt, wenn die Person darum gebeten oder eingewilligt hat, auch bei Betrieben (§ 7 UWG). Das bestätigen Sie vor jedem Senden. Adressen auf der Sperrliste lehnt das CRM ab.</li>
            <li><strong>Verlauf und Wiedervorlage:</strong> Jede E-Mail steht mit Empfänger, Betreff und vollem Text im Verlauf des Vorgangs. Die Wiedervorlage setzen Sie gleich beim Senden. Den Status ändert das CRM nicht.</li>
            <li><strong>Antworten</strong> kommen in Outlook an, nicht im CRM. Das CRM liest Ihr Postfach nicht und zählt keine Öffnungen.</li>
            <li><strong>Trennen:</strong> Mit „Verbindung trennen“ endet der Zugriff sofort. Wird ein Konto gesperrt, trennt das CRM die Verbindung automatisch.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
