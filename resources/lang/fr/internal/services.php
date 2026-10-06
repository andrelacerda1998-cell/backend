<?php

return [
    'refused' => [
        'vendor' => "Le pro a refusé l'intervention.",
        'timeout' => "Le délai de réponse du pro a expiré.",
        'vendor_busy' => "Le pro a accepté une autre demande immédiate.",
    ],
    'refunds' => [
        'refused' => "Remboursement de l'intervention.",
    ],
    'accepted' => [
        'admin_description' => "Paiement des frais de l'intervention",
        'description' => 'Intervention :service_name #:date',
    ],
    'cancel' => [
        'fee' => "Frais d'annulation",
        'description' => "Le client a annulé l'intervention avant qu'elle soit acceptée.",
        'charged' => 'Le client a annulé alors que le pro était en route ou sur place — facturé à 100 %.',
        'vendor_no_show' => "Le pro ne s'est pas présenté — intervention annulée et client remboursé.",
    ],
    'mbway' => [
        'canceled' => 'Le client a annulé avant la confirmation du paiement MBWay.',
        'refused' => "Le client a refusé le paiement MBWay dans l'application de sa banque.",
        'expired' => "Le paiement MBWay n'a pas été confirmé dans le délai imparti.",
    ],
    'transactions_type' => [
        'refund' => 'Remboursement',
        'service' => 'Intervention',
    ],
];
