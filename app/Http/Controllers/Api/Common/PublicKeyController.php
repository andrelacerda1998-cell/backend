<?php

namespace App\Http\Controllers\Api\Common;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;

class PublicKeyController extends Controller
{
    public function __invoke(Service $service)
    {
        if ($service->customer_id !== auth('api')->user()->id && $service->vendor->user->id !== auth('api')->user()->id) {
            abort(403);
        }

        if (!$service->rsa){
            $res = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);

            openssl_pkey_export($res, $privateKey);

            $service->rsa = $privateKey;
            $service->save();
        }
        $privateKey = openssl_pkey_get_private($service->rsa);

        $keyDetails = openssl_pkey_get_details($privateKey);

        $publicKey = $keyDetails['key'];

        return ApiSuccessResponse::make([
            'public_key' => $publicKey,
        ]);
    }
}
