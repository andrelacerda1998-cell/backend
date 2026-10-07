<?php

return [
    'min_vendors' => 3,
    /**
     * Time in minutes
     */
    'location_update_threshold' => 60,
    /**
     * Horas sem localização ao fim das quais o "Online" expira sozinho
     * (vendors:expirar-online). Ver ExpirarOnlineCommand.
     */
    'online_expira_horas' => (int) env('ONLINE_EXPIRA_HORAS', 72),
    'mock_location' => env('MOCK_LOCATION', false),
    /**
     * Time in seconds for instant services
     */
    // 180s: a app mostra ao cliente um cronómetro de 3 minutos como espera
    // máxima; este valor tem de bater certo com ele, senão o servidor cancela
    // ao minuto 1 com o mostrador ainda em 2:00.
    'time_to_accept' => 180,
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
