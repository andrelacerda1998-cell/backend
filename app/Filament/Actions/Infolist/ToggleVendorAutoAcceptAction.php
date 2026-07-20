<?php

namespace App\Filament\Actions\Infolist;

use App\Models\Vendor;
use App\Models\VendorScheduleSearch;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ToggleVendorAutoAcceptAction extends Action
{
    protected string $view = self::BUTTON_VIEW;

    public function setUp(): void
    {
        $this->label(fn () => $this->isAutoAccept() ? 'Desativar auto-aceitação' : 'Ativar auto-aceitação')
            ->icon(fn () => $this->isAutoAccept() ? 'heroicon-o-bolt-slash' : 'heroicon-o-bolt')
            ->color(fn () => $this->isAutoAccept() ? 'gray' : 'success')
            ->visible(fn () => $this->record instanceof Vendor
                && $this->record->scheduleAvailable()->exists())
            ->requiresConfirmation()
            ->modalHeading(fn () => $this->isAutoAccept() ? 'Desativar auto-aceitação' : 'Ativar auto-aceitação')
            ->modalDescription(fn () => $this->isAutoAccept()
                ? 'Os agendamentos deixarão de ser aceitos automaticamente em todos os dias da agenda.'
                : 'Os agendamentos passarão a ser aceitos automaticamente em todos os dias da agenda.')
            ->action(function () {
                /** @var Vendor $vendor */
                $vendor = $this->record;

                try {
                    $newValue = ! $this->isAutoAccept();

                    // Altera SOMENTE auto_accept, uniformemente nas linhas de agenda.
                    // is_enabled/time_start/time_end permanecem intactos.
                    $vendor->scheduleAvailable()->update(['auto_accept' => $newValue]);

                    // O bulk update() não dispara o ScheduleAvailableObserver,
                    // então reindexamos o índice vendors_schedule manualmente.
                    VendorScheduleSearch::find($vendor->id)?->searchable();

                    Notification::make()
                        ->title($newValue ? 'Auto-aceitação ativada' : 'Auto-aceitação desativada')
                        ->success()
                        ->send();
                } catch (\Exception $e) {
                    Notification::make()
                        ->title('Não foi possível alterar a auto-aceitação')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private function isAutoAccept(): bool
    {
        return $this->record instanceof Vendor
            && $this->record->scheduleAvailable()->where('auto_accept', true)->exists();
    }
}
