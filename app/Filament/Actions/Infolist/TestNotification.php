<?php

namespace App\Filament\Actions\Infolist;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

class TestNotification extends Action
{
    public function setUp(): void
    {
        $this->color('info')
            ->label('Test Notification')
            ->modal()
            ->form([
                TextInput::make('title')->required(),
                Textarea::make('text')->label('Notification')->required(),
            ])
            ->action(function ($data) {
                $this->record->user->notify(new \App\Notifications\Vendor\TestNotification($data['text'], $data['title']));

                \Filament\Notifications\Notification::make()
                    ->title('Success')
                    ->success()
                    ->send();

            })
            ->modalSubmitActionLabel(__('filament-actions::edit.single.modal.actions.save.label'));
    }
}
