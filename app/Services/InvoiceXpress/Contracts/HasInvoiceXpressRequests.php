<?php

namespace App\Services\InvoiceXpress\Contracts;

use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait HasInvoiceXpressRequests
{
    public string $apiKey;

    public string $workspace;

    /**
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $urlParams
     * @return array
     */
    private function sendRequest(string $url, string $method = 'POST', array $data = [], array $urlParams = []): array
    {
        $fullUrl = $this->buildFullUrl($url, $urlParams);
        $requestConfig = ['json' => $data];

        $this->logRequestStart($method, $url, $requestConfig);
        $startTime = microtime(true);

        // timeout: sem ele o worker ficava preso indefinidamente numa chamada lenta.
        // Levantar APENAS em 5xx: há chamadores que leem corpos 4xx de propósito
        // (VendorDuplicatedSequence → idempotência do PrepareWorkspaceJob;
        // VendorInvalidAtCredentials; auto-correção ATCUD em CreateInvoiceJob). Um
        // ->throw() incondicional disparava também em 4xx e tornava esses ramos
        // inalcançáveis. Estas chamadas correm em jobs, não no checkout do cliente.
        $response = Http::timeout(15)->connectTimeout(5)->send($method, $fullUrl, $requestConfig);

        if ($response->serverError()) {
            $response->throw();
        }

        if ($this->isEmptyResponse($response)) {
            return ['success' => true];
        }

        $this->logRequestEnd($method, $url, $this->responseContext($response), $startTime);

        return $response->json();
    }

    private function isEmptyResponse(Response $response): bool
    {
        return $response->body() === ' ';
    }

    /**
     * @param array<string, mixed> $requestData
     */
    private function logRequestStart(string $method, string $url, array $requestData): void
    {
        if ($this->shouldLog()) {
            Log::channel('requests')->info('InvoiceXpress request started', [
                'method' => $method,
                'endpoint' => $url,
                'request' => $requestData,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    private function logRequestEnd(string $method, string $url, array $response, float $startTime): void
    {
        if ($this->shouldLog()) {
            Log::channel('requests')->info('InvoiceXpress request finished', [
                'method' => $method,
                'endpoint' => $url,
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                ...$response,
            ]);
        }
    }

    private function logError(string $method, string $url, ClientException $e): void
    {
        if ($this->shouldLog()) {
            Log::channel('requests')->error('InvoiceXpress request failed', [
                'method' => $method,
                'endpoint' => $url,
                'exception' => $e,
            ]);
        }
    }

    private function shouldLog(): bool
    {
        return array_key_exists('requests', config('logging.channels'));
    }

    /**
     * @return array<string, mixed>
     */
    private function responseContext(Response $response): array
    {
        $body = $response->json();

        return [
            'status' => $response->status(),
            'response' => is_array($body) ? $body : $response->body(),
        ];
    }

    /**
     * Build the full URL for the request, including query parameters.
     *
     * @param array<string, mixed> $urlParams
     */
    private function buildFullUrl(string $url, array $urlParams): string
    {
        $baseApiUrl = sprintf(
            '%s://%s.%s',
            config('services.invoiceExpress.protocol'),
            $this->workspace,
            config('services.invoiceExpress.base_url')
        );

        $queryParams = array_merge(['api_key' => $this->apiKey], $urlParams);
        $queryString = http_build_query($queryParams);

        return $baseApiUrl . $url . '?' . $queryString;
    }
}
