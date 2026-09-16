<?php

namespace App\Filament\Resources\ServicesResource\Pages;

use App\Enums\Services\ServiceStatus;
use App\Filament\Resources\ServicesResource;
use App\Models\GeneralSettings\OperationArea;
use App\Notifications\Customer\ScheduleCanceledByVendorNotification;
use App\Services\Common\Services\CancelService;
use App\Services\Common\Services\CloseService;
use App\Services\Matching\MatchingService;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\DB;

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
            // Pedido personalizado: e aqui que o backoffice faz a parte dele.
            // Ate carregar nisto o pedido esta em analise e nenhum profissional
            // sabe que existe. Ao confirmar, entra em seleccao e a primeira
            // onda de convites sai — o mesmo caminho de um pedido de catalogo,
            // com a duracao e as categorias daqui em vez das do tipo.
            Actions\Action::make('dispatch_custom_request')
                ->label(__('backoffice/service.custom.dispatch'))
                ->color('warning')
                ->icon('heroicon-o-paper-airplane')
                ->modalHeading(__('backoffice/service.custom.dispatch'))
                ->modalDescription(__('backoffice/service.custom.dispatch_description'))
                ->modalSubmitActionLabel(__('backoffice/service.custom.dispatch_submit'))
                ->visible(fn (): bool => (bool) $this->record->is_custom
                    && $this->record->status === ServiceStatus::PENDING_REVIEW)
                ->form([
                    TextInput::make('custom_duration_minutes')
                        ->label(__('backoffice/service.custom.dispatch_minutes'))
                        ->helperText(__('backoffice/service.custom.dispatch_minutes_help'))
                        ->numeric()
                        ->integer()
                        ->minValue(15)
                        ->step(15)
                        ->suffix('min')
                        ->required()
                        ->default(fn () => $this->record->custom_duration_minutes),
                    Select::make('operation_areas')
                        ->label(__('backoffice/service.custom.dispatch_areas'))
                        ->multiple()
                        ->required()
                        ->options(fn () => OperationArea::query()->get()
                            ->mapWithKeys(fn (OperationArea $a) => [$a->id => $a->getTranslation('name', 'pt-pt')])
                            ->all())
                        ->default(fn () => $this->record->operationAreas()->pluck('operation_areas.id')->all()),
                ])
                ->action(function (array $data): void {
                    try {
                        $count = DB::transaction(function () use ($data): int {
                            $this->record->custom_duration_minutes = (int) $data['custom_duration_minutes'];
                            $this->record->custom_dispatched_at = now();
                            $this->record->status = ServiceStatus::MATCHING;
                            $this->record->save();
                            $this->record->operationAreas()->sync(array_map('intval', $data['operation_areas']));

                            $candidates = app(MatchingService::class)->dispatchNextWave($this->record->refresh());

                            // Ninguem elegivel: falha ja e avisa o cliente, como
                            // o start() faz num pedido de catalogo. Deixa-lo em
                            // seleccao seria uma espera que nunca resolve.
                            if ($candidates->isEmpty()) {
                                app(MatchingService::class)->fail($this->record);
                            }

                            return $candidates->count();
                        });

                        $this->refreshFormData(['status', 'custom_duration_minutes', 'custom_dispatched_at']);

                        if ($count === 0) {
                            Notification::make()
                                ->title(__('backoffice/service.custom.dispatch_none'))
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('backoffice/service.custom.dispatch_success', ['count' => $count]))
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title(__('backoffice/service.custom.dispatch_error'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
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
