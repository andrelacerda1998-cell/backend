<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use Illuminate\Http\Request;

/**
 * Ligar e desligar as comunicacoes de marketing.
 *
 * Endpoint proprio, e nao mais um campo no /profile/update: aquele exige nome,
 * telefone e companhia, e um interruptor nas Definicoes nao tem nada disso
 * para enviar. Alem disso, o que se guarda aqui e uma data (quando consentiu),
 * nao um valor que o cliente escreve.
 */
class MarketingConsentController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'accepted' => 'required|boolean',
        ]);

        $user = auth()->user();
        $user->marketing_consent_at = $request->boolean('accepted') ? now() : null;
        $user->save();

        return new ApiSuccessResponse([
            'marketing_consent_at' => $user->marketing_consent_at?->toIso8601String(),
        ]);
    }
}
