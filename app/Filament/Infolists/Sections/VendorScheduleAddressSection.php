<?php

namespace App\Filament\Infolists\Sections;

use App\Enums\Services\AddressType;
use App\Models\Address;
use App\Models\Vendor;
use App\Services\Common\AddressService;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\Actions\Action;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;

class VendorScheduleAddressSection
{
    public static function make(): Section
    {
        return Section::make(__('backoffice/vendor.infolist.schedule_address.title'))
            ->icon(fn (Vendor $record) => self::scheduleAddress($record) ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-circle')
            ->iconColor(fn (Vendor $record) => self::scheduleAddress($record) ? 'success' : 'danger')
            ->headerActions([
                Action::make('configureScheduleAddress')
                    ->label(__('backoffice/vendor.infolist.schedule_address.configure'))
                    ->button()
                    ->icon('heroicon-o-map-pin')
                    ->modalHeading(__('backoffice/vendor.infolist.schedule_address.configure'))
                    ->form([
                        TextInput::make('address_name')
                            ->label(__('backoffice/vendor.infolist.schedule_address.address_name'))
                            ->required(),
                        TextInput::make('street_name')
                            ->label(__('backoffice/vendor.infolist.schedule_address.street_name'))
                            ->required(),
                        TextInput::make('street_number')
                            ->label(__('backoffice/vendor.infolist.schedule_address.street_number'))
                            ->required(),
                        TextInput::make('postal_code')
                            ->label(__('backoffice/vendor.infolist.schedule_address.postal_code'))
                            ->mask('9999-999')
                            ->required(),
                        TextInput::make('city')
                            ->label(__('backoffice/vendor.infolist.schedule_address.city'))
                            ->required(),
                    ])
                    ->mountUsing(function (Form $form, Vendor $record) {
                        $address = self::scheduleAddress($record);

                        if ($address) {
                            $form->fill([
                                'address_name' => $address->address_name,
                                'street_name' => $address->street_name,
                                'street_number' => $address->street_number,
                                'postal_code' => $address->postal_code,
                                'city' => $address->city,
                            ]);
                        }
                    })
                    ->action(function (Vendor $record, array $data) {
                        try {
                            $service = app(AddressService::class);
                            $coordinates = $service->getCoordinates($data);

                            if (empty($coordinates)) {
                                Notification::make()
                                    ->title(__('backoffice/vendor.infolist.schedule_address.geocode_failed'))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            // Reusa exatamente a mesma transformação do app vendor
                            // (ScheduleController::update) — valida Portugal e monta lat/lng.
                            $addressData = $service->transformAddress($data, $coordinates);

                            // Escopo por address_type: nunca toca no endereço fiscal/casa.
                            $record->addresses()->updateOrCreate(
                                ['address_type' => AddressType::SCHEDULE_ADDRESS],
                                [
                                    ...$addressData,
                                    'user_id' => $record->user_id,
                                    'address_type' => AddressType::SCHEDULE_ADDRESS,
                                    'main_address' => false,
                                ],
                            );

                            // O AddressObserver reindexa o índice vendors_schedule automaticamente.

                            Notification::make()
                                ->title(__('backoffice/vendor.infolist.schedule_address.saved'))
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title(__('backoffice/vendor.infolist.schedule_address.save_error'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->schema([
                TextEntry::make('schedule_address_missing')
                    ->hiddenLabel()
                    ->state(__('backoffice/vendor.infolist.schedule_address.not_configured'))
                    ->icon('heroicon-o-exclamation-triangle')
                    ->iconColor('danger')
                    ->color('danger')
                    ->visible(fn (Vendor $record) => self::scheduleAddress($record) === null),
                TextEntry::make('schedule_address_full')
                    ->label(__('backoffice/vendor.infolist.schedule_address.address'))
                    ->state(fn (Vendor $record) => self::scheduleAddress($record)?->name ?? '-')
                    ->visible(fn (Vendor $record) => self::scheduleAddress($record) !== null),
                TextEntry::make('schedule_address_coords')
                    ->label(__('backoffice/vendor.infolist.schedule_address.coordinates'))
                    ->state(function (Vendor $record) {
                        $address = self::scheduleAddress($record);

                        return $address ? "{$address->latitude}, {$address->longitude}" : '-';
                    })
                    ->visible(fn (Vendor $record) => self::scheduleAddress($record) !== null),
                TextEntry::make('schedule_availability_hint')
                    ->hiddenLabel()
                    ->state(__('backoffice/vendor.infolist.schedule_address.no_availability'))
                    ->icon('heroicon-o-exclamation-triangle')
                    ->iconColor('warning')
                    ->color('warning')
                    ->visible(fn (Vendor $record) => ! $record->scheduleAvailable()->where('is_enabled', true)->exists()),
            ]);
    }

    private static function scheduleAddress(Vendor $record): ?Address
    {
        return $record->addresses()
            ->where('address_type', AddressType::SCHEDULE_ADDRESS)
            ->first();
    }
}
