<?php

namespace App\Http\Responses\Api;

use Exception;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\Storage\EntryModel;

class ApiErrorResponse implements Responsable
{
    public function __construct(
        // ?\Throwable (não ?Exception): os históricos podem apanhar um \Error (ex.: TypeError)
        // e passá-lo aqui; com ?Exception o próprio construtor lançava TypeError → 500 cru.
        private readonly ?\Throwable $exception,
        private string $message = 'Something went wrong',
        private int $statusCode = Response::HTTP_INTERNAL_SERVER_ERROR,
        private readonly array $headers = [],
        private readonly int $options = 0
    ) {}

    public function toResponse($request): JsonResponse|\Symfony\Component\HttpFoundation\Response
    {

        if (! is_null($this->exception)) {
            if (method_exists($this->exception, 'getStatus')) {
                $this->statusCode = $this->exception->getStatus();
                $this->message = $this->exception->getMessage();
            }
        }

        $response = ['message' => __($this->message)];

        if (! empty($this->exception) && $this->statusCode == Response::HTTP_INTERNAL_SERVER_ERROR && config('app.debug')) {
            $response['debug'] = [
                'message' => $this->exception->getMessage(),
                'file' => $this->exception->getFile(),
                'line' => $this->exception->getLine(),
                'trace' => $this->exception->getTrace(),
            ];

            $response['telescope'] = route('telescope').'/requests/'.EntryModel::where('type', EntryType::EXCEPTION)
                ->where('content->response_status', Response::HTTP_INTERNAL_SERVER_ERROR)
                ->latest('created_at')
                ->first()?->uuid;
        } elseif (! is_null($this->exception)) {
            if (app(ExceptionHandler::class)->shouldReport($this->exception)) {
                Log::error($this->exception->getMessage());
            }
            report($this->exception);
        }

        return response()->json(
            $response,
            $this->statusCode,
            $this->headers,
            $this->options
        );
    }
}
