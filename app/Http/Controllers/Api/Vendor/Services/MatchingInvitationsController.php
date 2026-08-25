<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Enums\Services\CandidateStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\ServiceCandidate;
use App\Services\Matching\MatchingService;
use Exception;

/**
 * Convites de seleção de profissional — ver docs/matching.md.
 *
 * Aceitar aqui NÃO é o mesmo que aceitar um serviço no fluxo antigo. É dizer
 * "estou disponível e interessado": não reserva a agenda e não garante o
 * trabalho. Quem decide é o cliente. Por isso o profissional pode responder a
 * dois pedidos em paralelo sem se prejudicar.
 */
class MatchingInvitationsController extends Controller
{
    public function __construct(private MatchingService $matching)
    {
    }

    /** Convites com a janela ainda aberta. */
    public function index(): ApiSuccessResponse
    {
        $vendorId = auth()->user()->vendor->id;

        $invitations = ServiceCandidate::query()
            ->where('vendor_id', $vendorId)
            ->where('status', CandidateStatus::NOTIFIED)
            // A expiração é avaliada por leitura: um convite cuja janela fechou
            // não deve aparecer, mesmo que ninguém o tenha marcado ainda.
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with(['service.serviceType', 'service.schedule'])
            ->orderBy('expires_at')
            ->get();

        return new ApiSuccessResponse(
            $invitations->map(fn (ServiceCandidate $c) => $this->payload($c))->values()
        );
    }

    public function accept(ServiceCandidate $candidate): ApiSuccessResponse|ApiErrorResponse
    {
        if (! $this->owns($candidate)) {
            return new ApiErrorResponse(new Exception, 'Invitation not found', 404);
        }

        if (! $this->matching->accept($candidate)) {
            // Chegou tarde, ou a janela fechou. Dizer qual dos dois foi é o que
            // separa "o sistema está partido" de "outro foi mais rápido".
            return new ApiErrorResponse(
                new Exception,
                $candidate->refresh()->hasExpired()
                    ? 'This invitation has expired'
                    : 'This request has already been filled',
                409
            );
        }

        return new ApiSuccessResponse($this->payload($candidate->refresh()));
    }

    public function decline(ServiceCandidate $candidate): ApiSuccessResponse|ApiErrorResponse
    {
        if (! $this->owns($candidate)) {
            return new ApiErrorResponse(new Exception, 'Invitation not found', 404);
        }

        $this->matching->decline($candidate);

        return new ApiSuccessResponse;
    }

    private function owns(ServiceCandidate $candidate): bool
    {
        return $candidate->vendor_id === auth()->user()->vendor?->id;
    }

    /**
     * Dia e hora pretendidos, venham da agenda já materializada ou da intenção
     * guardada enquanto ela não pode existir.
     */
    private function scheduleFor(?\App\Models\Service $service): ?array
    {
        if (! $service) {
            return null;
        }

        if ($service->schedule) {
            return [
                'scheduled_day' => $service->schedule->scheduled_day,
                'scheduled_time_start' => $service->schedule->scheduled_time_start,
            ];
        }

        $pending = $service->pending_schedule_data['schedule'] ?? null;

        if (! ($service->pending_schedule_data['scheduled'] ?? false) || ! $pending) {
            return null;
        }

        return [
            'scheduled_day' => $pending['scheduled_day'] ?? null,
            'scheduled_time_start' => $pending['scheduled_time_start'] ?? null,
        ];
    }

    private function payload(ServiceCandidate $candidate): array
    {
        $service = $candidate->service;

        return [
            'candidate_id' => $candidate->id,
            'service_id' => $candidate->service_id,
            'status' => $candidate->status,
            // O que ele recebe, congelado. Nunca o que o cliente paga: o modelo
            // é margem por cima, e o técnico recebe 100% do que definiu.
            'amount_for_vendor' => $candidate->quoted_amount_for_vendor,
            'distance' => (float) $candidate->quoted_distance,
            // A janela é visível de propósito: sem saber quando fica livre, o
            // profissional fica pendurado e deixa de responder.
            'expires_at' => $candidate->expires_at?->toIso8601String(),
            'service_type' => $service?->serviceType ? [
                'id' => $service->serviceType->id,
                'name' => $service->serviceType->getTranslation('name', auth()->user()->language ?? 'pt-pt'),
                'time' => $service->serviceType->time,
            ] : null,
            'address' => $service?->address ? [
                'city' => $service->address['city'] ?? null,
                'postal_code' => $service->address['postal_code'] ?? null,
            ] : null,
            // Durante a seleção NÃO existe linha de agenda: schedule.vendor_id é
            // NOT NULL e ainda não há profissional atribuído. A intenção vive em
            // pending_schedule_data até ao pagamento (ver docs/matching.md).
            //
            // Ler só $service->schedule fazia o convite de um agendamento chegar
            // sem data nenhuma — e a data é o que decide se ele pode ou não.
            'schedule' => $this->scheduleFor($service),
        ];
    }
}
