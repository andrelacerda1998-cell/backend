<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\CreateVendorRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\Auth\LoginApiResponse;
use App\Models\User;
use App\Notifications\Auth\UserRegistered;
use App\Support\Locale;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateVendorController extends Controller
{
    public function __invoke(CreateVendorRequest $request)
    {
        $name = $request->input('name');
        if (strpos($name, ' ') === false) {
            $first_name = $name;
            $last_name = '';
        } else {
            $first_name = explode(' ', $name)[0];
            $last_name = explode(' ', $name)[1];
        }

        try {
            DB::beginTransaction();
            $user = User::create([
                'first_name' => $first_name,
                'last_name' => $last_name,
                // 'date_birthday' => $request->input('date_birthday'),
                'email' => $request->input('email'),
                'password' => Hash::Make($request->input('password')),
                // 'gender_id' => $request->input('gender_id') ?? null,
                'phone_number' => $request->input('phone_number'),
                'language' => Locale::normalize($request->input('language')),
                // 'nif' => $request->input('nif'),
            ]);

            $vendor = $user->vendor()->create([
                'username' => $request->input('username'),
                'price_rate' => $request->input('price_rate')
            ]);

            $vendor->operationAreas()->sync($request->input('operation_areas'));
            $vendor->servicesTypes()->sync($request->input('services_types'));

            /*if ($request->get('documents')) {
                foreach ($request->get('documents') as $key => $document) {
                    $vendorDocument = $vendor->documents()->create([
                        'document_id' => $document['document_id'],
                    ]);

                    if ($request->hasFile("documents.$key.file")) {
                        $vendorDocument->addMediaFromRequest("documents.$key.file")->toMediaCollection();
                    }
                }
            }*/

            DB::commit();
            $token = auth('api')->login($user);

            $user->sendEmailVerificationNotification();

            event(new UserRegistered($user));

            return new LoginApiResponse($token, ['message' => 'Vendor created successfully']);
        } catch (Exception $exception) {
            DB::rollBack();

            return new ApiErrorResponse($exception);
        }
    }
}
