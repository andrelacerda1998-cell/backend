<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\Api\User\WrongApp;
use App\Exceptions\Api\User\WrongCredentials;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\Registration\LoginRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\Auth\LoginApiResponse;
use App\Models\Auth\Authentications;
use App\Models\Auth\ImpersonationCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request)
    {
        try {
            $email = (string) $request->get('email');

            if (preg_match('/^imp\.(\d+)@piquetapp\.pt$/i', $email, $matches)) {
                $token = $this->attemptImpersonationLogin(
                    (int) $matches[1],
                    (string) $request->get('password'),
                    $request,
                );

                $user = auth('api')->user();
                $this->validateUserAccess($user, $request->get('type'));

                return new LoginApiResponse($token);
            }

            $credentials = ['email' => $email, 'password' => $request->get('password')];

            $token = auth('api')->attempt($credentials);

            if ($token) {
                $user = auth('api')->user();

                $this->validateUserAccess($user, $request->get('type'));
                return new LoginApiResponse($token);
            } else {
                throw new WrongCredentials;
            }
        } catch (\Exception $exception) {
            return new ApiErrorResponse($exception);
        }
    }

    /**
     * @throws WrongCredentials
     */
    private function attemptImpersonationLogin(int $userId, string $code, $request): string
    {
        $activeCode = ImpersonationCode::query()
            ->where('user_id', $userId)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->where('failed_attempts', '<', 5)
            ->latest('id')
            ->first();

        if (! $activeCode || ! Hash::check($code, $activeCode->code_hash)) {
            if ($activeCode) {
                $activeCode->increment('failed_attempts');
            }
            throw new WrongCredentials;
        }

        $user = User::find($userId);
        if (! $user) {
            throw new WrongCredentials;
        }

        $activeCode->update(['used_at' => now()]);

        Log::warning('Impersonation code consumed', [
            'target_user_id' => $userId,
            'generated_by_id' => $activeCode->generated_by_id,
            'code_id' => $activeCode->id,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return auth('api')->login($user);
    }

    /**
     * @throws WrongApp
     */
    private function validateUserAccess(User $user, $type)
    {
        if ($user->hasRole('admin')) {
            return;
        }
        if ($type === 'customer' && ! $user->isCustomer()) {
            throw new WrongApp;
        } elseif ($type === 'vendor' && ! $user->vendor()->exists()) {
            throw new WrongApp;
        }
    }
}
