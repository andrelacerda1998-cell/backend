<?php

namespace App\Filament\Resources\VendorResource\Pages;

use App\Enums\Vendors\StatusVendor;
use App\Filament\Resources\VendorResource;
use App\Filament\Widgets\Vendors\VendorStats;
use App\Models\User;
use App\Models\Vendor;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ListVendors extends ListRecords
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            VendorStats::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 4;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->mutateFormDataUsing(function ($data) {
                    try {
                        if (
                            isset($data['user']) &&
                            (User::where('email', $data['user']['email'])->exists() ||
                            Vendor::where('username', $data['user']['username'])->exists())
                        ) {
                            throw new \Exception('User already exists');
                        }

                        DB::beginTransaction();

                        $user = User::create([
                            'first_name' => $data['user']['first_name'],
                            'last_name' => $data['user']['last_name'],
                            'date_birthday' => $data['user']['date_birthday'],
                            'email' => $data['user']['email'],
                            'password' => Hash::make($data['user']['password']),
                            'gender_id' => $data['user']['gender_id'],
                            'phone_number' => $data['user']['phone_number'],
                            'language' => 'PT',
                            'nif' => $data['user']['nif'],
                        ]);

                        DB::commit();

                        $data['user_id'] = $user->id;
                        $data['username'] = $data['user']['username'];
                        unset($data['user']);
                    } catch (\Exception $e) {
                        throw new \Exception($e->getMessage());
                    }

                    return $data;
                }),
        ];
    }
}
