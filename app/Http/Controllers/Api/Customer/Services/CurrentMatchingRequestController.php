<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Services\Matching\MatchingService;

/**
 * O pedido que está à espera do cliente, para a Home poder mostrá-lo.
 *
 * Sem isto, um pedido em seleção só era alcançável pela notificação: quem a
 * descartasse, a fechasse sem tocar, ou tivesse as notificações recusadas,
 * ficava com propostas à espera e sem caminho nenhum de volta — e com um
 * relógio a correr. Nem o cartão da Home (Finished/Accepted/Arrived) nem o
 * separador Serviços (agendamentos e histórico) cobriam estes estados.
 *
 * Endpoint próprio e não mais um estado no `CheckHasAnyServiceOpenController`:
 * aquele alimenta o cartão do serviço a decorrer, com técnico, morada e
 * contagem — coisas que um pedido em seleção ainda não tem.
 */
class CurrentMatchingRequestController extends Controller
{
    public function __construct(private readonly MatchingService $matching) {}

    public function __invoke()
    {
        $service = auth()->user()->services()
            ->whereIn('status', [
                ServiceStatus::PENDING_REVIEW,
                ServiceStatus::MATCHING,
                ServiceStatus::AWAITING_PAYMENT,
            ])
            ->latest('id')
            ->first();

        if (! $service) {
            return new ApiSuccessResponse(['request' => null]);
        }

        $language = auth()->user()->language ?? 'pt-pt';

        return new ApiSuccessResponse(['request' => [
            'id' => $service->id,
            'status' => $service->status,
            'is_custom' => (bool) $service->is_custom,
            // O que o cliente pediu, para o cartão dizer de que serviço se
            // trata. Num personalizado é a descrição que ele próprio escreveu.
            'title' => $service->is_custom
                ? $service->custom_description
                : $service->serviceType?->getTranslation('name', $language),
            // Quantos já se disponibilizaram. Zero é informação legítima: em
            // análise, ou ainda à espera da primeira resposta.
            'candidates_ready' => $this->countReady($service),
        ]]);
    }

    private function countReady(Service $service): int
    {
        if ($service->status !== ServiceStatus::MATCHING) {
            return 0;
        }

        return $this->matching->selectableFor($service)->count();
    }
}
