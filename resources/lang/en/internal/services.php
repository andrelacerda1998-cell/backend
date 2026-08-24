<?php

return [
    'refused' => [
        'vendor' => 'The vendor has refused the job.',
    ],
    'refunds' => [
        'refused' => 'Refund for service.',
    ],
    'accepted' => [
        'admin_description' => 'Service payment fee',
        'description' => 'Service :service_name #:date',
    ],
    'cancel' => [
        'fee' => 'Cancellation fee',
        'description' => 'The customer has cancelled the job request before it could be accepted.',
        'charged' => 'The customer cancelled after the technician was on the way or on site — charged 100%.',
    ],
    'mbway' => [
        'canceled' => 'The customer canceled before the MBWay payment was confirmed.',
        'refused' => 'The customer refused the MBWay payment in their bank app.',
        'expired' => 'The MBWay payment was not confirmed within the time limit.',
    ],
    'transactions_type' => [
        'refund' => 'Refund',
        'service' => 'Service',
    ],
];
