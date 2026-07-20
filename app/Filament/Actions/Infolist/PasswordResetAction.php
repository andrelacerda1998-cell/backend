<?php

namespace App\Filament\Actions\Infolist;

use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Auth\PasswordResetNotification;
use Filament\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

class PasswordResetAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'passwordReset';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->color('info')
            ->label(__('backoffice/customer.action.reset_password'))
            ->modal()
            ->form([
                Radio::make('password_reset')
                    ->options([
                        'email' => __('backoffice/customer.action.reset_password_form.options.email'),
                        'setPassword' => __('backoffice/customer.action.reset_password_form.options.password_input'),
                    ])
                    ->default('email')
                    ->reactive(),
                Grid::make(2)
                    ->visible(fn (Get $get) => $get('password_reset') === 'setPassword')
                    ->schema([
                        TextInput::make('password')
                            ->minLength(8)
                            ->required()
                            ->password(),
                        TextInput::make('password_confirmation')
                            ->minLength(8)
                            ->required()
                            ->same('password')
                            ->password(),

                    ]),
            ])
            ->action(function ($data) {
                if ($data['password_reset'] === 'email') {
                    $user = $this->record;
                    if ($this->record instanceof Vendor) {
                        $user = $this->record->user;
                    }
                    $token = Password::broker()->createToken($user);
                    Notification::send($user, new PasswordResetNotification($token));

                    \Filament\Notifications\Notification::make()->title(__('backoffice/notifications.sent_with_success'))->success()->send();

                    return;
                }
                if ($this->record instanceof User) {
                    $this->record->password = $data['password'];
                    $this->record->save();
                } else {
                    $this->record->user->password = $data['password'];
                    $this->record->user->save();
                }

                \Filament\Notifications\Notification::make()->title(__('backoffice/notifications.success'))->success()->send();

            })
            ->modalSubmitActionLabel(__('filament-actions::edit.single.modal.actions.save.label'));
    }
}
