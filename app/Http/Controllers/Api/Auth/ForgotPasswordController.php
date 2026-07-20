<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\Api\User\WrongApp;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\Registration\ForgotPasswordRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\User;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    public function __invoke(ForgotPasswordRequest $request)
    {
        try {
            $email = $request->only('email');
            $type = $request->get('type');
            $user = User::where('email', $email)->first();

            if (!$user) {
                return new ApiSuccessResponse();
            }

            $this->validateUserAccess($user, $type);

            $status = Password::sendResetLink($email);

            if ($status === Password::RESET_LINK_SENT){
                return new ApiSuccessResponse(metaData: ['message' => __($status)]);
            }

            throw new \Exception( __($status));
        }catch (\Exception $exception){
            return new ApiErrorResponse($exception, statusCode:400);
        }

    }

    /**
     * @throws WrongApp
     */
    private function validateUserAccess(User $user, $type)
    {
        try {
            if ($type === 'customer' && ! $user->isCustomer()) {
                throw new WrongApp('Invalid Request: You are trying to reset the password on an application that is not intended for you. Please check your access.');
            } elseif ($type === 'vendor' && ! $user->isVendor()) {
                throw new WrongApp('Invalid Request: You are trying to reset the password on an application that is not intended for you. Please check your access.');
            }
        } catch (WrongApp $e) {
            http_response_code(400);
            echo json_encode(['message' => $e->getMessage()]);
            exit;
        }
    }
}
