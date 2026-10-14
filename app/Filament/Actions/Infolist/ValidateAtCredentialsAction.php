<?php

namespace App\Filament\Actions\Infolist;

use App\Models\Vendor;
use App\Services\InvoiceXpress\ValidacaoDaAt;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ValidateAtCredentialsAction extends Action
{
    protected string $view = self::BUTTON_VIEW;

    public function setUp(): void
    {
        $this->label('Validar AT')
            ->icon('heroicon-o-shield-check')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Validar credenciais AT')
            ->modalDescription('Vai tentar autenticar as credenciais AT no InvoiceXpress. O resultado será guardado no perfil do técnico.')
            // As condições e os passos são os do backoffice: vivem os dois em
            // ValidacaoDaAt, para os botões não divergirem.
            ->disabled(fn () => ! $this->record instanceof Vendor
                || ValidacaoDaAt::bloqueio($this->record) !== null
            )
            ->action(function () {
                /** @var Vendor $vendor */
                $vendor = $this->record;

                try {
                    ValidacaoDaAt::validar($vendor);

                    Notification::make()
                        ->title('Credenciais AT válidas')
                        ->success()
                        ->send();
                } catch (\Exception $e) {
                    Notification::make()
                        ->title('Credenciais AT inválidas')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
