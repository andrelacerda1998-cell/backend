<?php

namespace App\Notifications\Admin;

use App\Models\Schedule\Schedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Escala uma possível não-comparência ao backoffice/ops (terceira etapa, T+20min):
 * o serviço passou a hora e continua por iniciar, o técnico já foi avisado e o
 * cliente também — agora precisa de um humano (contactar o técnico, reatribuir,
 * reembolsar). Vai por email (alguém tem de ver) e database (fica no backoffice).
 */
class NoShowOpsNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Schedule $schedule,
        private readonly string $time,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        [$serviceType, $vendorName, $customerName, $customerPhone, $serviceId] = $this->context($language);

        return (new MailMessage)
            ->subject(__('notifications.mail.noShowOps.subject', ['service_id' => $serviceId], $language))
            ->greeting(__('notifications.mail.noShowOps.greetings', [], $language))
            ->line(__('notifications.mail.noShowOps.line1', [
                'service_id' => $serviceId,
                'service_type' => $serviceType,
                'time' => $this->time,
            ], $language))
            ->line(__('notifications.mail.noShowOps.line2', [
                'vendor_name' => $vendorName,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
            ], $language))
            ->line(__('notifications.mail.noShowOps.line3', [], $language))
            ->salutation(__('notifications.mail.noShowOps.salutation', [], $language));
    }

    public function toArray($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        [$serviceType, $vendorName, $customerName, , $serviceId] = $this->context($language);

        return [
            'type' => 'no_show_ops',
            'service_id' => $serviceId,
            'title' => __('notifications.noShow.ops.title', ['service_id' => $serviceId], $language),
            'body' => __('notifications.noShow.ops.description', [
                'service_id' => $serviceId,
                'service_type' => $serviceType,
                'time' => $this->time,
                'vendor_name' => $vendorName,
                'customer_name' => $customerName,
            ], $language),
        ];
    }

    /**
     * @return array{0:string,1:string,2:string,3:string,4:int|null}
     */
    private function context(string $language): array
    {
        $schedule = $this->schedule->loadMissing(['serviceType', 'vendor.user', 'customer']);

        return [
            $schedule->serviceType?->getTranslation('name', $language) ?? '',
            $schedule->vendor?->user?->name ?? '',
            $schedule->customer?->name ?? '',
            $schedule->customer?->phone_number ?? '—',
            $schedule->service_id,
        ];
    }
}
