<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{__('notifications.mail.confirmAccount.subject', locale: $lang)}}</title>
    <style>
        @media only screen and (max-width: 600px) {
            .container {
                width: 100% !important;
                max-width: 100% !important;
                padding: 10px !important;
            }
            .content {
                padding: 10px !important;
            }
            .button-primary {
                font-size: 16px !important;
                padding: 12px 24px !important;
            }
            h3, h4 {
                font-size: 18px !important;
            }
        }
    </style>
</head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #000000;">
<table role="presentation" cellspacing="0" cellpadding="0" width="100%" style="border-spacing: 0; background-color: #000000;">
    <tr>
        <td align="center">
            <table role="presentation" cellspacing="0" cellpadding="0" width="100%" class="container" style="max-width: 600px; margin: 0 auto; background-color: #000000;">
                <tr>
                    <td class="content" style="padding: 20px; text-align: center;">
                        <img src="{{asset('images/Logo.png')}}" alt="logo" style="width: 150px; max-width: 100%; margin-top: 20px;" />
                        <h3 style="color: #ffffff; margin: 20px 0 10px;">{{__('notifications.mail.confirmAccount.greetings', locale: $lang)}},</h3>
                        <h4 style="color: #ffffff; font-size: 16px; margin: 10px 0;">{!! __('notifications.mail.confirmAccount.line1',locale:  $lang) !!}.</h4>
                        <a style="box-sizing: border-box; font-family: inherit; border-radius: 4px; color: #000000; display: inline-block; text-decoration: none; background-color: #FABB5A; border: none; padding: 14px 32px; font-size: 18px; margin: 20px 0; font-weight: bold;" rel="noopener noreferrer" class="button button-primary" href="{{$url}}" target="_blank">
                            {!! __('notifications.mail.confirmAccount.button', [], $lang) !!}
                        </a>
                        <h4 style="color: #ffffff; font-size: 16px; margin: 20px 0 10px;">{!! __('notifications.mail.confirmAccount.line2', [], $lang) !!}</h4>
                        <h4 style="color: #ffffff; font-size: 14px; margin: 10px 0;">
                            {!! __('notifications.mail.confirmAccount.line3', [], $lang) !!}
                            <span style="color: #FABB5A; font-size: 16px;">Piquet</span>.
                        </h4>
                        <div style="margin-top: 32px; color: #ffffff; font-size: 12px; word-break: break-all;">
                            <p>{!! __('notifications.mail.confirmAccount.copyLink', [], $lang) !!}</p>
                            <a href="{{$url}}" target="_blank" style="color: #ffffff; text-decoration: underline; word-break: break-all;">{{$url}}</a>
                        </div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
