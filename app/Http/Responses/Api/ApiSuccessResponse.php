<?php

namespace App\Http\Responses\Api;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

readonly class ApiSuccessResponse implements Responsable
{
    public function __construct(
        private mixed $data = [],
        private array $metaData = ['message' => 'Successfully'],
        private int $statusCode = Response::HTTP_OK,
        private array $headers = [],
        private int $options = 0
    ) {}

    public function toResponse($request): JsonResponse|\Symfony\Component\HttpFoundation\Response
    {
        return response()->json(
            [
                'data' => $this->data,
                'metaData' => $this->metaData,
            ],
            $this->statusCode,
            $this->headers,
            $this->options
        );
    }

    public static function make(
        mixed $data = [],
        array $metaData = ['message' => 'Successfully'],
        int $statusCode = Response::HTTP_OK,
        array $headers = [],
        int $options = 0
    ): static
    {
        return new static(
            $data,
            $metaData,
            $statusCode,
            $headers,
            $options
        );
    }
}
