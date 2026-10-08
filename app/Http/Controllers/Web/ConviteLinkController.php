<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Carteira\Convites;
use Illuminate\Http\Request;

/**
 * GET /c/{codigo} — o link que vai na mensagem de "Convida um amigo".
 *
 * Leva cada um à loja certa, com o código a seguir:
 *  - Android: vai direto ao Google Play com `referrer=piquet_convite=CODIGO`.
 *    A Play Store entrega esse referrer à app no primeiro arranque (Install
 *    Referrer), e a app preenche e aplica o código sozinha.
 *  - iPhone: a App Store não passa nada à app. A página mostra o código e um
 *    botão que o COPIA e abre a App Store; na app, o código cola-se com um
 *    toque. "Já tenho a app" abre a app com o código (piquet.customer://).
 *  - Computador: a página com o código e as duas lojas.
 *
 * Um código que não existe não dá erro: segue para a loja na mesma, sem
 * código — o link não serve para adivinhar quais existem.
 */
class ConviteLinkController extends Controller
{
    public function __invoke(Request $request, string $codigo, Convites $convites)
    {
        $codigo = Convites::normalizar($codigo);
        $valido = preg_match('/^[A-Z0-9]{4,12}$/', $codigo) && $convites->encontrarCodigo($codigo);
        $codigo = $valido ? $codigo : null;

        $ua = (string) $request->userAgent();
        $playUrl = config('services.lojas.google_play')
            .($codigo ? '&referrer='.rawurlencode('piquet_convite='.$codigo.'&utm_source=convite&utm_medium=link') : '');

        if (stripos($ua, 'Android') !== false) {
            return redirect()->away($playUrl);
        }

        return response()->view('convite', [
            'codigo' => $codigo,
            'appStoreUrl' => config('services.lojas.app_store'),
            'playUrl' => $playUrl,
            'abrirApp' => $codigo ? 'piquet.customer://convite/'.$codigo : 'piquet.customer://',
            'eIphone' => (bool) preg_match('/iPhone|iPad|iPod/i', $ua),
            'valor' => number_format(Convites::VALOR / 100, 0),
        ])->header('Cache-Control', 'no-store');
    }
}
