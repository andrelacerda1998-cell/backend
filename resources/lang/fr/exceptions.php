<?php

return [
    'auth' => [
        'email_already_verified' => 'Cet e-mail est déjà confirmé.',
    ],
    'payment_methods' => [
        'credit_card_invalid_data' => 'La clé de chiffrement n\'est pas valide.',
        // Séparée de la clé de chiffrement : l'une vient de la carte que le
        // client a saisie, l'autre du chiffrement et il n'a aucun moyen de la
        // corriger.
        'card_data_invalid' => 'Les données de la carte ne sont pas valides. Vérifie le numéro, la date d\'expiration et le code.',
        'session_expired' => 'Ta session a expiré. Connecte-toi à nouveau pour enregistrer la carte.',
        'disabled' => 'Ce moyen de paiement n\'est pas disponible pour le moment.',
    ],
    'services' => [
        'service_not_found' => 'Intervention introuvable.',
        'verify_phone_to_request' => 'Vérifie ton numéro de téléphone pour demander une intervention. C\'est par là que le pro te contacte à son arrivée.',
        'customer_cannot_request_service' => 'Le client ne peut pas demander d\'intervention.',
        // Dites au CLIENT, à la deuxième personne. Les équivalentes du
        // backoffice sont dans backoffice/customer.infolist.eligibility et
        // restent à la troisième.
        'cannot_request' => [
            'unverified_phone' => 'Confirme ton numéro de téléphone pour demander une intervention.',
            'no_main_address' => 'Choisis l\'adresse où tu veux l\'intervention.',
            'open_service' => 'Tu as déjà une intervention en cours (:services). Termine-la ou annule-la pour en demander une autre.',
        ],
        'customer_dont_have_balance' => 'Le client n\'a pas assez de solde.',
        'customer_dont_have_main_address' => 'Le client n\'a pas d\'adresse principale.',
        'service_already_canceled' => 'L\'intervention a déjà été annulée.',
        'service_not_possible_to_cancel' => 'L\'intervention ne peut pas être annulée.',
        'payment_already_confirmed' => 'Le paiement a déjà été confirmé — ta demande suit son cours.',
    ],
    'customer' => [
        'only_customers_allowed' => 'Seuls les clients peuvent accéder à cet endpoint.',
        'code_already_sent_recently' => 'Un code a déjà été envoyé récemment.',
    ],
    'user' => [
        'wrong_application' => 'Accès invalide : tu essaies d\'accéder à une application qui ne t\'est pas destinée. Vérifie ton accès.',
        'wrong_credentials' => 'Identifiants invalides.',
    ],
    'vendor' => [
        'phone_permissions_off' => 'Pour être en ligne, vous devez activer la localisation et les notifications. Sans elles, vous n\'êtes pas averti des demandes et le client ne vous voit pas en route.',
        'service' => [
            'service_is_not_pending' => 'L\'intervention n\'est pas en attente.',
            'service_is_not_accepted' => 'L\'intervention n\'a pas été acceptée.',
        ],
        'vendor_cannot_accept_service' => 'Le pro ne peut pas accepter l\'intervention.',
        'has_service_open' => 'Le pro a une intervention en cours.',
        'already_has_device_connected' => 'Le pro a déjà un appareil connecté.',
        'vendor_cannot_invalid_workspace' => 'Espace de travail Invoice express invalide',
        'vendor_wrong_credentials' => 'Identifiants AT invalides.',
        'cantDeleteAccountWithBalance' => 'Tu ne peux pas supprimer ton compte tant qu\'il reste du solde.',
        'cantDeleteAccountWithActiveServices' => 'Tu ne peux pas supprimer ton compte tant que tu as des interventions en cours.',
        'account_not_validated' => 'Ton compte n\'a pas encore été validé.',
        'account_workspace_required' => 'Un espace de travail est nécessaire pour le compte.',
        'payment_not_complete' => 'Le paiement n\'est pas encore terminé',
        'payment_refused' => 'Paiement refusé',
        'at_Account_need_attention' => 'Vérifie tes identifiants AT sur Piquet.',
    ],
    'common' => [
        'wrong_app_version' => 'Version de l\'app incorrecte.',
    ],
];
