<?php

return [
    'paymentSent' => [
        'title' => 'Pago enviado',
        'description' => 'Pago enviado por un importe de :value€',
    ],
    'mail' => [
        'problemReportedOps' => [
            'subject' => 'Problema informado — servicio #:service_id',
            'line1' => 'El servicio #:service_id (:service_type) tiene un problema informado por :reported_by: :reason.',
            'line2' => 'Cliente: :customer_name (:customer_phone). Profesional: :vendor_name.',
            'line3' => 'El cierre automático está parado hasta que alguien decida: cerrar y pagar, reembolsar o enviar otro profesional.',
            'by_customer' => 'el cliente',
            'by_vendor' => 'el profesional',
            'reasons' => [
                'not_done' => 'el trabajo no se hizo',
                'poor_quality' => 'trabajo mal hecho',
                'damage' => 'daños en casa',
                'price' => 'un problema con el precio',
                'no_show' => 'el profesional no apareció',
                'customer_absent' => 'el cliente no estaba',
                'other' => 'otro motivo',
            ],
        ],
        'paymentSent' => [
            'subject' => 'Pago enviado',
            'greeting' => 'Hola',
            'line1' => 'Te informamos de que el pago de <span style="color: #FABB5A;">:amount€</span> se ha enviado al IBAN: <span style="color: #FABB5A;">:iban</span> el día <span style="color: #FABB5A">:date</span>.',
            'line2' => 'El importe debería reflejarse en tu cuenta en los próximos días.',
            'line3' => 'Si tienes alguna duda o necesitas más información, no dudes en contactarnos.',
            'line4' => 'Gracias por tu colaboración continua. Un saludo,',
        ],
        'confirmAccount' => [
            'subject' => 'Confirmación de cuenta',
            'greetings' => 'Hola',
            'line1' => 'Gracias por registrarte. Haz clic en el botón de abajo para confirmar tu correo electrónico.',
            'line2' => 'Si no has creado ninguna cuenta, ignora este correo',
            'line3' => 'Un saludo, el equipo',
            'button' => 'Confirmar la cuenta',
            'copyLink' => 'Si el botón no funciona, copia y pega este enlace en tu navegador:',
        ],
        'passwordReset' => [
            'subject' => 'Restablecer la contraseña',
            'greetings' => 'Hola',
            'line1' => 'Recibes este correo porque hemos recibido una solicitud para restablecer la contraseña de tu cuenta.',
            'action' => 'Restablecer la contraseña',
            'line2' => 'Si no has pedido restablecer la contraseña, no tienes que hacer nada más.',
            'salutation' => 'Un saludo, el equipo',
        ],
        'twoFactorCode' => [
            'subject' => 'Tu código de acceso al backoffice',
            'greetings' => 'Hola',
            'line1' => 'Usa este código para terminar de iniciar sesión en el backoffice:',
            'line2' => 'Este código caduca en :minutes minutos.',
            'line3' => 'Si no has intentado iniciar sesión, ignora este correo — tu cuenta sigue segura.',
            'salutation' => 'Un saludo, el equipo',
        ],
        'noShowOps' => [
            'subject' => 'Posible incomparecencia — servicio #:service_id',
            'greetings' => 'Hola',
            'line1' => 'El servicio #:service_id (:service_type) estaba reservado para las :time y el profesional todavía no lo ha iniciado.',
            'line2' => 'Profesional: :vendor_name. Cliente: :customer_name (:customer_phone).',
            'line3' => 'Conviene contactar con el profesional y, si hace falta, reasignar el servicio o reembolsar al cliente.',
            'salutation' => 'Un saludo, el equipo',
        ],
        'userRegistered' => [
            'line1' => '¡Bienvenido a nuestra aplicación!',
            'line2' => 'Haz clic en el botón de abajo para confirmar tu dirección de correo electrónico.',
            'action' => 'Confirmar correo',
            'line3' => '¡Gracias por registrarte!',
        ],
        'documents' => [
            'accept' => [
                'greetings' => 'Hola ',
                'line1' => 'Nos alegra informarte de que tu documento se ha validado correctamente.',
                'line2' => 'Gracias por usar nuestra plataforma. Si tienes alguna duda, no dudes en escribir a nuestro equipo de soporte.',
                'salutation' => 'Un saludo, el equipo',
            ],
            'deny' => [
                'greetings' => 'Hola ',
                'line1' => 'Lamentamos informarte de que tu documento ha sido rechazado después de la revisión.',
                'line2' => 'Asegúrate de que toda la información necesaria es correcta y está completa antes de volver a enviarlo.',
                'line3' => 'Si tienes alguna duda o necesitas más ayuda, no dudes en escribir a nuestro equipo de soporte.',
                'salutation' => 'Un saludo, el equipo',
            ],
        ],
    ],
    'newMessage' => [
        'title' => 'Tienes un mensaje nuevo',
        'description' => 'El cliente te ha enviado un mensaje nuevo sobre el servicio: ',
        'description_vendor' => 'El profesional te ha enviado un mensaje nuevo sobre el servicio: ',
    ],
    'serviceStuck' => [
        'title' => 'Servicio sin terminar',
        'description' => 'El servicio ":service" lleva :hours horas en curso. Termínalo para recibir el pago.',
    ],
    // Detección de incomparecencia (servicio aún sin iniciar después de la hora reservada).
    'noShowPenalty' => [
        'title' => 'Has faltado a un servicio',
        'description' => 'El servicio de :service_type se ha dado como falta. Se han descontado :amount € de tus ganancias — la mitad de lo que ibas a recibir. Si crees que hay un error, habla con soporte.',
    ],
    'noShow' => [
        'vendor' => [
            'title' => '¿Todavía no has salido?',
            'description' => 'Tu servicio de :service_type con :customer_name era a las :time. Si estás en camino, marca "En camino" en la app; si ya no puedes ir, avísanos.',
        ],
        'customer' => [
            'title' => 'Estamos confirmando tu servicio',
            'description' => 'Tu servicio de :service_type estaba reservado para las :time. Lo estamos confirmando con el profesional y te avisamos en breve.',
        ],
        'ops' => [
            'title' => 'Posible incomparecencia — servicio #:service_id',
            'description' => 'El servicio #:service_id (:service_type) reservado para las :time con :vendor_name sigue sin iniciar. Cliente: :customer_name.',
        ],
    ],
    'incompleteProfile' => [
        'greeting' => 'Hola :name,',
        'action' => 'Completar mi perfil',
        'default' => [
            'title' => 'Te falta :steps paso|Te faltan :steps pasos',
            'description' => 'Completa tu perfil para empezar a recibir solicitudes en tu zona.',
        ],
        'with_requests' => [
            'title' => 'Hay solicitudes esperándote',
            'description' => '{1} Esta semana ha habido :requests solicitud en tu zona. Completa tu perfil para poder aceptarla.|[2,*] Esta semana ha habido :requests solicitudes en tu zona. Completa tu perfil para poder aceptarlas.',
        ],
    ],
    'newService' => [
        'title' => 'Nuevo servicio de ',
        'description' => 'Tienes 60 segundos para aceptar',
        'description_schedule' => 'Tienes 20 minutos para aceptar',
    ],
    'matchingInvitation' => [
        'title' => 'Solicitud de ',
        // Dice que el cliente elige. Prometer el trabajo aquí es lo que hace que el
        // profesional se sienta engañado cuando lo pierde — y deje de responder.
        'description' => 'Dinos si tienes disponibilidad. El cliente elige entre quienes respondan.',
    ],
    'matchingOutcome' => [
        'lost' => [
            'title' => 'El cliente eligió a otro profesional',
            'description' => 'Esta solicitud fue para otra persona. Gracias por responder.',
        ],
        'closed' => [
            'title' => 'La solicitud se canceló',
            'description' => 'El cliente no completó el pago a tiempo. Ya puedes aceptar otras solicitudes.',
        ],
    ],
    'customRequest' => [
        'label' => 'tu solicitud personalizada',
    ],
    'customRequestDispatched' => [
        'title' => 'Tu solicitud ya está con los profesionales',
        // No promete ningún profesional, a propósito: aquí todavía no se sabe
        // si alguno va a aceptar. Lo que se dice es que una persona ha cogido la
        // solicitud — que es exactamente la información que faltaba.
        'description' => 'Hemos visto lo que has escrito y ya lo hemos enviado. Te avisamos en cuanto haya alguien disponible.',
    ],
    'matchingCandidatesReady' => [
        'title' => 'Ya hay quien puede ir',
        // Dice lo que falta HACER, y no solo lo que ha pasado: quien recibe esto
        // tiene un reloj en marcha y necesita saber que la decisión es suya.
        'description' => 'Profesionales disponibles para :service_type. Elige a quien prefieras.',
    ],
    'serviceWon' => [
        'title' => 'El trabajo es tuyo',
        'description' => 'El cliente te ha elegido para :service_type y ya ha pagado. Puedes ponerte en camino.',
    ],
    'matchingFailed' => [
        'title' => 'Nadie disponible ahora mismo',
        'description' => 'No hemos encontrado profesionales para :service_type. Inténtalo otra vez o reserva para otra hora.',
    ],
    'serviceTimedOut' => [
        'title' => 'Solicitud sin respuesta',
        'description' => 'Nadie ha respondido a tu solicitud de :service_type a tiempo. Se ha cancelado — puedes intentarlo otra vez.',
        'description_scheduled' => 'Tu reserva de :service_type no se ha confirmado a tiempo y se ha cancelado. Puedes reservar otra hora.',
    ],
    'scheduledService' => [
        'title' => 'Nuevo servicio reservado para :when',
        'description' => 'de :service_type',
        'when' => [
            'tomorrow' => 'mañana',
            'date' => 'el día :day',
        ],
    ],
    'canceledService' => [
        'title' => 'Servicio cancelado',
        'description' => 'El cliente ha cancelado la solicitud de servicio del tipo: ',
    ],
    'acceptedService' => [
        'title' => 'Servicio aceptado',
        'description' => 'El profesional ha aceptado tu propuesta para el servicio del tipo: ',
    ],
    'conviteRecompensa' => [
        'title' => 'Has ganado :valor € 🎉',
        'description' => ':amigo hizo su primer servicio en Piquet. Tienes :valor € en tu Cartera, válidos :meses meses.',
    ],
    'serviceAutoClosed' => [
        'title' => 'Servicio cerrado',
        'description' => 'Pasaron :hours horas sin ningún problema informado, así que cerramos tu servicio de :service_type. Cuéntanos qué tal fue.',
    ],
    'problemReportedVendor' => [
        'title' => 'El cliente informó de un problema',
        'description' => 'El cliente informó de un problema en el servicio de :service_type. Piquet lo revisará; el pago queda en espera hasta entonces.',
    ],
    'vendorCantFindCustomer' => [
        'title' => 'El profesional no te encuentra',
        'description' => ':vendor_name está en la dirección del servicio y no te encuentra. Llámale o responde en el chat.',
    ],
    'finishedService' => [
        'title' => 'El profesional terminó el servicio',
        'description' => 'Tu servicio de :service_type está terminado. Si algo no está bien, informa de un problema en las próximas :hours horas; después cerramos el servicio automáticamente.',
    ],
    'vendorArrivedService' => [
        'title' => 'El profesional ha llegado',
        'description' => 'ha llegado al destino',
    ],
    'vendorOnTheWay' => [
        'title' => 'Profesional en camino',
        'description' => 'está en camino a tu dirección',
    ],
    'confirmRecurringSchedule' => [
        'title' => 'Confirma tu próximo servicio',
        'description' => ':service_type el día :day a las :time — confirma y paga para asegurar la hora.',
    ],
    'recurringScheduleReleased' => [
        'title' => 'Reserva liberada',
        'description' => ':service_type del día :day no se ha confirmado a tiempo y la hora ha quedado libre. Puedes reservar de nuevo cuando quieras.',
    ],
    'scheduleAttendanceReminder' => [
        'title' => 'Servicio reservado para dentro de 3 días',
        'description' => ':service_type el día :day a las :time — confirma que vas para que el cliente sepa que está todo listo.',
    ],
    'scheduleReminder' => [
        'customer' => [
            'title' => 'Recordatorio de servicio',
            'description' => ':vendor_name llegará dentro de 1 hora para tu servicio de :service_type',
        ],
        'vendor' => [
            'title' => 'Recordatorio de servicio',
            'description' => 'Tienes un servicio de :service_type con :customer_name dentro de 1 hora',
        ],
    ],
    'scheduleCanceled' => [
        'customer' => [
            'title' => 'Reserva cancelada',
            'description' => ':vendor_name ha cancelado la reserva del servicio de :service_type',
        ],
        'vendor' => [
            'title' => 'Reserva cancelada',
            'description' => ':customer_name ha cancelado la reserva del servicio de :service_type',
        ],
    ],
    'serviceExtra' => [
        'item' => [
            'time' => '+:minutes min (:amount€)',
            'part' => ':description (:amount€)',
        ],
        'requested' => [
            'title' => 'Solicitud del profesional',
            'time' => 'El profesional ha pedido :minutes min más (:amount€) en el servicio de :service_type. Apruébalo o recházalo en la app.',
            'part' => 'El profesional ha pedido :description (:amount€) en el servicio de :service_type. Apruébalo o recházalo en la app.',
        ],
        'approved' => [
            'title' => 'Solicitud aprobada',
            'description' => 'El cliente ha aprobado tu solicitud: ',
        ],
        'rejected' => [
            'title' => 'Solicitud rechazada',
            'description' => 'El cliente ha rechazado tu solicitud: ',
            'reason' => 'Motivo: :reason',
        ],
        'chargeFailed' => [
            'vendor' => [
                'title' => 'Extra no cobrado',
                'description' => 'No se ha podido cobrar el extra aprobado — este importe no se te va a pagar: ',
            ],
            'customer' => [
                'title' => 'Fallo al cobrar el extra',
                'description' => 'No hemos podido cobrar el extra que has aprobado. Revisa tu método de pago: ',
            ],
        ],
    ],
    'serviceCanceledByVendorReopened' => [
        'title' => 'El profesional canceló — ya buscamos otro',
        'description' => 'Ya estamos buscando otro profesional para tu servicio de :service_type. Lo que pagaste se ha devuelto.',
    ],
    'serviceCanceledByVendor' => [
        'title' => 'Servicio cancelado',
        'description' => ':vendor_name ha cancelado tu servicio de :service_type',
    ],
        // O título é uma frase COMPLETA, com o nome do documento depois dos
        // dois pontos. Antes era só "validado" e o código colava-lhe o nome à
        // frente — "{nome} validado" — o que só concorda com nomes masculinos:
        // "Declaração de Início de Atividade validado". O mesmo problema que o
        // comentário do bloco 'expiring' aqui abaixo já assinalava.
    'documents' => [
        'accept' => [
            'title' => 'Documento validado: :type',
            'description' => 'Tu documento se ha validado correctamente.',
        ],
        'deny' => [
            'title' => 'Documento rechazado: :type',
            'description' => 'Tu documento ha sido rechazado.',
        ],
        // Avisos de caducidad (30/15/7/3 días). Solo el tipo de documento y el plazo — nunca datos del documento.
        // Sin artículo antes de :document: el nombre del documento tanto puede ser masculino
        // ("el Certificado de Antecedentes Penales") como femenino ("la Declaración de Inicio de Actividad").
        'expiring' => [
            'title' => ':document caduca en :days días',
            'description' => 'Renueva ya para seguir recibiendo solicitudes. Toca para enviar el documento nuevo.',
        ],
        // Último aviso (1 día): tono más directo.
        'expiring_last_call' => [
            'title' => 'Último aviso: :document caduca mañana',
            'description' => 'A partir de pasado mañana no podrás aceptar servicios. Envía ya el documento nuevo.',
        ],
    ],
    'phoneNumberValidation' => 'Piquet: Tu código de validación es :code',
    'profileCompletion' => [
        'title' => 'Completa tu perfil',
        'description' => 'Completa tu perfil para poder usar todas las funciones de la aplicación.',
    ],
    'mbway' => [
        'paymentRefused' => [
            'title' => 'Pago rechazado',
            'description' => 'El pago ha sido rechazado. Revisa los datos e inténtalo otra vez.',
        ],
        'paymentSuccess' => [
            'title' => 'Pago realizado',
            'description' => 'El pago se ha realizado y el servicio :type ya está creado. Gracias por usar nuestro servicio.',
        ],
        'paymentExpired' => [
            'title' => 'Pago caducado',
            'description' => 'No has confirmado el pago con MB Way a tiempo y la solicitud se ha cancelado: ',
        ],
    ],
    'onlineSemLocalizacao' => [
        'title' => 'Estás en línea, pero sin ubicación',
        'description' => 'No recibimos tu ubicación desde hace más de una hora, así que no te llegan pedidos para ahora. Abre la app para volver a recibirlos.',
    ],
    'onlineExpirou' => [
        'title' => 'Ahora estás desconectado',
        'description' => 'Hace :dias días que no recibimos tu ubicación, así que dejamos de enviarte pedidos. Abre la app y conéctate cuando quieras volver a recibirlos.',
    ],
];
