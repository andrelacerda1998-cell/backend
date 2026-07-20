<?php

return [
    'paymentSent' => [
        'title' => 'Pagamento enviado',
        'description' => 'Pagamento enviado com o valor de :value€',
    ],
    'mail' => [
        'paymentSent' => [
            'subject' => 'Pagamento enviado',
            'greeting' => 'Olá',
            'line1' => 'Informamos que o pagamento do valor de <span style="color: #FABB5A;">:amount€</span> foi enviado para o IBAN: <span style="color: #FABB5A;">:iban</span> no dia <span style="color: #FABB5A">:date</span>.',
            'line2' => 'O valor deverá ser refletido na tua conta nos próximos dias.',
            'line3' => 'Caso tenhas alguma dúvida ou necessites de mais informações, não hesites em contactar-nos.',
            'line4' => 'Agradecemos pela tua colaboração contínua. Com os melhores cumprimentos,',
        ],
        'confirmAccount' => [
            'subject' => 'Confirmação de conta',
            'greetings' => 'Olá',
            'line1' => 'Obrigado por se increver! Por favor clique no botão abaixo para confirmar o seu  email.',
            'line2' => 'Se não criou nenhuma conta, por favor ignore este e-mail',
            'line3' => 'Com os melhores cumprimentos, A Equipa',
            'button' => 'Confirmar a conta',
            'copyLink' => 'Se o botão não funcionar, copia e cola o seguinte link no teu navegador:',
        ],
        'passwordReset' => [
            'subject' => 'Redefinição de palavra-passe',
            'greetings' => 'Olá',
            'line1' => 'Recebeste este email porque recebemos um pedido de redefinição de palavra-passe para a tua conta.',
            'action' => 'Redefinir palavra-passe',
            'line2' => 'Se não solicitaste a redefinição de palavra-passe, nenhuma ação adicional é necessária.',
            'salutation' => 'Com os melhores cumprimentos, A Equipa',
        ],
        'twoFactorCode' => [
            'subject' => 'O teu código de acesso ao backoffice',
            'greetings' => 'Olá',
            'line1' => 'Usa o seguinte código para concluir o início de sessão no backoffice:',
            'line2' => 'Este código expira em :minutes minutos.',
            'line3' => 'Se não tentaste iniciar sessão, ignora este email — a tua conta continua segura.',
            'salutation' => 'Com os melhores cumprimentos, A Equipa',
        ],
        'userRegistered' => [
            'line1' => 'Bem-vindo à nossa aplicação!',
            'line2' => 'Por favor, clica no botão abaixo para confirmar o teu endereço de email.',
            'action' => 'Confirmar email',
            'line3' => 'Obrigado por te registares!',
        ],
        'documents' => [
            'accept' => [
                'greetings' => 'Olá ',
                'line1' => 'Temos o prazer de informar que o teu documento foi validado com sucesso.',
                'line2' => 'Obrigado por utilizares a nossa plataforma. Se tiveres alguma dúvida, não hesites em entrar em contacto com a nossa equipa de suporte.',
                'salutation' => 'Com os melhores cumprimentos, A Equipa',
            ],
            'deny' => [
                'greetings' => 'Olá ',
                'line1' => 'Lamentamos informar que o teu documento foi recusado após o processo de revisão.',
                'line2' => 'Por favor, assegura-te de que todas as informações necessárias estão corretas e completas antes de submeter novamente.',
                'line3' => 'Se tiveres alguma dúvida ou precisares de mais assistência, não hesites em entrar em contacto com a nossa equipa de suporte.',
                'salutation' => 'Com os melhores cumprimentos, A Equipa',
            ],
        ],
    ],
    'newMessage' => [
        'title' => 'Tens uma nova mensagem',
        'description' => 'O cliente enviou-te uma nova mensagem sobre o serviço: ',
        'description_vendor' => 'O técnico enviou-te uma nova mensagem sobre o serviço: ',
    ],
    'newService' => [
        'title' => 'Novo serviço de ',
        'description' => 'Tens 60 segundos para aceitar',
        'description_schedule' => 'Tens 20 minutos para aceitar',
    ],
    'scheduledService' => [
        'title' => 'Novo serviço agendado para :when',
        'description' => 'de :service_type',
        'when' => [
            'tomorrow' => 'amanhã',
            'date' => 'dia :day',
        ],
    ],
    'canceledService' => [
        'title' => 'Serviço cancelado',
        'description' => 'O cliente cancelou o pedido de serviço do tipo: ',
    ],
    'acceptedService' => [
        'title' => 'Serviço aceite',
        'description' => 'O profissional aceitou a tua proposta para o serviço do tipo: ',
    ],
    'finishedService' => [
        'title' => 'Serviço terminado',
        'description' => 'O profissional diz ter terminado o serviço do tipo: ',
    ],
    'vendorArrivedService' => [
        'title' => 'Profissional chegou',
        'description' => 'chegou ao destino',
    ],
    'vendorOnTheWay' => [
        'title' => 'Profissional a caminho',
        'description' => 'está a caminho da tua morada',
    ],
    'scheduleReminder' => [
        'customer' => [
            'title' => 'Lembrete de serviço',
            'description' => ':vendor_name chegará dentro de 1 hora para o teu serviço de :service_type',
        ],
        'vendor' => [
            'title' => 'Lembrete de serviço',
            'description' => 'Tens um serviço de :service_type com :customer_name dentro de 1 hora',
        ],
    ],
    'scheduleCanceled' => [
        'customer' => [
            'title' => 'Agendamento cancelado',
            'description' => ':vendor_name cancelou o agendamento do serviço de :service_type',
        ],
        'vendor' => [
            'title' => 'Agendamento cancelado',
            'description' => ':customer_name cancelou o agendamento do serviço de :service_type',
        ],
    ],
    'serviceCanceledByVendor' => [
        'title' => 'Serviço cancelado',
        'description' => ':vendor_name cancelou o teu serviço de :service_type',
    ],
    'documents' => [
        'accept' => [
            'title' => 'validado',
            'description' => 'O teu documento foi validado com sucesso.',
        ],
        'deny' => [
            'title' => 'recusado',
            'description' => 'O teu documento foi recusado.',
        ],
    ],
    'phoneNumberValidation' => 'Piquet: Seu código de validação é :code',
    'profileCompletion' => [
        'title' => 'Complete o seu perfil',
        'description' => 'Complete o seu perfil para poder usar todas as funcionalidades da aplicação.',
    ],
    'mbway' => [
        'paymentRefused' => [
            'title' => 'Pagamento recusado',
            'description' => 'O pagamento foi recusado. Por favor, verifique os detalhes e tente novamente.',
        ],
        'paymentSuccess' => [
            'title' => 'Pagamento bem-sucedido',
            'description' => 'O pagamento foi bem-sucedido e o serviço :type foi criado. Obrigado por utilizar o nosso serviço.',
        ],
        'paymentExpired' => [
            'title' => 'Pagamento expirado',
            'description' => 'Não confirmou o pagamento MBWay a tempo e o pedido foi cancelado: ',
        ],
    ],
];
