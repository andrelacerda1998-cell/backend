<!DOCTYPE html>
<html lang="pt" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{__('notifications.mail.paymentSent.subject', locale: $lang)}}</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        :root {
            color-scheme: light dark;
            supported-color-schemes: light dark;
        }
        body, table, td {
            background-color: #000000 !important;
        }
        @media (prefers-color-scheme: dark) {
            body, table, td {
                background-color: #000000 !important;
            }
        }
    </style>
</head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #000000; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;" bgcolor="#000000">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="width: 100%; border-spacing: 0; background-color: #000000;" bgcolor="#000000">
    <tr>
        <td align="center" style="background-color: #000000; padding: 20px 0;" bgcolor="#000000">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #000000;" bgcolor="#000000">
                <tr>
                    <td style="padding: 20px; text-align: center; background-color: #000000;" bgcolor="#000000">
                        <img src="{{asset('images/Logo.png')}}" alt="logo" width="150" style="width: 150px; margin-top: 20px; display: block; margin-left: auto; margin-right: auto;" />
                        <h3 style="color: #ffffff; margin: 20px 0 10px 0;">{{__('notifications.mail.paymentSent.greeting', locale: $lang)}}, {{$name}},</h3>
                        <h4 style="color: #ffffff; font-size: 16px; margin: 10px 0;">{!! __('notifications.mail.paymentSent.line1', ['amount' => $amount, 'iban' => $iban, 'date' => now()->format('d/m/Y')], $lang) !!}.</h4>
                        <h4 style="color: #ffffff; font-size: 16px; margin: 10px 0;">{!! __('notifications.mail.paymentSent.line2', [], $lang) !!}</h4>
                        <h4 style="color: #ffffff; font-size: 16px; margin: 10px 0;">{!! __('notifications.mail.paymentSent.line3', [], $lang) !!}</h4>
                        <h4 style="color: #ffffff; font-size: 14px; margin: 10px 0;">{!! __('notifications.mail.paymentSent.line4', [], $lang) !!}
                            <span style="color: #FABB5A; font-size: 16px;">Piquet</span>.
                        </h4>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
