<?php

namespace App\Notifications\Admin;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alguém reportou um problema num serviço e o fecho automático parou.
 *
 * Por email (alguém tem de ver) e na base de dados (fica no backoffice), como
 * o alerta de não-comparência. Sem uma pessoa a decidir, o serviço ficaria
 * parado: não fecha sozinho enquanto o problema estiver por resolver.
 */
class ServiceProblemOpsNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Service $service) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        $c = $this->contexto($language);

        return (new MailMessage)
            ->subject(__('notifications.mail.problemReportedOps.subject', ['service_id' => $c['service_id']], $language))
            ->line(__('notifications.mail.problemReportedOps.line1', $c, $language))
            ->line(__('notifications.mail.problemReportedOps.line2', $c, $language))
            ->line($c['message'] !== '' ? '“'.$c['message'].'”' : '')
            ->line(__('notifications.mail.problemReportedOps.line3', [], $language));
    }

    public function toArray($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        $c = $this->contexto($language);

        return [
            'type' => 'service_problem_ops',
            'service_id' => $c['service_id'],
            'title' => __('notifications.mail.problemReportedOps.subject', ['service_id' => $c['service_id']], $language),
            'body' => __('notifications.mail.problemReportedOps.line1', $c, $language),
        ];
    }

    private function contexto(string $language): array
    {
        $s = $this->service->loadMissing(['serviceType', 'vendor.user', 'customerUser']);

        return [
            'service_id' => $s->id,
            'service_type' => $s->titulo($language) ?? '',
            'reported_by' => __('notifications.mail.problemReportedOps.by_'.($s->problem_reported_by ?? 'customer'), [], $language),
            'reason' => __('notifications.mail.problemReportedOps.reasons.'.($s->problem_reason ?? 'other'), [], $language),
            'customer_name' => $s->customerUser?->name ?? '',
            'customer_phone' => $s->customerUser?->phone_number ?? '',
            'vendor_name' => $s->vendor?->user?->name ?? '',
            'message' => trim((string) $s->problem_message),
        ];
    }
}
