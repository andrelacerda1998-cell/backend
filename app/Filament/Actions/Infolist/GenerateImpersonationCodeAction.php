<?php

namespace App\Filament\Actions\Infolist;

use App\Models\Auth\ImpersonationCode;
use App\Models\User;
use App\Models\Vendor;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class GenerateImpersonationCodeAction extends Action
{
    protected ?int $pendingUserId = null;

    protected ?string $pendingCode = null;

    public function setUp(): void
    {
        $this->color('warning')
            ->icon('heroicon-o-key')
            ->authorize(fn () => auth()->user()?->hasRole('super-admin') ?? false)
            ->label(__('backoffice/impersonation.action.label'))
            ->requiresConfirmation()
            ->modalHeading(__('backoffice/impersonation.action.modal_heading'))
            ->modalDescription(__('backoffice/impersonation.action.modal_description'))
            ->modalSubmitActionLabel(__('backoffice/impersonation.action.submit'))
            ->registerModalActions([
                Action::make('showImpersonationCode')
                    ->modalHeading(__('backoffice/impersonation.result.heading'))
                    ->fillForm(fn (array $arguments): array => [
                        'email' => 'imp.'.$arguments['user_id'].'@piquetapp.pt',
                        'code' => $arguments['code'],
                    ])
                    ->form([
                        TextInput::make('email')
                            ->label(__('backoffice/impersonation.result.email_label'))
                            ->readOnly()
                            ->extraInputAttributes(['onclick' => 'this.select()']),
                        TextInput::make('code')
                            ->label(__('backoffice/impersonation.result.code_label'))
                            ->readOnly()
                            ->extraInputAttributes([
                                'onclick' => 'this.select()',
                                'class' => 'font-mono tracking-widest text-lg',
                            ]),
                        Placeholder::make('expires_in')
                            ->hiddenLabel()
                            ->content(__('backoffice/impersonation.result.expires_in', ['minutes' => 15])),
                    ])
                    ->modalCancelAction(false)
                    ->modalSubmitActionLabel(__('backoffice/impersonation.result.close'))
                    ->action(fn () => null)
                    ->cancelParentActions(),
            ])
            ->action(function () {
                $targetUser = $this->record instanceof Vendor
                    ? $this->record->user
                    : $this->record;

                if (! $targetUser instanceof User) {
                    Notification::make()
                        ->title(__('backoffice/impersonation.notifications.user_not_found'))
                        ->danger()
                        ->send();

                    return;
                }

                if ($targetUser->hasAnyRole('admin', 'super-admin')) {
                    Notification::make()
                        ->title(__('backoffice/impersonation.notifications.not_allowed_for_admins'))
                        ->danger()
                        ->send();

                    return;
                }

                ImpersonationCode::query()
                    ->where('user_id', $targetUser->id)
                    ->whereNull('used_at')
                    ->update(['used_at' => now()]);

                $code = (string) random_int(10000000, 99999999);

                ImpersonationCode::create([
                    'user_id' => $targetUser->id,
                    'generated_by_id' => auth()->id(),
                    'code_hash' => Hash::make($code),
                    'expires_at' => now()->addMinutes(15),
                ]);

                Log::warning('Impersonation code generated', [
                    'admin_id' => auth()->id(),
                    'target_user_id' => $targetUser->id,
                ]);

                $this->pendingUserId = $targetUser->id;
                $this->pendingCode = $code;
            })
            ->after(function ($livewire) {
                if ($this->pendingCode === null) {
                    return;
                }

                $livewire->mountAction('showImpersonationCode', [
                    'user_id' => $this->pendingUserId,
                    'code' => $this->pendingCode,
                ]);

                $this->pendingUserId = null;
                $this->pendingCode = null;
            });
    }
}
