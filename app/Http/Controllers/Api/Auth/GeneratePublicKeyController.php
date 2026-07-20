<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Auth\Authentications;
use Illuminate\Http\Request;

class GeneratePublicKeyController extends Controller
{
    public function __invoke(Request $request)
    {
        $token = $request->headers->get('authorization');
        $token = str_replace('Bearer ', '', $token);

        $authentication = Authentications::where('token', $token)->first();

        if (! $authentication) {
            $key = $this->getPrivateKey($token);
        } else {
            $key = $authentication->rsa;
        }

        $privateKey = openssl_pkey_get_private($key);

        $keyDetails = openssl_pkey_get_details($privateKey);

        $publicKey = $keyDetails['key'];

        return ApiSuccessResponse::make(['public_key' => $publicKey]);
    }

    private function getPrivateKey($token)
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($res, $privateKey);

        $auth = new Authentications;
        $auth->token = $token;
        $auth->rsa = $privateKey;
        $auth->ip = request()->ip();
        $auth->userAgent = request()->userAgent();

        $auth->save();

        return $privateKey;
    }
}
