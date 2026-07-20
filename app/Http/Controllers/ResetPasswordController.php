<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\User;
use Exception;
use Filament\Events\Auth\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class ResetPasswordController extends Controller
{
  public function _invoke(string $token)
  {
        $email = request()->get('email');
        $user = User::where('email', $email)->first();

        if (!$user) {
            abort(404);
        }

        // Reativado: token inválido/expirado/já usado → erro claro, não um "redirect com sucesso".
        if (!Password::tokenExists($user, $token) && !session('status') && !session()->has('errors')) {
            abort(404);
        }

        $isVendor = $user->isVendor();
        $deepLink = ($isVendor ? 'piquet.vendor' : 'piquet.customer')
            . '://(reset-password)/' . $token . '?email=' . urlencode($email);

        // Já não fazemos redirect() para o esquema custom (browsers recusam o 302 e ficam em
        // branco). Servimos um interstitial que tenta abrir a app e cai no form web como fallback.
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $email,
            'isVendor' => $isVendor,
            'deepLink' => $deepLink,
        ]);
  }

  public function update(ResetPasswordRequest $request)
  {
    $token = $request->get('token');
    $user = User::where('email', $request->email)->first();

    // O formulário web (fallback do interstitial) usa a rota nomeada `password.update` e espera
    // HTML (redirect); a app mobile usa a rota da API e espera JSON.
    $isWeb = $request->routeIs('password.update');

    if (!$user || !Password::tokenExists($user, $token)) {
        if ($isWeb) {
            return $this->webBack($token, $request->email)
                ->withErrors(['email' => __('O link expirou ou já foi utilizado. Solicite um novo.')]);
        }
        abort(404);
    }

    if ($user && Hash::check($request->password, $user->password)) {
        if ($isWeb) {
            return $this->webBack($token, $request->email)
                ->withErrors(['email' => __('A nova password não pode ser igual à anterior.')]);
        }
        abort(422);
    }

    $status = Password::reset(
        $request->only('email', 'password', 'token'),
        function ($user, $password) {
            $user->forceFill([
                'password' => bcrypt($password),
            ])->save();

            $user->setRememberToken(Str::random(60));

            // event(new PasswordReset($user));
        }
    );

    if ($status === Password::PASSWORD_RESET) {
        return $isWeb
            ? $this->webBack($token, $request->email)->with('status', __('A sua password foi redefinida com sucesso.'))
            : new ApiSuccessResponse(['message' => 'Password reset successfully']);
    }

    return $isWeb
        ? $this->webBack($token, $request->email)->withErrors(['email' => __($status)])
        : new ApiErrorResponse(null, 'Error on send reset link', 400, ['message' => __($status)]);
  }

  /**
   * Redireciona de volta para a página de reset (GET) com token/email, para o form web mostrar
   * a mensagem de sucesso/erro em HTML em vez de JSON.
   */
  private function webBack(string $token, ?string $email)
  {
    return redirect()->route('password.reset', ['token' => $token, 'email' => $email]);
  }
}
