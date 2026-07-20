<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Twilio\TwilioChannel;
use NotificationChannels\Twilio\TwilioSmsMessage;

class PhoneNumberValidationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $code)
    {
    }

    public function via($notifiable): array
    {
        return [TwilioChannel::class];
    }

    public function toArray($notifiable): array
    {
        return [];
    }

    public function toTwilio($notifiable): TwilioSmsMessage
    {
        // A linha do código já foi persistida sincronamente em SmsValidationService.
        return (new TwilioSmsMessage())
            ->content(__('notifications.phoneNumberValidation', ['code' => $this->code], $notifiable->preferredLocale()));
    }
}
