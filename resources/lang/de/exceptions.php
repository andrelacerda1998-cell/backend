<?php

return [
    'auth' => [
        'email_already_verified' => 'Diese E-Mail-Adresse ist schon bestätigt.',
    ],
    'payment_methods' => [
        'credit_card_invalid_data' => 'Der Verschlüsselungsschlüssel ist ungültig.',
        'card_data_invalid' => 'Die Kartendaten sind ungültig. Prüfe Nummer, Gültigkeitsdatum und Sicherheitscode.',
        'session_expired' => 'Deine Sitzung ist abgelaufen. Melde dich erneut an, um die Karte zu speichern.',
        'disabled' => 'Diese Zahlungsmethode ist gerade nicht verfügbar.',
    ],
    'services' => [
        'service_not_found' => 'Auftrag nicht gefunden.',
        'verify_phone_to_request' => 'Bestätige deine Handynummer, um einen Auftrag anzufragen. Darüber meldet sich die Fachkraft bei dir, wenn sie ankommt.',
        'customer_cannot_request_service' => 'Der Kunde kann keinen Auftrag anfragen.',
        'cannot_request' => [
            'unverified_phone' => 'Bestätige deine Handynummer, um einen Auftrag anzufragen.',
            'no_main_address' => 'Wähle die Adresse, an der der Auftrag stattfinden soll.',
            'open_service' => 'Du hast schon einen laufenden Auftrag (:services). Beende oder storniere ihn, um einen neuen anzufragen.',
        ],
        'customer_dont_have_balance' => 'Der Kunde hat nicht genug Guthaben.',
        'customer_dont_have_main_address' => 'Der Kunde hat keine Hauptadresse.',
        'service_already_canceled' => 'Der Auftrag wurde schon storniert.',
        'service_not_possible_to_cancel' => 'Der Auftrag kann nicht storniert werden.',
        'payment_already_confirmed' => 'Die Zahlung ist schon bestätigt — die Anfrage läuft weiter.',
    ],
    'customer' => [
        'only_customers_allowed' => 'Nur Kunden haben Zugriff auf diesen Endpoint.',
        'code_already_sent_recently' => 'Der Code wurde vor Kurzem schon gesendet.',
    ],
    'user' => [
        'wrong_application' => 'Ungültiger Zugriff: Du versuchst, eine App zu öffnen, die nicht für dich bestimmt ist. Prüfe deinen Zugang.',
        'wrong_credentials' => 'Ungültige Anmeldedaten.',
    ],
    'vendor' => [
        'phone_permissions_off' => 'Um online zu gehen, müssen Standort und Benachrichtigungen aktiviert sein. Ohne sie werden Sie nicht über Anfragen informiert und der Kunde sieht Sie nicht unterwegs.',
        'service' => [
            'service_is_not_pending' => 'Der Auftrag ist nicht ausstehend.',
            'service_is_not_accepted' => 'Der Auftrag wurde nicht angenommen.',
        ],
        'vendor_cannot_accept_service' => 'Die Fachkraft kann den Auftrag nicht annehmen.',
        'has_service_open' => 'Die Fachkraft hat einen offenen Auftrag.',
        'already_has_device_connected' => 'Die Fachkraft hat schon ein Gerät verbunden.',
        'vendor_cannot_invalid_workspace' => 'Der Workspace von Invoice Express ist ungültig',
        'vendor_wrong_credentials' => 'Ungültige Zugangsdaten für die AT (portugiesische Steuerbehörde).',
        'cantDeleteAccountWithBalance' => 'Du kannst das Konto nicht löschen, solange Guthaben vorhanden ist.',
        'cantDeleteAccountWithActiveServices' => 'Du kannst das Konto nicht löschen, solange Aufträge aktiv sind.',
        'account_not_validated' => 'Dein Konto ist noch nicht freigegeben.',
        'account_workspace_required' => 'Für das Konto ist ein Workspace erforderlich.',
        'payment_not_complete' => 'Zahlung noch nicht abgeschlossen',
        'payment_refused' => 'Zahlung abgelehnt',
        'at_Account_need_attention' => 'Prüfe deine AT-Zugangsdaten bei Piquet.',
    ],
    'common' => [
        'wrong_app_version' => 'Falsche App-Version.',
    ],
];
