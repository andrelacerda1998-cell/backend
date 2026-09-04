<?php

namespace App\Notifications\Vendor\Documents;

use App\Models\Vendor\VendorDocuments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;
use NotificationChannels\Expo\ExpoMessage;

class DenyNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable;

    public function __construct(protected VendorDocuments $document) {}

    /**
     * Não respeita notification_preferences por opção: é operacionalmente crítico.
     * Ver App\Notifications\Concerns\RespectsVendorPreference.
     */
    public function via($notifiable): array
    {
        return ['mail', 'expo'];
    }

    public function toMail($notifiable): MailMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        return (new MailMessage)
            ->greeting(__('notifications.mail.documents.deny.greetings', [], $language).$notifiable->name.',')
            ->line(__('notifications.mail.documents.deny.line1', [], $language))
            ->line(__('notifications.mail.documents.deny.line2', [], $language))
            ->line(__('notifications.mail.documents.deny.line3', [], $language))
            ->salutation(new HtmlString(__('notifications.mail.documents.deny.salutation', [], $language)));
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        return ExpoMessage::create("{$this->document->type->getTranslation('name', $language)} ".__('notifications.documents.deny.title', [], $language))
            ->body(__('notifications.documents.deny.description', ['type' => $this->document->type->getTranslation('name', $language)], $language))
            ->priority('high')
            ->playSound();
    }
}
