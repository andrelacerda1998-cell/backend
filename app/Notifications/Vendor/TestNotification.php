<?php

namespace App\Notifications\Vendor;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

class TestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private string $text, private string $title)
    {
    }

    public function via($notifiable): array
    {
        return ['expo'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        return ExpoMessage::create($this->title)
            ->body($this->text)
            ->priority('high')
            ->playSound();
    }
    public function toArray($notifiable): array
    {
        return [];
    }
}
