<?php

return [
    'refused' => [
        'vendor' => 'El profesional rechazó el servicio.',
    ],
    'refunds' => [
        'refused' => 'Devolución del importe del servicio.',
    ],
    'accepted' => [
        'admin_description' => 'Pago de la comisión del servicio',
        'description' => 'Servicio :service_name #:date',
    ],
    'cancel' => [
        'fee' => 'Gastos de cancelación',
        'description' => 'El cliente canceló el servicio antes de que fuera aceptado.',
        'charged' => 'El cliente canceló cuando el profesional ya iba de camino o estaba en el lugar — se cobra el 100 %.',
        'vendor_no_show' => 'El profesional no se presentó — servicio cancelado y cliente reembolsado.',
    ],
    'mbway' => [
        'canceled' => 'El cliente canceló antes de que se confirmara el pago MBWay.',
        'refused' => 'El cliente rechazó el pago MBWay en la app de su banco.',
        'expired' => 'El pago MBWay no se confirmó dentro del tiempo límite.',
    ],
    'transactions_type' => [
        'refund' => 'Devolución',
        'service' => 'Servicio',
    ],
];
