<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\Registration\CreateUserRequest;
use App\Http\Requests\Api\Auth\Registration\VerifyUserDataToCreateRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Http\Responses\Api\Auth\LoginApiResponse;
use App\Models\User;
use App\Notifications\Auth\UserRegistered;
use Exception;
use Illuminate\Support\Facades\Hash;

class CreateUserController extends Controller
{
    public function createCustomerUser(CreateUserRequest $request)
    {
        try {
            $data = $request->validated();   // só campos declarados nas rules()
            if (strpos($data['name'], ' ') === false) {
                $data['first_name'] = $data['name'];
                $data['last_name'] = '';
            } else {
                $data['first_name'] = explode(' ', $data['name'])[0];
                $data['last_name'] = explode(' ', $data['name'])[1];
            }

            $data['password'] = Hash::make($data['password']);
            $data['language'] = $data['language'] ?? 'en';
            $user = User::create($data);

            // try {
            //     $user->addresses()->updateOrCreate([], $data['address']);
            // } catch (\Exception $e) {
            //     error_log($e->getMessage());
            // }

            $token = auth('api')->login($user);

            $user->sendEmailVerificationNotification();

            event (new UserRegistered($user));

            return new LoginApiResponse($token, ['message' => 'Account created successfully']);
        } catch (Exception $exception) {
            return new ApiErrorResponse($exception);
        }
    }

    public function verifyUserDataToCreate(VerifyUserDataToCreateRequest $request): ApiSuccessResponse
    {
        return new ApiSuccessResponse(['message' => 'Email and Phone Number are available', 'can_use' => true]);
    }
}
