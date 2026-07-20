<?php

namespace App\Http\Responses\Api\Auth;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;

readonly class LoginApiResponse implements Responsable
{
    public function __construct(
        private string $token,
        private array $metaData = ['message' => 'Successfully'],
        private array $data = []
    ) {}

    public function toResponse($request): JsonResponse
    {
        return response()->json(
            [
                'data' => [
                    'access_token' => $this->token,
                    'token_type' => 'bearer',
                    'expires_in' => config('auth.ttl'),
                    ...$this->data,
                ],
                'metaData' => $this->metaData,
            ]
        );
    }
}
