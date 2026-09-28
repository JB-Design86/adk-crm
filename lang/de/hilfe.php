<?php

/*
|--------------------------------------------------------------------------
| Hilfetexte (Fragezeichen im CRM)
|--------------------------------------------------------------------------
|
| „felder“: Das CRM zeigt neben jedem Formularfeld und jeder Angabe mit diesem
| Feldnamen ein ? mit dem Text als Tooltip. Bei Namen wie „organization.priority“
| zählt der letzte Teil („priority“).
| „seiten“: Satz oben auf der jeweiligen Seite.
| „status“: Erklärung der Status (Anrufliste, Status setzen).
|
| Texte hier anpassen, Sie-Form, kurz und konkret.
|
*/

return [

    'felder' => [
        // Vorgang
        'target_group' => 'Über welchen Weg die Weiterbildung bezahlt würde: A Jobcenter, B Agentur für Arbeit, C Beschäftigte eines Betriebs (§ 82 SGB III), D Geschäftsführung, E Arbeitsunfall, Selbstzahler. Bei Kaltakquise zunächst „Betrieb, Zuordnung offen“.',
        'channel' => 'Wie der Kontakt zustande kam. Alles außer „Kaltakquise“ gilt als eingehende Anfrage: rot oben in „Heute“, Wiedervorlage heute, Anruf auch bei Privatpersonen erlaubt.',
        'status' => 'Stand des Vorgangs in der Akquise. Wird über „Status setzen“ oder die Tasten in der Anrufliste geändert, damit Wiedervorlage und Sperrliste automatisch stimmen.',
        'next_action_at' => 'Wiedervorlage: An diesem Tag erscheint der Vorgang in „Heute“ und in der Anrufliste. Wird bei vielen Status automatisch gesetzt.',
        'assigned_to' => 'Wer sich um den Vorgang kümmert. „nur meine“ in Heute und Anrufliste zeigt die eigenen und nicht zugewiesenen Vorgänge.',
        'call_attempts' => 'Wie oft niemand erreicht wurde (Taste 1). Nach dem dritten Versuch fragt die Anrufliste, ob der Vorgang ruhen soll.',
        'last_contact_at' => 'Letzter Kontakt oder Versuch. Ab hier läuft die Löschfrist: Betriebe 24 Monate, Privatpersonen 6 Monate.',
        'cross_selling' => 'Merkmal für JB Design (z. B. Anzeigenbetreuung). Unabhängig vom Status, mit eigener Wiedervorlage. Taste 0.',
        'cross_selling_follow_up_at' => 'Wann wegen JB Design nachgefasst wird.',
        'closed_at' => 'Geschlossene Vorgänge erscheinen nicht mehr in Heute und Anrufliste.',
        'close_reason' => 'Warum der Datensatz falsch ist. Fließt in die Auswertung je Importquelle ein.',
        'organization_id' => 'Betrieb, zu dem der Vorgang gehört. Bei Privatpersonen leer lassen.',
        'contact_id' => 'Person, mit der gesprochen wird. Bei Betrieben die Ansprechperson.',

        // Organisation
        'legal_form' => 'Rechtsform laut Impressum, z. B. GmbH, KG, e.K.',
        'industry' => 'Branche aus der Liste. Der WZ-Code wird dann automatisch ergänzt.',
        'wz_code' => 'Branchenschlüssel des Statistischen Bundesamts (WZ 2008), z. B. 73.1 = Werbung.',
        'priority' => 'Wie gut der Betrieb passt: A sehr gut, B gut, C möglich. Die Anrufliste ruft zuerst A, dann B, dann C an.',
        'employee_count' => 'Ungefähre Zahl der Beschäftigten. Ein-Personen-Betriebe haben meist keinen Bedarf.',
        'is_training_company' => 'Bildet der Betrieb aus? Häufig ein Zeichen für Weiterbildungsbereitschaft.',
        'source' => 'Pflicht: Woher stammen die Daten (Leadliste, Branchenbuch, Vertriebs-Chat …)? Gehört zum Nachweis nach DSGVO.',
        'source_url' => 'Genaue Seite, auf der die Angaben stehen, meist das Impressum. Beantwortet die Frage „Woher haben Sie meine Daten?“.',
        'retrieved_at' => 'Pflicht: An welchem Tag wurden die Daten abgerufen?',
        'check_notes' => 'Warum der Betrieb in Frage kommt, z. B. Stellenanzeigen oder Umstellung auf digitale Abläufe. Hilft beim Gespräch und dient als Nachweis für die Ansprache.',
        'phone_display' => 'Telefonnummer wie gewohnt eingeben. Das CRM speichert sie zusätzlich einheitlich (+49…) für Dubletten und Sperrliste.',
        'website' => 'Startseite. Die Domain dient auch der Dublettenprüfung beim Import.',
        'postal_code' => 'PLZ. Firmenname plus PLZ dient der Dublettenprüfung und der Sperrliste.',

        // Kontakt
        'is_private' => 'Privatperson statt Betrieb. Privatpersonen dürfen nur mit Einwilligung angerufen werden, außer sie haben selbst angefragt. Löschfrist 6 Monate.',
        'privacy_notice_sent_at' => 'Wann der Datenschutzhinweis übermittelt wurde. Wird mit Taste 3 (Unterlagen versendet) automatisch gesetzt.',
        'phone_consent_at' => 'Datum der Einwilligung in Anrufe. Pflicht für Anrufe bei Privatpersonen aus der Kaltakquise, nur zusammen mit dem Nachweis gültig.',
        'phone_consent_proof' => 'Womit die Einwilligung belegt ist, z. B. „Formular vom 12.09., Häkchen Rückruf“. Wird 5 Jahre nach Erteilung bzw. letzter Nutzung gelöscht.',
        'health_consent_at' => 'Nur Zielgruppe E (Arbeitsunfall): Datum der ausdrücklichen Einwilligung. Keine medizinischen Angaben in Notizen.',
        'health_consent_proof' => 'Womit die Einwilligung zu Gesundheitsangaben belegt ist.',
        'position' => 'Funktion im Betrieb, z. B. Geschäftsführung oder Personalleitung.',

        // Termine, Aktivitäten
        'starts_at' => 'Datum und Uhrzeit. Am Termintag erscheint der Vorgang in „Heute“.',
        'note' => 'Kurze Gesprächsnotiz. Keine medizinischen Angaben.',
        'body' => 'Inhalt der Aktivität. Keine medizinischen Angaben.',
        'follow_up_at' => 'Wann wegen Cross-Selling nachgefasst wird.',
        'confirmed' => 'Pflicht bei Werbewiderspruch: Telefon, E-Mail und Firma kommen dauerhaft auf die Sperrliste.',

        // Sperrliste
        'phone_e164' => 'Gesperrte Telefonnummer. Wird automatisch in die einheitliche Form +49… umgewandelt.',
        'blocked_on' => 'Seit wann der Eintrag gilt. Einträge der Sperrliste werden nie automatisch gelöscht.',
        'reason' => 'Anlass, z. B. Werbewiderspruch am Telefon.',

        // Benutzer
        'role' => 'Verwaltung: alles, auch Import, Export, Sperrliste und Benutzer. Mitarbeitende: Vorgänge, Anrufliste, eigene Termine.',

        // Prüfstufen
        'is_active' => 'Inaktive Einträge erscheinen nicht mehr in Formularen und im Import. Vorhandene Werte bleiben erhalten.',
    ],

    'seiten' => [
        'heute' => 'Alles, was heute zu tun ist: fällige und überfällige Wiedervorlagen, neue Anfragen (rot) und die Termine des Tages.',
        'anrufliste' => 'Ein Betrieb nach dem anderen, zuerst Priorität A. Taste drücken (im Notizfeld mit Alt), Enter speichert und springt weiter.',
        'vorgaenge' => 'Alle Vorgänge mit Filtern und Suche. Ein Klick öffnet den Vorgang mit allen Daten, Aktivitäten und Terminen.',
        'kalender' => 'Termine und Wiedervorlagen nach Tag oder Woche. Ein Klick auf einen Tag zeigt alle Einträge.',
        'auswertung' => 'Anrufe, Erreichte, Termine und Unterlagen je Tag und Woche, Quoten je Branche, Kanal und Importquelle.',
        'organisationen' => 'Betriebe und Einrichtungen. Quelle und Abrufdatum sind Pflicht.',
        'kontakte' => 'Personen mit Datenschutzhinweis und Einwilligungen.',
        'import' => 'Leadliste als Excel oder CSV einspielen. Quelle und Abrufdatum sind Pflicht, Dubletten und Sperrliste werden automatisch übersprungen.',
        'importe' => 'Protokoll aller Importe mit übersprungenen Zeilen und Grund.',
        'sperrliste' => 'Betriebe und Personen, die nicht mehr angesprochen werden dürfen. Gilt unbefristet, auch für künftige Importe.',
        'benutzer' => 'Konten anlegen und sperren. Gesperrte Konten werden sofort abgemeldet, Daten bleiben erhalten.',
        'protokoll' => 'Wer hat wann was geändert. Nur lesbar, nicht änderbar.',
        'pruefstufen' => 'Prüfstufen der Leadliste (Branchenmatrix): Kriterien, ob ein Betrieb für die Ansprache in Frage kommt.',
    ],

    'status' => [
        'new' => 'Noch nicht bearbeitet. Eingehende Anfragen erscheinen rot ganz oben, Wiedervorlage heute.',
        'not_reached' => 'Niemand erreicht. Versuch wird gezählt, Wiedervorlage automatisch in 2 Arbeitstagen.',
        'interested' => 'Gespräch geführt, Interesse vorhanden. Wiedervorlagedatum ist Pflicht.',
        'documents_sent' => 'Unterlagen mit Datenschutzhinweis versendet. Datum wird am Kontakt gesetzt, Wiedervorlage in 5 Arbeitstagen.',
        'appointment' => 'Termin vereinbart (Datum, Uhrzeit, Art). Der Vorgang erscheint am Termintag in „Heute“.',
        'later' => 'Später Interesse, z. B. nächster Durchlauf. Wiedervorlagedatum ist Pflicht.',
        'no_interest' => 'Kein Interesse. Vorgang wird geschlossen, die Löschfrist läuft.',
        'no_need' => 'Kein Bedarf, falsche Zielgruppe (z. B. Ein-Personen-Betrieb). Vorgang wird geschlossen.',
        'objection' => 'Werbewiderspruch: Vorgang geschlossen, Telefon, E-Mail und Firma dauerhaft auf der Sperrliste. Mit Sicherheitsabfrage.',
        'wrong_data' => 'Datensatz falsch (Firma besteht nicht, Nummer falsch). Grund wählen. Zählt in der Auswertung je Importquelle.',
        'handed_over' => 'Zusage erhalten, weiter im Förderweg.',
        'cross_selling' => 'Merkmal Cross-Selling JB Design an oder aus, mit eigener Wiedervorlage. Ändert den Status nicht.',
    ],

];
