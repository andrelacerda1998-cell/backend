<?php

return [
    'paymentSent' => [
        'title' => 'Paiement envoyé',
        'description' => 'Paiement de :value € envoyé',
    ],
    'mail' => [
        'paymentSent' => [
            'subject' => 'Paiement envoyé',
            'greeting' => 'Bonjour',
            'line1' => 'Nous t\'informons que le paiement de <span style="color: #FABB5A;">:amount €</span> a été envoyé sur l\'IBAN : <span style="color: #FABB5A;">:iban</span> le <span style="color: #FABB5A">:date</span>.',
            'line2' => 'Le montant devrait apparaître sur ton compte dans les prochains jours.',
            'line3' => 'Si tu as des questions ou besoin de plus d\'informations, n\'hésite pas à nous contacter.',
            'line4' => 'Merci pour ta collaboration continue. Cordialement,',
        ],
        'confirmAccount' => [
            'subject' => 'Confirmation de compte',
            'greetings' => 'Bonjour',
            'line1' => 'Merci de ton inscription ! Clique sur le bouton ci-dessous pour confirmer ton adresse e-mail.',
            'line2' => 'Si tu n\'as pas créé de compte, ignore cet e-mail',
            'line3' => 'Cordialement, l\'équipe',
            'button' => 'Confirmer le compte',
            'copyLink' => 'Si le bouton ne fonctionne pas, copie et colle ce lien dans ton navigateur :',
        ],
        'passwordReset' => [
            'subject' => 'Réinitialisation du mot de passe',
            'greetings' => 'Bonjour',
            'line1' => 'Tu reçois cet e-mail parce que nous avons reçu une demande de réinitialisation du mot de passe de ton compte.',
            'action' => 'Réinitialiser le mot de passe',
            'line2' => 'Si tu n\'as pas demandé cette réinitialisation, aucune action n\'est nécessaire.',
            'salutation' => 'Cordialement, l\'équipe',
        ],
        'twoFactorCode' => [
            'subject' => 'Ton code d\'accès au backoffice',
            'greetings' => 'Bonjour',
            'line1' => 'Utilise ce code pour terminer ta connexion au backoffice :',
            'line2' => 'Ce code expire dans :minutes minutes.',
            'line3' => 'Si tu n\'as pas essayé de te connecter, ignore cet e-mail — ton compte reste en sécurité.',
            'salutation' => 'Cordialement, l\'équipe',
        ],
        'noShowOps' => [
            'subject' => 'Absence possible — intervention #:service_id',
            'greetings' => 'Bonjour',
            'line1' => 'L\'intervention #:service_id (:service_type) était prévue à :time et le professionnel ne l\'a toujours pas commencée.',
            'line2' => 'Professionnel : :vendor_name. Client : :customer_name (:customer_phone).',
            'line3' => 'Il vaut mieux contacter le professionnel et, si besoin, réattribuer l\'intervention ou rembourser le client.',
            'salutation' => 'Cordialement, l\'équipe',
        ],
        'userRegistered' => [
            'line1' => 'Bienvenue sur notre application !',
            'line2' => 'Clique sur le bouton ci-dessous pour confirmer ton adresse e-mail.',
            'action' => 'Confirmer l\'e-mail',
            'line3' => 'Merci de ton inscription !',
        ],
        'documents' => [
            'accept' => [
                'greetings' => 'Bonjour ',
                'line1' => 'Nous avons le plaisir de t\'informer que ton document a bien été validé.',
                'line2' => 'Merci d\'utiliser notre plateforme. Si tu as des questions, n\'hésite pas à contacter notre équipe de support.',
                'salutation' => 'Cordialement, l\'équipe',
            ],
            'deny' => [
                'greetings' => 'Bonjour ',
                'line1' => 'Nous sommes désolés de t\'informer que ton document a été refusé après vérification.',
                'line2' => 'Assure-toi que toutes les informations nécessaires sont correctes et complètes avant de le renvoyer.',
                'line3' => 'Si tu as des questions ou besoin d\'aide, n\'hésite pas à contacter notre équipe de support.',
                'salutation' => 'Cordialement, l\'équipe',
            ],
        ],
    ],
    'newMessage' => [
        'title' => 'Tu as un nouveau message',
        'description' => 'Le client t\'a envoyé un nouveau message sur l\'intervention : ',
        'description_vendor' => 'Le pro t\'a envoyé un nouveau message sur l\'intervention : ',
    ],
    'serviceStuck' => [
        'title' => 'Intervention non terminée',
        'description' => 'L\'intervention ":service" est en cours depuis :hours heures. Termine-la pour être payé.',
    ],
    // Détection d'absence (intervention encore non commencée après l'heure prévue).
    'noShowPenalty' => [
        'title' => 'Tu as manqué une intervention',
        'description' => 'L\'intervention :service_type a été comptée comme absence. :amount € déduits de tes gains — la moitié de ce que tu allais recevoir. En cas d\'erreur, contacte le support.',
    ],
    'noShow' => [
        'vendor' => [
            'title' => 'Tu n\'es pas encore parti ?',
            'description' => 'Ton intervention :service_type avec :customer_name était à :time. Si tu es en route, appuie sur "En route" dans l\'app ; sinon, préviens-nous.',
        ],
        'customer' => [
            'title' => 'Nous confirmons ton intervention',
            'description' => 'Ton intervention :service_type était prévue à :time. Nous vérifions avec le professionnel et nous te recontactons très vite.',
        ],
        'ops' => [
            'title' => 'Absence possible — intervention #:service_id',
            'description' => 'Intervention #:service_id (:service_type) prévue à :time avec :vendor_name toujours pas commencée. Client : :customer_name.',
        ],
    ],
    'incompleteProfile' => [
        'greeting' => 'Bonjour :name,',
        'action' => 'Compléter mon profil',
        'default' => [
            'title' => 'Il te reste :steps étapes',
            'description' => 'Complète ton profil pour commencer à recevoir des demandes dans ta zone.',
        ],
        'with_requests' => [
            'title' => 'Des demandes t\'attendent',
            'description' => ':requests demandes dans ta zone cette semaine. Il te reste :steps étapes pour pouvoir les accepter.',
        ],
    ],
    'newService' => [
        'title' => 'Nouvelle intervention : ',
        'description' => 'Tu as 60 secondes pour accepter',
        'description_schedule' => 'Tu as 20 minutes pour accepter',
    ],
    'matchingInvitation' => [
        'title' => 'Demande : ',
        // Dire que c'est le client qui choisit. Promettre le travail ici, c'est ce
        // qui fait que le pro se sent trompé quand il perd — et arrête de répondre.
        'description' => 'Dis-nous si tu es disponible. Le client choisit parmi ceux qui répondent.',
    ],
    'customRequest' => [
        'label' => 'ta demande personnalisée',
    ],
    'customRequestDispatched' => [
        'title' => 'Ta demande est chez les pros',
        // Ne promet aucun professionnel, volontairement : ici on ne sait pas encore
        // si quelqu'un va accepter. Ce qu'on dit, c'est qu'une personne a pris la
        // demande en main — exactement l'information qui manquait.
        'description' => 'Nous avons lu ta demande et nous l\'avons envoyée. Nous te prévenons dès que quelqu\'un est disponible.',
    ],
    'matchingCandidatesReady' => [
        'title' => 'Quelqu\'un peut venir',
        // Dit ce qu'il reste À FAIRE, et pas seulement ce qui s'est passé : celui qui
        // reçoit ça a une horloge qui tourne et doit savoir que la décision est la sienne.
        'description' => 'Des pros sont disponibles pour :service_type. Choisis celui que tu préfères.',
    ],
    'serviceWon' => [
        'title' => 'Le travail est pour toi',
        'description' => 'Le client t\'a choisi pour :service_type et a déjà payé. Tu peux te mettre en route.',
    ],
    'matchingFailed' => [
        'title' => 'Personne de disponible pour le moment',
        'description' => 'Nous n\'avons pas trouvé de pros pour :service_type. Réessaie ou choisis une autre heure.',
    ],
    'serviceTimedOut' => [
        'title' => 'Demande sans réponse',
        'description' => 'Personne n\'a répondu à ta demande :service_type à temps. Elle a été annulée — tu peux réessayer.',
        'description_scheduled' => 'Ton rendez-vous :service_type n\'a pas été confirmé à temps et il a été annulé. Tu peux choisir une autre heure.',
    ],
    'scheduledService' => [
        'title' => 'Nouvelle intervention prévue pour :when',
        'description' => 'de :service_type',
        'when' => [
            'tomorrow' => 'demain',
            'date' => 'le :day',
        ],
    ],
    'canceledService' => [
        'title' => 'Intervention annulée',
        'description' => 'Le client a annulé la demande d\'intervention du type : ',
    ],
    'acceptedService' => [
        'title' => 'Intervention acceptée',
        'description' => 'Le pro a accepté ta proposition pour l\'intervention du type : ',
    ],
    'finishedService' => [
        'title' => 'Intervention terminée',
        'description' => 'Le pro indique avoir terminé l\'intervention du type : ',
    ],
    'vendorArrivedService' => [
        'title' => 'Le pro est arrivé',
        'description' => 'est arrivé à destination',
    ],
    'vendorOnTheWay' => [
        'title' => 'Le pro est en route',
        'description' => 'est en route vers ton adresse',
    ],
    'confirmRecurringSchedule' => [
        'title' => 'Confirme ta prochaine intervention',
        'description' => ':service_type le :day à :time — confirme et paie pour garder le créneau.',
    ],
    'recurringScheduleReleased' => [
        'title' => 'Rendez-vous libéré',
        'description' => ':service_type du :day n\'a pas été confirmé à temps et le créneau est libre. Tu peux reprendre rendez-vous quand tu veux.',
    ],
    'scheduleAttendanceReminder' => [
        'title' => 'Intervention prévue dans 3 jours',
        'description' => ':service_type le :day à :time — confirme que tu y vas pour que le client sache que tout est prêt.',
    ],
    'scheduleReminder' => [
        'customer' => [
            'title' => 'Rappel d\'intervention',
            'description' => ':vendor_name arrivera dans 1 heure pour ton intervention :service_type',
        ],
        'vendor' => [
            'title' => 'Rappel d\'intervention',
            'description' => 'Tu as une intervention :service_type avec :customer_name dans 1 heure',
        ],
    ],
    'scheduleCanceled' => [
        'customer' => [
            'title' => 'Rendez-vous annulé',
            'description' => ':vendor_name a annulé le rendez-vous de l\'intervention :service_type',
        ],
        'vendor' => [
            'title' => 'Rendez-vous annulé',
            'description' => ':customer_name a annulé le rendez-vous de l\'intervention :service_type',
        ],
    ],
    'serviceExtra' => [
        'item' => [
            'time' => '+:minutes min (:amount €)',
            'part' => ':description (:amount €)',
        ],
        'requested' => [
            'title' => 'Demande du pro',
            'time' => 'Le pro a demandé :minutes min de plus (:amount €) sur l\'intervention :service_type. Approuve ou refuse dans l\'app.',
            'part' => 'Le pro a demandé :description (:amount €) sur l\'intervention :service_type. Approuve ou refuse dans l\'app.',
        ],
        'approved' => [
            'title' => 'Demande approuvée',
            'description' => 'Le client a approuvé ta demande : ',
        ],
        'rejected' => [
            'title' => 'Demande refusée',
            'description' => 'Le client a refusé ta demande : ',
            'reason' => 'Motif : :reason',
        ],
        'chargeFailed' => [
            'vendor' => [
                'title' => 'Extra non facturé',
                'description' => 'Nous n\'avons pas pu facturer l\'extra approuvé — ce montant ne sera pas payé : ',
            ],
            'customer' => [
                'title' => 'Échec du paiement de l\'extra',
                'description' => 'Nous n\'avons pas pu facturer l\'extra que tu as approuvé. Vérifie ton moyen de paiement : ',
            ],
        ],
    ],
    'serviceCanceledByVendor' => [
        'title' => 'Intervention annulée',
        'description' => ':vendor_name a annulé ton intervention :service_type',
    ],
    'documents' => [
        'accept' => [
            'title' => 'validé',
            'description' => 'Ton document a bien été validé.',
        ],
        'deny' => [
            'title' => 'refusé',
            'description' => 'Ton document a été refusé.',
        ],
        // Rappels d'expiration (30/15/7/3 jours). Seulement le type de document et le délai — jamais les données du document.
        // Sans article devant :document : le nom du document peut être masculin
        // (« le Casier judiciaire ») comme féminin (« la Déclaration de début d'activité »).
        'expiring' => [
            'title' => ':document expire dans :days jours',
            'description' => 'Renouvelle maintenant pour continuer à recevoir des demandes. Appuie pour envoyer le nouveau document.',
        ],
        // Dernier rappel (1 jour) : ton plus direct.
        'expiring_last_call' => [
            'title' => 'Dernier avertissement : :document expire demain',
            'description' => 'Après-demain, tu ne pourras plus accepter d\'interventions. Envoie le nouveau document maintenant.',
        ],
    ],
    'phoneNumberValidation' => 'Piquet : ton code de validation est :code',
    'profileCompletion' => [
        'title' => 'Complète ton profil',
        'description' => 'Complète ton profil pour utiliser toutes les fonctionnalités de l\'application.',
    ],
    'mbway' => [
        'paymentRefused' => [
            'title' => 'Paiement refusé',
            'description' => 'Le paiement a été refusé. Vérifie les informations et réessaie.',
        ],
        'paymentSuccess' => [
            'title' => 'Paiement accepté',
            'description' => 'Le paiement a été accepté et l\'intervention :type a été créée. Merci d\'utiliser nos services.',
        ],
        'paymentExpired' => [
            'title' => 'Paiement expiré',
            'description' => 'Tu n\'as pas confirmé le paiement MBWay à temps et la demande a été annulée : ',
        ],
    ],
];
