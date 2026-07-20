<?php

return [
    'paymentSent' => [
        'title' => 'Payment sent',
        'description' => 'Payment sent with value :value€',
    ],
    'mail' => [
        'paymentSent' => [
            'subject' => 'Payment sent',
            'greeting' => 'Hello',
            'line1' => 'We inform you that the payment of <span style="color: #FABB5A;">:amount€</span> has been sent to IBAN: <span style="color: #FABB5A;">:iban</span> on <span style="color: #FABB5A">:date</span>.',
            'line2' => 'The amount should be reflected in your account in the coming days.',
            'line3' => 'If you have any questions or need further information, please do not hesitate to contact us.',
            'line4' => 'We appreciate your continued cooperation. Best regards,',
        ],
        'passwordReset' => [
            'subject' => 'Password reset',
            'greetings' => 'Hello',
            'line1' => 'You are receiving this email because we received a password reset request for your account.',
            'action' => 'Reset password',
            'line2' => 'If you did not request a password reset, no further action is required.',
            'salutation' => 'Best regards, The Team',
        ],
        'twoFactorCode' => [
            'subject' => 'Your backoffice access code',
            'greetings' => 'Hello',
            'line1' => 'Use the following code to finish signing in to the backoffice:',
            'line2' => 'This code expires in :minutes minutes.',
            'line3' => 'If you did not try to sign in, please ignore this email — your account is still safe.',
            'salutation' => 'Best regards, The Team',
        ],
        'userRegistered' => [
            'line1' => 'Welcome to our app!',
            'line2' => 'Please click the button below to confirm your email address.',
            'action' => 'Confirm email',
            'line3' => 'Thank you for registering!',
        ],
        'documents' => [
            'accept' => [
                'greetings' => 'Hello ',
                'line1' => 'We are pleased to inform you that your document has been successfully validated.',
                'line2' => 'Thank you for using our platform. If you have any questions, feel free to reach out to our support team.',
                'salutation' => 'Best regards, The Team',
            ],
            'deny' => [
                'greetings' => 'Hello ',
                'line1' => 'We regret to inform you that your document has been denied after the review process.',
                'line2' => 'Please ensure that all required information is correct and complete before submitting again.',
                'line3' => 'If you have any questions or need further assistance, feel free to reach out to our support team.',
                'salutation' => 'Best regards, The Team',
            ],
        ],
        'confirmAccount' => [
            'subject' => 'Confirm your account',
            'greetings' => 'Hello',
            'line1' => 'Thank you for registering! Please click the button below to confirm your email address.',
            'line2' => 'If you did not create an account, please ignore this email.',
            'line3' => 'Best regards, The Team.',
            'button' => 'Confirm your account',
            'copyLink' => 'If the button does not work, copy and paste the following link into your browser:',
        ],
    ],
    'newMessage' => [
        'title' => 'You have a new message',
        'description' => 'The Customer has sent you a new message about the service: ',
        'description_vendor' => 'The Vendor has sent you a new message about the service: ',
    ],
    'newService' => [
        'title' => 'New service: ',
        'description' => 'You have 60 seconds',
        'description_schedule' => 'You have 20 minutes',
    ],
    'scheduledService' => [
        'title' => 'New service scheduled for :when',
        'description' => 'of :service_type',
        'when' => [
            'tomorrow' => 'tomorrow',
            'date' => 'day :day',
        ],
    ],
    'canceledService' => [
        'title' => 'Service canceled',
        'description' => 'The customer canceled the service request of type: ',
    ],
    'acceptedService' => [
        'title' => 'Service accepted',
        'description' => 'The professional accepted your proposal for the service of type: ',
    ],
    'finishedService' => [
        'title' => 'Service finished',
        'description' => 'The professional says to have finished the service of type: ',
    ],
    'vendorArrivedService' => [
        'title' => 'Professional arrived',
        'description' => 'has arrived at your destination',
    ],
    'vendorOnTheWay' => [
        'title' => 'Professional on the way',
        'description' => 'is on the way to your location',
    ],
    'scheduleReminder' => [
        'customer' => [
            'title' => 'Service reminder',
            'description' => ':vendor_name will arrive in 1 hour for your :service_type service',
        ],
        'vendor' => [
            'title' => 'Service reminder',
            'description' => 'You have a :service_type service with :customer_name in 1 hour',
        ],
    ],
    'scheduleCanceled' => [
        'customer' => [
            'title' => 'Schedule canceled',
            'description' => ':vendor_name has canceled the :service_type service appointment',
        ],
        'vendor' => [
            'title' => 'Schedule canceled',
            'description' => ':customer_name has canceled the :service_type service appointment',
        ],
    ],
    'serviceCanceledByVendor' => [
        'title' => 'Service canceled',
        'description' => ':vendor_name has canceled your :service_type service',
    ],
    'documents' => [
        'accept' => [
            'title' => 'validated',
            'description' => 'Your document has been successfully validated.',
        ],
        'deny' => [
            'title' => 'denied',
            'description' => 'Your document has been denied.',
        ],
    ],
    'phoneNumberValidation' => 'Piquet: Your validation code is :code',
    'profileCompletion' => [
        'title' => 'Complete your profile',
        'description' => 'Complete your profile to use all the features of the app.',
    ],
    'mbway' => [
        'paymentRefused' => [
            'title' => 'Payment refused',
            'description' => 'Your payment was refused. Please try again or contact support.',
        ],
        'paymentSuccess' => [
            'title' => 'Payment successful',
            'description' => 'Your payment was successful and the service :type has been created. Thank you for using our service.',
        ],
        'paymentExpired' => [
            'title' => 'Payment expired',
            'description' => 'You did not confirm the MBWay payment in time and the request was cancelled: ',
        ],
    ],
];
