<?php

return [
    'refused' => [
        'vendor' => 'O profissional recusou o serviço.',
    ],
    'refunds' => [
        'refused' => 'Devolução do valor do serviço.',
    ],
    'accepted' => [
        'admin_description' => 'Pagamento da fee do serviço',
        'description' => 'Serviço :service_name #:date',
    ],
    'cancel' => [
        'fee' => 'Taxa de cancelamento',
        'description' => 'O cliente cancelou o serviço antes de ser aceite.',
        'charged' => 'O cliente cancelou depois de o técnico estar a caminho ou no local — cobrança de 100%.',
    ],
    'mbway' => [
        'canceled' => 'O cliente cancelou antes de o pagamento MBWay ser confirmado.',
        'refused' => 'O cliente recusou o pagamento MBWay na app do banco.',
        'expired' => 'O pagamento MBWay não foi confirmado dentro do tempo limite.',
    ],
    'transactions_type' => [
        'refund' => 'Devolução',
        'service' => 'Serviço',
    ],
];
