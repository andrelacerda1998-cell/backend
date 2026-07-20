<?php
return [
    'requests' => [
        'today' => 'Pedidos hoje',
        'this_month' => 'Pedidos este mês',
        'pending_now' => 'Pendentes agora',
        'pending_now_description' => 'Pagos e à espera de um prestador',
        'upcoming_scheduled' => 'Agendados por vir',
        'upcoming_scheduled_description' => 'Agendados a partir de hoje',
        'completion_rate' => 'Taxa de conclusão (30 dias)',
        'completion_rate_description' => ':closed concluídos · :lost cancelados/recusados',
    ],
    'financial' => [
        'volume' => 'Volume pago (mês)',
        'commission' => 'Comissão da plataforma (mês)',
        'vendor_payout' => 'Pago aos prestadores (mês)',
        'average_ticket' => 'Ticket médio (mês)',
    ],
    'trend' => [
        'heading' => 'Pedidos de serviço por dia',
        'dataset' => 'Pedidos',
        'total' => 'Total (todos, incl. cancelados)',
        'filters' => [
            '7' => 'Últimos 7 dias',
            '30' => 'Últimos 30 dias',
            '90' => 'Últimos 90 dias',
        ],
    ],
    'status' => [
        'heading' => 'Distribuição por estado (últimos 30 dias)',
    ],
    'comparison' => [
        'increase' => ':value% mais do que :period',
        'decrease' => ':value% menos do que :period',
        'equal' => 'Igual a :period',
        'no_previous' => 'Sem dados de :period',
        'yesterday' => 'ontem',
        'previous_month' => 'o mês anterior',
    ],
];
