<?php

namespace App\Notifications\Vendor;

use App\Models\Schedule\Schedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

class ScheduleCanceledByCustomerNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private readonly int $scheduleId;

    /** @var array<string,string> Traduções do nome do tipo de serviço (por locale). */
    private readonly array $serviceTypeNames;

    private readonly string $customerName;

    public function __construct(Schedule $schedule)
    {
        // Resolvemos o conteúdo AGORA (schedule ainda existe). A notificação é ShouldQueue e
        // o controller apaga o schedule logo a seguir — guardar escalares evita o job falhar
        // ao tentar recarregar uma linha já deletada.
        $schedule->loadMissing(['serviceType', 'customer']);

        $this->scheduleId = $schedule->id;
        $this->serviceTypeNames = $schedule->serviceType?->getTranslations('name') ?? [];
        $this->customerName = $schedule->customer?->name ?? '';
    }

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        $title = __('notifications.scheduleCanceled.vendor.title', [], $language);
        $body = __('notifications.scheduleCanceled.vendor.description', [
            'customer_name' => $this->customerName,
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
            'title' => __('notifications.scheduleCanceled.vendor.title', [], $language),
            'body' => __('notifications.scheduleCanceled.vendor.description', [
                'customer_name' => $this->customerName,
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
