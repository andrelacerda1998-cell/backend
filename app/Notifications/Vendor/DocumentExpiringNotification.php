<?php

namespace App\Notifications\Vendor;

use App\Models\Vendor\VendorDocuments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * Avisa o técnico de que um documento aprovado está prestes a expirar
 * (30, 15, 7, 3 e 1 dias antes). Quando expira, o técnico deixa de poder
 * aceitar serviços (Vendor::canAcceptService -> all_documents_verified).
 *
 * Só expõe o TIPO de documento e o prazo — nunca o conteúdo/ficheiro.
 */
class DocumentExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly VendorDocuments $document,
        private readonly int $daysLeft,
    ) {}

    /**
     * Não respeita notification_preferences por opção: é operacionalmente crítico
     * (bloqueia o rendimento do técnico), tal como cancelamentos e documentos recusados.
     * Ver App\Notifications\Concerns\RespectsVendorPreference.
     */
    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $copy = $this->copy($notifiable);

        return ExpoMessage::create($copy['title'])
            ->body($copy['body'])
            ->priority('high')
            ->playSound()
            ->data([
                'open_type' => 'documents',
                'open_id' => $this->document->id,
            ]);
    }

    public function toArray($notifiable): array
    {
        $copy = $this->copy($notifiable);

        return [
            'title' => $copy['title'],
            'body' => $copy['body'],
            'document_id' => $this->document->id,
            'days_left' => $this->daysLeft,
            'open_type' => 'documents',
            'open_id' => $this->document->id,
        ];
    }

    /**
     * @return array{title: string, body: string}
     */
    private function copy($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        $documentName = $this->document->loadMissing('type')->type?->getTranslation('name', $language)
            ?? $this->document->type?->name
            ?? '';

        // 1 dia = último aviso, com tom mais direto.
        $key = $this->daysLeft <= 1 ? 'notifications.documents.expiring_last_call' : 'notifications.documents.expiring';

        $replace = ['document' => $documentName, 'days' => $this->daysLeft];

        return [
            'title' => __($key.'.title', $replace, $language),
            'body' => __($key.'.description', $replace, $language),
        ];
    }
}
