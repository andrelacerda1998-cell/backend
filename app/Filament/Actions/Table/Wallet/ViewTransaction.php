<?php

namespace App\Filament\Actions\Table\Wallet;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\ServicesResource;
use App\Filament\Resources\VendorResource;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Filament\Tables\Actions\Action;

class ViewTransaction extends Action
{
    public static function make(?string $name = null): static
    {
        $instance = parent::make($name);

        $instance->action(function ($record) use ($instance) {
            if (!isset($record['meta'])) {
                return;
            }
            $class = $record['meta']['class'];

            $resource = match ($class) {
                User::class => CustomerResource::class,
                Vendor::class => VendorResource::class,
                Service::class => ServicesResource::class,
                default => null
            };

            if (!$resource) {
                return;
            }

            $instance->redirect($resource::getUrl('view', ['record' => $record['meta']['id']]));
        });

        $instance->disabled(function ($record) {
            if (!isset($record['meta']) || !isset($record['meta']['class'])) {
                return true;
            }
            $class = $record['meta']['class'];

            $resource = match ($class) {
                User::class => CustomerResource::class,
                Vendor::class => VendorResource::class,
                Service::class => ServicesResource::class,
                default => null
            };

            if (!$resource) {
                return true;
            }
            $model = $record['meta']['class']::find($record['meta']['id']);
            if ($model) {
                return !$resource::canView($record['meta']['class']::find($record['meta']['id']));
            }
            return false;
        });

        return $instance;
    }
}
