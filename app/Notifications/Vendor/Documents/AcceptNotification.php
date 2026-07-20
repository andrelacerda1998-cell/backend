<?php

namespace App\Notifications\Vendor\Documents;

use App\Models\Vendor\VendorDocuments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;
use NotificationChannels\Expo\ExpoMessage;

class AcceptNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected VendorDocuments $document) {}

    public function via($notifiable): array
    {
        return ['mail', 'expo'];
    }

    public function toMail($notifiable): MailMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        return (new MailMessage)
            ->greeting(__('notifications.mail.documents.accept.greetings', [], $language).$notifiable->name.',')
            ->line(__('notifications.mail.documents.accept.line1', [], $language))
            ->line(__('notifications.mail.documents.accept.line2', [], $language))
            ->salutation(new HtmlString(__('notifications.mail.documents.accept.salutation', [], $language)));
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        return ExpoMessage::create("{$this->document->type->getTranslation('name', $language)} ".__('notifications.documents.accept.title', [], $language))
            ->body(__('notifications.documents.accept.description', ['type' => $this->document->type->getTranslation('name', $language)], $language))
            ->priority('high')
            ->playSound();
    }
}
