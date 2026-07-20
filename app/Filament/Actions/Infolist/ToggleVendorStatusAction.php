<?php

namespace App\Filament\Actions\Infolist;

use App\Enums\Services\AddressType;
use App\Enums\Vendors\StatusVendor;
use App\Models\Address;
use App\Models\Vendor;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ToggleVendorStatusAction extends Action
{
    protected string $view = self::BUTTON_VIEW;

    public function setUp(): void
    {
        $this->label(fn () => $this->isOnline() ? 'Ficar Offline' : 'Ficar Online')
            ->icon(fn () => $this->isOnline() ? 'heroicon-o-signal-slash' : 'heroicon-o-signal')
            ->color(fn () => $this->isOnline() ? 'gray' : 'success')
            ->visible(fn () => $this->fiscalAddressWithCoords() !== null)
            ->requiresConfirmation()
            ->modalHeading(fn () => $this->isOnline() ? 'Colocar técnico Offline' : 'Colocar técnico Online')
            ->modalDescription(fn () => $this->isOnline()
                ? 'O técnico deixará de aparecer na busca dos clientes.'
                : 'O técnico ficará Online usando a localização do endereço fiscal cadastrado.')
            ->action(function () {
                /** @var Vendor $vendor */
                $vendor = $this->record;

                try {
                    if ($this->isOnline()) {
                        $vendor->status = StatusVendor::OFFLINE;
                        $vendor->save();
                        $vendor->searchable();

                        Notification::make()
                            ->title('Técnico agora está Offline')
                            ->success()
                            ->send();

                        return;
                    }

                    $address = $this->fiscalAddressWithCoords();

                    if ($address === null) {
                        Notification::make()
                            ->title('Endereço fiscal sem coordenadas')
                            ->body('Não foi possível colocar Online porque o endereço fiscal não tem latitude/longitude.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $vendor->status = StatusVendor::ONLINE;
                    $vendor->save();

                    $vendor->currentLocation()->updateOrCreate([], [
                        'latitude' => $address->latitude,
                        'longitude' => $address->longitude,
                    ]);
                    $vendor->currentLocation()->touch();

                    $vendor->searchable();

                    Notification::make()
                        ->title('Técnico agora está Online')
                        ->body('Localização definida a partir do endereço fiscal cadastrado.')
                        ->success()
                        ->send();
                } catch (\Exception $e) {
                    Notification::make()
                        ->title('Não foi possível alterar o status')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private function isOnline(): bool
    {
        return $this->record instanceof Vendor
            && $this->record->status === StatusVendor::ONLINE;
    }

    private function fiscalAddressWithCoords(): ?Address
    {
        if (! $this->record instanceof Vendor) {
            return null;
        }

        return $this->record->addresses()
            ->where('address_type', AddressType::FISCAL_ADDRESS)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->first();
    }
}
