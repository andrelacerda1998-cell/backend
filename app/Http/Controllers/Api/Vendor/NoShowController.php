<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Services\Common\Services\VendorNoShowPolicy;
use Exception;
use Illuminate\Http\Request;

/**
 * As faltas do próprio técnico e a contestação de cada uma.
 *
 * Uma penalização que só aparece numa notificação é uma penalização que ele não
 * consegue rever nem discutir: passada a notificação, fica um débito na carteira
 * sem explicação. Aqui ele vê quais foram, quanto custou cada uma, e tem por
 * onde dizer que houve engano — que acontece (o cliente não estava em casa, a
 * morada estava errada).
 */
class NoShowController extends Controller
{
    /** Faltas registadas a este técnico, mais recentes primeiro. */
    public function index(Request $request): ApiSuccessResponse
    {
        $vendorId = $request->user()->vendor?->id;

        $services = Service::query()
            ->where('vendor_id', $vendorId)
            ->whereNotNull('vendor_no_show_at')
            ->with('serviceType')
            ->orderByDesc('vendor_no_show_at')
            ->limit(50)
            ->get();

        $language = app()->getLocale();

        return new ApiSuccessResponse([
            'no_shows' => $services->map(fn (Service $service) => [
                'service_id' => $service->id,
                'service_type' => $service->serviceType?->getTranslation('name', $language),
                'scheduled_day' => $service->schedule?->scheduled_day,
                'scheduled_time' => $service->schedule?->scheduled_time_start,
                // Em cêntimos, como o resto do dinheiro na API.
                'penalty' => (int) ($service->vendor_no_show_penalty ?? 0),
                'registered_at' => $service->vendor_no_show_at?->toIso8601String(),
                'disputed' => $this->disputeFor($request, $service->id) !== null,
            ]),
            // Para a app poder explicar a regra sem a ter escrita em duplicado.
            'penalty_ratio' => VendorNoShowPolicy::PENALTY_RATIO,
        ]);
    }

    /**
     * Contestar uma falta.
     *
     * Abre um ticket de suporte em vez de reverter seja o que for: quem decide
     * se houve engano é uma pessoa, com o mesmo cuidado com que declarou a
     * falta. A devolução, se for caso disso, é feita pela operação.
     */
    public function dispute(Request $request, Service $service): ApiSuccessResponse|ApiErrorResponse
    {
        $vendor = $request->user()->vendor;

        // Só as suas, e só as que existem: sem isto um técnico contestava a
        // falta de outro (mesma proteção do confirm-attendance).
        if (! $vendor || $service->vendor_id !== $vendor->id || $service->vendor_no_show_at === null) {
            return new ApiErrorResponse(new Exception, 'No-show not found', 404);
        }

        if ($this->disputeFor($request, $service->id) !== null) {
            return new ApiErrorResponse(new Exception, 'Already disputed', 409);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $ticket = $vendor->supportTickets()->create([
            // O assunto leva o id do serviço para o backoffice ligar o ticket à
            // falta sem ter de o perguntar, e é por ele que se sabe que já foi
            // contestada.
            'subject' => $this->subjectFor($service->id),
            'message' => $validated['message'],
            'status' => 'open',
        ]);

        return new ApiSuccessResponse([
            'ticket' => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'created_at' => $ticket->created_at?->toIso8601String(),
            ],
        ]);
    }

    private function subjectFor(int $serviceId): string
    {
        return "Contestação de falta — serviço #{$serviceId}";
    }

    /** O ticket de contestação deste serviço, se já existir. */
    private function disputeFor(Request $request, int $serviceId): ?int
    {
        return $request->user()->vendor?->supportTickets()
            ->where('subject', $this->subjectFor($serviceId))
            ->value('id');
    }
}
