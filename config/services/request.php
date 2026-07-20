<?php

return [
    'min_vendors' => 3,
    /**
     * Time in minutes
     */
    'location_update_threshold' => 60,
    'mock_location' => env('MOCK_LOCATION', false),
    /**
     * Time in seconds for instant services
     */
    'time_to_accept' => 60,
    /**
     * Time in seconds for scheduled services (20 minutes)
     */
    'time_accept_scheduled' => 1200,
    /**
     * Distance to search a vendors
     */
    'new_service_search_distance' => 50000,
    'mbway_payment_check_timeout' => 10,
    /**
     * Safety margin (in minutes) to block after a scheduled service is accepted.
     */
    'schedule_safety_margin_minutes' => 60,
];
