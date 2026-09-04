<?php

return [
    'requests' => [
        'today' => 'Requests today',
        'this_month' => 'Requests this month',
        'pending_now' => 'Pending now',
        'pending_now_description' => 'Paid and waiting for a vendor',
        'upcoming_scheduled' => 'Upcoming scheduled',
        'upcoming_scheduled_description' => 'Scheduled from today onwards',
        'completion_rate' => 'Completion rate (30 days)',
        'completion_rate_description' => ':closed completed · :lost canceled/refused',
    ],
    'financial' => [
        'volume' => 'Paid volume (month)',
        'commission' => 'Platform commission (month)',
        'vendor_payout' => 'Paid to vendors (month)',
        'average_ticket' => 'Average ticket (month)',
    ],
    'trend' => [
        'heading' => 'Service requests per day',
        'dataset' => 'Requests',
        'total' => 'Total (all, incl. cancelled)',
        'filters' => [
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
        ],
    ],
    'status' => [
        'heading' => 'Status distribution (last 30 days)',
    ],
    'comparison' => [
        'increase' => ':value% more than :period',
        'decrease' => ':value% less than :period',
        'equal' => 'Same as :period',
        'no_previous' => 'No data for :period',
        'yesterday' => 'yesterday',
        'previous_month' => 'the previous month',
    ],
];
