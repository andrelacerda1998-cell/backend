<?php

namespace App\Filament\Actions\Infolist;

use App\Exceptions\Api\Vendor\VendorDuplicatedSequence;
use App\Models\Vendor;
use App\Services\InvoiceXpress\InvoiceVendorService;
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
            ->disabled(fn () => ! $this->record instanceof Vendor
                || ! $this->record->auth_token
                || ! $this->record->invoice_workspace
                || ! $this->record->at_user
            )
            ->action(function () {
                /** @var Vendor $vendor */
                $vendor = $this->record;

                try {
                    $service = new InvoiceVendorService($vendor);

                    $service->updateFiscalDetails();
                    $service->createAtCommunications();

                    try {
                        $service->createSequence();
                    } catch (VendorDuplicatedSequence) {
                        // sequence already exists — credentials are valid
                    }

                    $vendor->at_valid = true;
                    $vendor->at_validated_at = now();
                    $vendor->save();

                    Notification::make()
                        ->title('Credenciais AT válidas')
                        ->success()
                        ->send();
                } catch (\Exception $e) {
                    $vendor->at_valid = false;
                    $vendor->save();

                    Notification::make()
                        ->title('Credenciais AT inválidas')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
