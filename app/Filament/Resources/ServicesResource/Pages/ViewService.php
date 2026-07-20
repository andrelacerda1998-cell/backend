<?php

namespace App\Filament\Resources\ServicesResource\Pages;

use App\Enums\Services\ServiceStatus;
use App\Filament\Resources\ServicesResource;
use App\Notifications\Customer\ScheduleCanceledByVendorNotification;
use App\Services\Common\Services\CancelService;
use App\Services\Common\Services\CloseService;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;

class ViewService extends ViewRecord
{
    protected static string $resource = ServicesResource::class;

    public function boot(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn () => view('services.chat-panel', [
                'service' => $this->record,
            ])
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('close_service')
                ->label('Fechar Serviço')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->modalHeading('Fechar Serviço')
                ->modalDescription('Tens a certeza que queres fechar este serviço? Esta ação irá confirmar o pagamento e depositar o valor na carteira do prestador.')
                ->modalSubmitActionLabel('Sim, fechar serviço')
                ->visible(fn (): bool => $this->record->status === ServiceStatus::FINISHED)
                ->action(function (): void {
                    try {
                        (new CloseService($this->record))->close();
                        $this->refreshFormData(['status']);
                        Notification::make()
                            ->title('Serviço fechado com sucesso')
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Erro ao fechar serviço')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\Action::make('archive_service')
                ->label('Arquivar Serviço')
                ->color('gray')
                ->icon('heroicon-o-archive-box')
                ->requiresConfirmation()
                ->modalHeading('Arquivar Serviço')
                ->modalDescription('Tens a certeza que queres arquivar este serviço? Ele deixará de aparecer como aberto/pendente e o cliente poderá solicitar novos serviços.')
                ->modalSubmitActionLabel('Sim, arquivar serviço')
                ->visible(fn (): bool => in_array($this->record->status, [
                    ServiceStatus::PENDING,
                    ServiceStatus::PENDING_3DS,
                    ServiceStatus::SCHEDULED,
                ], true))
                ->action(function (): void {
                    try {
                        $this->record->update(['status' => ServiceStatus::ARCHIVED]);
                        $this->refreshFormData(['status']);
                        Notification::make()
                            ->title('Serviço arquivado com sucesso')
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Erro ao arquivar serviço')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\Action::make('cancel_scheduled_service')
                ->label(__('backoffice/service.actions.cancel_scheduled'))
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->requiresConfirmation()
                ->modalHeading(__('backoffice/service.actions.cancel_scheduled'))
                ->modalDescription(__('backoffice/service.actions.cancel_scheduled_description'))
                ->modalSubmitActionLabel(__('backoffice/service.actions.cancel_scheduled_submit'))
                ->visible(fn (): bool => $this->record->status === ServiceStatus::SCHEDULED
                    && (auth()->user()?->hasAnyRole('admin', 'super-admin') ?? false))
                ->action(function (): void {
                    try {
                        (new CancelService($this->record))->customerCancel();

                        // Notificar o customer do cancelamento (construir a notificação ANTES
                        // do delete: ela captura os dados do schedule no construtor). Falha de
                        // push nunca pode quebrar o cancelamento.
                        $schedule = $this->record->schedule;
                        $customer = $schedule?->customer;
                        if ($schedule && $customer && ! $customer->trashed() && $customer->devices()->exists()) {
                            try {
                                $customer->notify(new ScheduleCanceledByVendorNotification($schedule));
                            } catch (\Throwable $e) {
                                report($e);
                            }
                        }

                        $schedule?->delete();
                        $this->refreshFormData(['status']);
                        Notification::make()
                            ->title(__('backoffice/service.actions.cancel_scheduled_success'))
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title(__('backoffice/service.actions.cancel_scheduled_error'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\Action::make('cancel_refund_closed_service')
                ->label(__('backoffice/service.actions.cancel_refund_closed'))
                ->color('danger')
                ->icon('heroicon-o-arrow-uturn-left')
                ->requiresConfirmation()
                ->modalHeading(__('backoffice/service.actions.cancel_refund_closed'))
                ->modalDescription(__('backoffice/service.actions.cancel_refund_closed_description'))
                ->modalSubmitActionLabel(__('backoffice/service.actions.cancel_refund_closed_submit'))
                ->form([
                    Textarea::make('justification')
                        ->label(__('backoffice/service.actions.cancel_refund_closed_justification'))
                        ->required()
                        ->rows(3),
                ])
                ->authorize(fn (): bool => auth()->user()?->hasRole('super-admin') ?? false)
                ->visible(fn (): bool => $this->record->status === ServiceStatus::CLOSED)
                ->action(function (array $data): void {
                    try {
                        (new CancelService($this->record))->superAdminCancelClosedService($data['justification']);
                        $this->refreshFormData(['status', 'payment_status', 'status_justification']);
                        Notification::make()
                            ->title(__('backoffice/service.actions.cancel_refund_closed_success'))
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title(__('backoffice/service.actions.cancel_refund_closed_error'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
