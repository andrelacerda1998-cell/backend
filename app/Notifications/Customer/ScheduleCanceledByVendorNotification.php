<?php

namespace App\Notifications\Customer;

use App\Models\Schedule\Schedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

class ScheduleCanceledByVendorNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable;

    private readonly int $scheduleId;

    /** @var array<string,string> Traduções do nome do tipo de serviço (por locale). */
    private readonly array $serviceTypeNames;

    private readonly string $vendorName;

    public function __construct(Schedule $schedule)
    {
        // Resolvemos todo o conteúdo AGORA (o schedule ainda existe). A notificação é
        // ShouldQueue e o controller apaga o schedule logo a seguir — se dependêssemos do
        // model no envio, o job falharia por a linha já não existir. Guardamos escalares.
        $schedule->loadMissing(['serviceType', 'vendor.user']);

        $this->scheduleId = $schedule->id;
        $this->serviceTypeNames = $schedule->serviceType?->getTranslations('name') ?? [];
        $this->vendorName = $schedule->vendor?->user?->name ?? '';
    }

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        $title = __('notifications.scheduleCanceled.customer.title', [], $language);
        $body = __('notifications.scheduleCanceled.customer.description', [
            'vendor_name' => $this->vendorName,
            'service_type' => $this->serviceTypeName($language),
        ], $language);

        return ExpoMessage::create($title)
            ->body($body)
            ->priority('high')
            ->playSound();
    }

    public function toArray($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        return [
            'title' => __('notifications.scheduleCanceled.customer.title', [], $language),
            'body' => __('notifications.scheduleCanceled.customer.description', [
                'vendor_name' => $this->vendorName,
                'service_type' => $this->serviceTypeName($language),
            ], $language),
            'schedule_id' => $this->scheduleId,
        ];
    }

    private function serviceTypeName(string $language): string
    {
        $names = $this->serviceTypeNames;

        return $names[$language] ?? (reset($names) ?: '');
    }
}
