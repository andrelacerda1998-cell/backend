<?php

return [
    'auth' => [
        'email_already_verified' => 'Este correo electrónico ya está confirmado.',
    ],
    'payment_methods' => [
        'credit_card_invalid_data' => 'La clave de cifrado no es válida.',
        // Separada de la clave de cifrado: una es un problema de la tarjeta que
        // ha escrito el cliente, la otra es del cifrado y él no puede resolverla.
        'card_data_invalid' => 'Los datos de la tarjeta no son válidos. Comprueba el número, la fecha de caducidad y el código.',
        'session_expired' => 'Tu sesión ha caducado. Inicia sesión otra vez para guardar la tarjeta.',
        'disabled' => 'Este método de pago no está disponible en este momento.',
    ],
    'services' => [
        'service_not_found' => 'Servicio no encontrado.',
        'verify_phone_to_request' => 'Verifica tu número de móvil para pedir un servicio. Es así como el profesional contacta contigo cuando llega.',
        'schedule_outside_payment_window' => 'Ya no es posible pagar esta reserva: la fecha está demasiado lejos. Reserva de nuevo para una fecha más cercana.',
        'customer_cannot_request_service' => 'El cliente no puede solicitar un servicio.',
        // Dichas al CLIENTE, en segunda persona. Las equivalentes de backoffice
        // están en backoffice/customer.infolist.eligibility y van en tercera.
        'cannot_request' => [
            'unverified_phone' => 'Confirma tu número de móvil para pedir un servicio.',
            'no_main_address' => 'Elige la dirección donde quieres el servicio.',
            'open_service' => 'Ya tienes un servicio en curso (:services). Termínalo o cancélalo para pedir otro.',
        ],
        'customer_dont_have_balance' => 'El cliente no tiene saldo suficiente.',
        'customer_dont_have_main_address' => 'El cliente no tiene una dirección principal.',
        'service_already_canceled' => 'El servicio ya ha sido cancelado.',
        'service_not_possible_to_cancel' => 'El servicio no se puede cancelar.',
        'payment_already_confirmed' => 'El pago ya se ha confirmado — la solicitud sigue adelante.',
    ],
    'customer' => [
        'only_customers_allowed' => 'Solo los clientes pueden acceder a este endpoint.',
        'code_already_sent_recently' => 'El código ya se ha enviado hace poco.',
    ],
    'user' => [
        'wrong_application' => 'Acceso no válido: estás intentando entrar en una aplicación que no es para ti. Comprueba tu acceso.',
        'wrong_credentials' => 'Credenciales no válidas.',
    ],
    'vendor' => [
        'phone_permissions_off' => 'Para estar en línea necesitas la ubicación y las notificaciones activadas. Sin ellas no recibes avisos de pedidos y el cliente no te ve en camino.',
        'service' => [
            'service_is_not_pending' => 'El servicio no está pendiente.',
            'service_is_not_accepted' => 'El servicio no ha sido aceptado.',
        ],
        'vendor_cannot_accept_service' => 'El profesional no puede aceptar el servicio.',
        'has_service_open' => 'El profesional tiene un servicio abierto.',
        'already_has_device_connected' => 'El profesional ya tiene un dispositivo conectado.',
        'vendor_cannot_invalid_workspace' => 'El workspace de Invoice express no es válido',
        'vendor_wrong_credentials' => 'Credenciales de la AT (Autoridade Tributária, la agencia tributaria portuguesa) no válidas.',
        'cantDeleteAccountWithBalance' => 'No puedes borrar la cuenta con saldo.',
        'cantDeleteAccountWithActiveServices' => 'No puedes borrar la cuenta con servicios activos.',
        'account_not_validated' => 'Tu cuenta todavía no ha sido validada.',
        'account_workspace_required' => 'Hace falta un workspace para la cuenta.',
        'payment_not_complete' => 'El pago todavía no se ha completado',
        'payment_refused' => 'Pago rechazado',
        'at_Account_need_attention' => 'Revisa tus credenciales AT en Piquet.',
    ],
    'common' => [
        'wrong_app_version' => 'Versión de la app incorrecta.',
    ],
];
