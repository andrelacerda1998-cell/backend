<?php

return [
    'paymentSent' => [
        'title' => 'Zahlung gesendet',
        'description' => 'Zahlung über :value € gesendet',
    ],
    'mail' => [
        'problemReportedOps' => [
            'subject' => 'Problem gemeldet — Service #:service_id',
            'line1' => 'Service #:service_id (:service_type) hat ein Problem, gemeldet von :reported_by: :reason.',
            'line2' => 'Kunde: :customer_name (:customer_phone). Fachkraft: :vendor_name.',
            'line3' => 'Der automatische Abschluss ist angehalten, bis jemand entscheidet: abschließen und zahlen, erstatten oder eine andere Fachkraft schicken.',
            'by_customer' => 'dem Kunden',
            'by_vendor' => 'der Fachkraft',
            'reasons' => [
                'not_done' => 'die Arbeit wurde nicht erledigt',
                'poor_quality' => 'schlechte Arbeit',
                'damage' => 'Schaden in der Wohnung',
                'price' => 'ein Problem mit dem Preis',
                'no_show' => 'die Fachkraft ist nicht erschienen',
                'customer_absent' => 'der Kunde war nicht da',
                'other' => 'ein anderer Grund',
            ],
        ],
        'paymentSent' => [
            'subject' => 'Zahlung gesendet',
            'greeting' => 'Hallo',
            'line1' => 'Wir haben die Zahlung über <span style="color: #FABB5A;">:amount €</span> am <span style="color: #FABB5A">:date</span> an die IBAN <span style="color: #FABB5A;">:iban</span> überwiesen.',
            'line2' => 'Der Betrag sollte in den nächsten Tagen auf deinem Konto erscheinen.',
            'line3' => 'Bei Fragen oder wenn du weitere Informationen brauchst, melde dich einfach bei uns.',
            'line4' => 'Danke für die gute Zusammenarbeit. Viele Grüße,',
        ],
        'confirmAccount' => [
            'subject' => 'Konto bestätigen',
            'greetings' => 'Hallo',
            'line1' => 'Danke für deine Registrierung! Klicke auf den Button unten, um deine E-Mail-Adresse zu bestätigen.',
            'line2' => 'Wenn du kein Konto erstellt hast, ignoriere diese E-Mail einfach',
            'line3' => 'Viele Grüße, dein Piquet-Team',
            'button' => 'Konto bestätigen',
            'copyLink' => 'Wenn der Button nicht funktioniert, kopiere diesen Link in deinen Browser:',
        ],
        'passwordReset' => [
            'subject' => 'Passwort zurücksetzen',
            'greetings' => 'Hallo',
            'line1' => 'Du erhältst diese E-Mail, weil für dein Konto ein neues Passwort angefordert wurde.',
            'action' => 'Passwort zurücksetzen',
            'line2' => 'Wenn du das nicht angefordert hast, musst du nichts weiter tun.',
            'salutation' => 'Viele Grüße, dein Piquet-Team',
        ],
        'twoFactorCode' => [
            'subject' => 'Dein Zugangscode für das Backoffice',
            'greetings' => 'Hallo',
            'line1' => 'Verwende diesen Code, um die Anmeldung im Backoffice abzuschließen:',
            'line2' => 'Dieser Code läuft in :minutes Minuten ab.',
            'line3' => 'Wenn du dich nicht anmelden wolltest, ignoriere diese E-Mail — dein Konto ist weiterhin sicher.',
            'salutation' => 'Viele Grüße, dein Piquet-Team',
        ],
        'noShowOps' => [
            'subject' => 'Mögliches Nichterscheinen — Auftrag #:service_id',
            'greetings' => 'Hallo',
            'line1' => 'Der Auftrag #:service_id (:service_type) war für :time Uhr geplant und die Fachkraft hat ihn noch nicht begonnen.',
            'line2' => 'Fachkraft: :vendor_name. Kunde: :customer_name (:customer_phone).',
            'line3' => 'Am besten kontaktierst du die Fachkraft und weist den Auftrag bei Bedarf neu zu oder erstattest dem Kunden den Betrag.',
            'salutation' => 'Viele Grüße, dein Piquet-Team',
        ],
        'userRegistered' => [
            'line1' => 'Willkommen in unserer App!',
            'line2' => 'Klicke auf den Button unten, um deine E-Mail-Adresse zu bestätigen.',
            'action' => 'E-Mail bestätigen',
            'line3' => 'Danke für deine Registrierung!',
        ],
        'documents' => [
            'accept' => [
                'greetings' => 'Hallo ',
                'line1' => 'Dein Dokument wurde erfolgreich geprüft und freigegeben.',
                'line2' => 'Danke, dass du unsere Plattform nutzt. Bei Fragen wende dich einfach an unser Support-Team.',
                'salutation' => 'Viele Grüße, dein Piquet-Team',
            ],
            'deny' => [
                'greetings' => 'Hallo ',
                'line1' => 'Leider müssen wir dir mitteilen, dass dein Dokument nach der Prüfung abgelehnt wurde.',
                'line2' => 'Bitte stelle sicher, dass alle erforderlichen Angaben korrekt und vollständig sind, bevor du es erneut einreichst.',
                'line3' => 'Wenn du Fragen hast oder weitere Hilfe brauchst, wende dich gerne an unser Support-Team.',
                'salutation' => 'Viele Grüße, dein Team',
            ],
        ],
    ],
    'newMessage' => [
        'title' => 'Du hast eine neue Nachricht',
        'description' => 'Der Kunde hat dir eine neue Nachricht zum Auftrag geschickt: ',
        'description_vendor' => 'Die Fachkraft hat dir eine neue Nachricht zum Auftrag geschickt: ',
    ],
    'serviceStuck' => [
        'title' => 'Auftrag noch offen',
        'description' => 'Der Auftrag ":service" läuft seit :hours Stunden. Schließe ihn ab, damit du bezahlt wirst.',
    ],
    'noShowPenalty' => [
        'title' => 'Du bist nicht erschienen',
        'description' => 'Der Auftrag für :service_type wurde als Nichterscheinen gewertet. :amount € wurden von deinem Verdienst abgezogen — die Hälfte von dem, was du bekommen hättest. Wenn das ein Fehler ist, melde dich beim Support.',
    ],
    'noShow' => [
        'vendor' => [
            'title' => 'Bist du noch nicht los?',
            'description' => 'Dein Auftrag für :service_type mit :customer_name war für :time geplant. Wenn du unterwegs bist, tippe in der App auf "Unterwegs"; wenn du es nicht mehr schaffst, sag uns Bescheid.',
        ],
        'customer' => [
            'title' => 'Wir prüfen deinen Auftrag',
            'description' => 'Dein Auftrag für :service_type war für :time geplant. Wir klären das mit der Fachkraft und melden uns bald bei dir.',
        ],
        'ops' => [
            'title' => 'Mögliches Nichterscheinen — Auftrag #:service_id',
            'description' => 'Auftrag #:service_id (:service_type) für :time mit :vendor_name wurde noch nicht begonnen. Kunde: :customer_name.',
        ],
    ],
    'incompleteProfile' => [
        'greeting' => 'Hallo :name,',
        'action' => 'Profil vervollständigen',
        'default' => [
            'title' => '{1} Noch :steps Schritt|[2,*] Noch :steps Schritte',
            'description' => 'Vervollständige dein Profil, um Anfragen in deiner Zone zu erhalten.',
        ],
        'with_requests' => [
            'title' => 'Anfragen warten auf dich',
            'description' => '{1} Diese Woche kam :requests Anfrage in deiner Zone an. Vervollständige dein Profil, um sie annehmen zu können.|[2,*] Diese Woche kamen :requests Anfragen in deiner Zone an. Vervollständige dein Profil, um sie annehmen zu können.',
        ],
    ],
    'newService' => [
        'title' => 'Neuer Auftrag: ',
        'description' => 'Du hast 60 Sekunden zum Annehmen',
        'description_schedule' => 'Du hast 20 Minuten zum Annehmen',
    ],
    'matchingInvitation' => [
        'title' => 'Anfrage: ',
        'description' => 'Sag, ob du Zeit hast. Der Kunde wählt unter den Antworten aus.',
    ],
    'matchingOutcome' => [
        'lost' => [
            'title' => 'Der Kunde hat jemand anderen gewählt',
            'description' => 'Dieser Auftrag ging an eine andere Person. Danke für deine Antwort.',
        ],
        'closed' => [
            'title' => 'Der Auftrag wurde storniert',
            'description' => 'Der Kunde hat nicht rechtzeitig bezahlt. Du kannst wieder andere Aufträge annehmen.',
        ],
    ],
    'customRequest' => [
        'label' => 'deine individuelle Anfrage',
    ],
    'customRequestDispatched' => [
        'title' => 'Deine Anfrage ist bei den Profis',
        'description' => 'Wir haben deine Beschreibung gesehen und weitergeleitet. Wir melden uns, sobald jemand verfügbar ist.',
    ],
    'matchingCandidatesReady' => [
        'title' => 'Jemand kann kommen',
        'description' => 'Profis verfügbar für :service_type. Wähle, wen du möchtest.',
    ],
    'serviceWon' => [
        'title' => 'Du hast den Auftrag',
        'description' => 'Der Kunde hat dich für :service_type ausgewählt und bezahlt. Du kannst dich auf den Weg machen.',
    ],
    'matchingFailed' => [
        'title' => 'Gerade niemand verfügbar',
        'description' => 'Wir haben keine Profis für :service_type gefunden. Versuche es noch einmal oder wähle eine andere Zeit.',
    ],
    'serviceTimedOut' => [
        'title' => 'Anfrage ohne Antwort',
        'description' => 'Niemand hat rechtzeitig auf deine Anfrage für :service_type geantwortet. Wir haben sie storniert — du kannst es noch einmal versuchen.',
        'description_scheduled' => 'Dein Termin für :service_type wurde nicht rechtzeitig bestätigt und daher storniert. Du kannst einen neuen Termin buchen.',
    ],
    'scheduledService' => [
        'title' => 'Neuer Auftrag für :when geplant',
        'description' => 'für :service_type',
        'when' => [
            'tomorrow' => 'morgen',
            'date' => 'den :day',
        ],
    ],
    'canceledService' => [
        'title' => 'Auftrag storniert',
        'description' => 'Der Kunde hat die Anfrage storniert: ',
    ],
    'acceptedService' => [
        'title' => 'Auftrag angenommen',
        'description' => 'Die Fachkraft hat dein Angebot angenommen: ',
    ],
    'conviteRecompensa' => [
        'title' => 'Du hast :valor € verdient 🎉',
        'description' => ':amigo hat den ersten Auftrag bei Piquet abgeschlossen. Du hast :valor € in deiner Wallet, gültig :meses Monate.',
    ],
    'serviceAutoClosed' => [
        'title' => 'Service abgeschlossen',
        'description' => ':hours Stunden sind ohne gemeldetes Problem vergangen, deshalb haben wir deinen Service :service_type abgeschlossen. Erzähl uns, wie es war.',
    ],
    'problemReportedVendor' => [
        'title' => 'Der Kunde hat ein Problem gemeldet',
        'description' => 'Der Kunde hat beim Service :service_type ein Problem gemeldet. Piquet prüft den Fall; die Zahlung ist bis dahin angehalten.',
    ],
    'vendorCantFindCustomer' => [
        'title' => 'Die Fachkraft findet dich nicht',
        'description' => ':vendor_name ist an der Serviceadresse und findet dich nicht. Ruf an oder antworte im Chat.',
    ],
    'finishedService' => [
        'title' => 'Die Fachkraft hat den Service abgeschlossen',
        'description' => 'Dein Service :service_type ist abgeschlossen. Wenn etwas nicht stimmt, melde in den nächsten :hours Stunden ein Problem; danach schließen wir den Service automatisch.',
    ],
    'vendorArrivedService' => [
        'title' => 'Fachkraft angekommen',
        'description' => 'ist bei dir angekommen',
    ],
    'vendorOnTheWay' => [
        'title' => 'Fachkraft auf dem Weg',
        'description' => 'ist auf dem Weg zu deiner Adresse',
    ],
    'confirmRecurringSchedule' => [
        'title' => 'Bestätige deinen nächsten Auftrag',
        'description' => ':service_type am :day um :time — bestätige und bezahle, um den Termin zu sichern.',
    ],
    'recurringScheduleReleased' => [
        'title' => 'Termin verfallen',
        'description' => ':service_type am :day wurde nicht rechtzeitig bestätigt und der Termin ist wieder frei. Du kannst jederzeit neu buchen.',
    ],
    'scheduleAttendanceReminder' => [
        'title' => 'Auftrag in 3 Tagen',
        'description' => ':service_type am :day um :time — bestätige, dass du kommst, damit der Kunde weiß, dass alles geklärt ist.',
    ],
    'scheduleReminder' => [
        'customer' => [
            'title' => 'Auftragserinnerung',
            'description' => ':vendor_name ist in 1 Stunde für :service_type bei dir',
        ],
        'vendor' => [
            'title' => 'Auftragserinnerung',
            'description' => 'Du hast in 1 Stunde einen Auftrag für :service_type mit :customer_name',
        ],
    ],
    'scheduleCanceled' => [
        'customer' => [
            'title' => 'Termin storniert',
            'description' => ':vendor_name hat den Termin für :service_type storniert',
        ],
        'vendor' => [
            'title' => 'Termin storniert',
            'description' => ':customer_name hat den Termin für :service_type storniert',
        ],
    ],
    'serviceExtra' => [
        'item' => [
            'time' => '+:minutes Min. (:amount €)',
            'part' => ':description (:amount €)',
        ],
        'requested' => [
            'title' => 'Anfrage der Fachkraft',
            'time' => 'Die Fachkraft hat zusätzliche :minutes Min. (:amount €) für :service_type angefragt. Genehmige oder lehne die Anfrage in der App ab.',
            'part' => 'Die Fachkraft hat :description (:amount €) für :service_type angefragt. Genehmige oder lehne die Anfrage in der App ab.',
        ],
        'approved' => [
            'title' => 'Anfrage genehmigt',
            'description' => 'Der Kunde hat deine Anfrage genehmigt: ',
        ],
        'rejected' => [
            'title' => 'Anfrage abgelehnt',
            'description' => 'Der Kunde hat deine Anfrage abgelehnt: ',
            'reason' => 'Grund: :reason',
        ],
        'chargeFailed' => [
            'vendor' => [
                'title' => 'Extra nicht abgerechnet',
                'description' => 'Der genehmigte Zusatzbetrag konnte nicht abgebucht werden — dieser Betrag wird nicht ausgezahlt: ',
            ],
            'customer' => [
                'title' => 'Zusatzbetrag nicht abgebucht',
                'description' => 'Wir konnten den von dir genehmigten Zusatzbetrag nicht abbuchen. Prüfe deine Zahlungsmethode: ',
            ],
        ],
    ],
    'serviceCanceledByVendorReopened' => [
        'title' => 'Die Fachkraft hat storniert — wir suchen bereits eine andere',
        'description' => 'Wir suchen bereits eine andere Fachkraft für deinen Service :service_type. Dein Betrag wurde zurückerstattet.',
    ],
    'serviceCanceledByVendor' => [
        'title' => 'Auftrag storniert',
        'description' => ':vendor_name hat deinen Auftrag für :service_type storniert',
    ],
    'documents' => [
        'accept' => [
            'title' => 'Dokument bestätigt: :type',
            'description' => 'Dein Dokument wurde erfolgreich bestätigt.',
        ],
        'deny' => [
            'title' => 'Dokument abgelehnt: :type',
            'description' => 'Dein Dokument wurde abgelehnt.',
        ],
        'expiring' => [
            'title' => ':document läuft in :days Tagen ab',
            'description' => 'Erneuere dein Dokument jetzt, damit du weiter Anfragen erhältst. Tippe hier, um das neue Dokument zu senden.',
        ],
        'expiring_last_call' => [
            'title' => 'Letzte Erinnerung: :document läuft morgen ab',
            'description' => 'Ab übermorgen kannst du keine Aufträge mehr annehmen. Sende jetzt das neue Dokument.',
        ],
    ],
    'phoneNumberValidation' => 'Piquet: Dein Bestätigungscode ist :code',
    'profileCompletion' => [
        'title' => 'Vervollständige dein Profil',
        'description' => 'Vervollständige dein Profil, um alle Funktionen der App zu nutzen.',
    ],
    'mbway' => [
        'paymentRefused' => [
            'title' => 'Zahlung abgelehnt',
            'description' => 'Die Zahlung wurde abgelehnt. Prüfe die Angaben und versuche es erneut.',
        ],
        'paymentSuccess' => [
            'title' => 'Zahlung erfolgreich',
            'description' => 'Die Zahlung war erfolgreich und dein Auftrag für :type wurde erstellt. Danke, dass du unseren Service nutzt.',
        ],
        'paymentExpired' => [
            'title' => 'Zahlung abgelaufen',
            'description' => 'Du hast die MB Way-Zahlung nicht rechtzeitig bestätigt, und die Anfrage wurde storniert: ',
        ],
    ],
    'onlineSemLocalizacao' => [
        'title' => 'Du bist online, aber ohne Standort',
        'description' => 'Wir haben deinen Standort seit über einer Stunde nicht erhalten, daher bekommst du keine Sofortaufträge. Öffne die App, um sie wieder zu erhalten.',
    ],
    'onlineExpirou' => [
        'title' => 'Du bist jetzt offline',
        'description' => 'Wir haben deinen Standort seit :dias Tagen nicht erhalten und senden dir daher keine Aufträge mehr. Öffne die App und geh online, wenn du wieder Aufträge erhalten möchtest.',
    ],
];
