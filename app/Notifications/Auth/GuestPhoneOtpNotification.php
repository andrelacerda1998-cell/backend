<?php

namespace App\Notifications\Auth;

use Illuminate\Notifications\Notification;
use NotificationChannels\Twilio\TwilioChannel;
use NotificationChannels\Twilio\TwilioSmsMessage;

class GuestPhoneOtpNotification extends Notification
{
    public function __construct(private readonly string $code) {}

    public function via($notifiable): array
    {
        return [TwilioChannel::class];
    }

    public function toTwilio($notifiable): TwilioSmsMessage
    {
        $locale = app()->getLocale() ?? config('app.fallback_locale');

        return (new TwilioSmsMessage)
            ->content(__('notifications.phoneNumberValidation', ['code' => $this->code], $locale));
    }
}
