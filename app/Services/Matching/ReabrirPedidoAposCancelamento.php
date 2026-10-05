<?php

namespace App\Services\Matching;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Models\ServiceCandidate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Quando o técnico cancela, o pedido do cliente volta a procurar outro.
 *
 * Antes o cliente ficava com um "serviço cancelado", o dinheiro devolvido e
 * mais nada: tinha de recomeçar do zero, e muitas vezes não sabia sequer que
 * o técnico tinha largado. A procura que já estava ganha perdia-se.
 *
 * O que se reabre é um pedido NOVO, em seleção, com o mesmo serviço, morada,
 * notas e (se ainda fizer sentido) a mesma hora. O cliente volta a escolher e
 * a pagar — o preço depende de quem aceitar, e o pagamento anterior já foi
 * libertado. O técnico que cancelou fica de fora (`EXCLUDED`).
 */
class ReabrirPedidoAposCancelamento
{
    /**
     * Um agendado mantém a hora se ainda faltar pelo menos isto. Abaixo disso
     * não há tempo para outro técnico se organizar, e procura-se para já.
     */
    public const MINUTOS_PARA_MANTER_A_HORA = 120;

    public function __construct(private MatchingService $matching) {}

    /**
     * @param  array{scheduled_day: ?string, scheduled_time_start: ?string}|null  $intencao
     *         O dia e a hora do serviço cancelado, lidos ANTES de cancelar — o
     *         cancelamento apaga a linha de agenda.
     */
    public function handle(Service $cancelado, ?array $intencao): ?Service
    {
        if ($cancelado->status !== ServiceStatus::CANCELED || ! $cancelado->vendor_canceled_at) {
            return null;
        }

        if (! $cancelado->services_type_id && ! $cancelado->is_custom) {
            return null;
        }

        // Já há outro pedido dele em seleção: não se abre um segundo por cima.
        $outroAberto = Service::query()
            ->where('customer_id', $cancelado->customer_id)
            ->whereIn('status', [ServiceStatus::MATCHING, ServiceStatus::AWAITING_PAYMENT])
            ->exists();

        if ($outroAberto) {
            return null;
        }

        $agenda = $this->agendaQueAindaServe($intencao);

        $novo = DB::transaction(function () use ($cancelado, $agenda) {
            $novo = new Service([
                'customer_id' => $cancelado->customer_id,
                'vendor_id' => null,
                'services_type_id' => $cancelado->services_type_id,
                'quantity' => $cancelado->quantity,
                'status' => ServiceStatus::MATCHING,
                'is_test' => (bool) $cancelado->is_test,
                'customer_notes' => $cancelado->customer_notes,
                'address' => $cancelado->address,
                'is_custom' => (bool) $cancelado->is_custom,
                'custom_description' => $cancelado->custom_description,
                'custom_duration_minutes' => $cancelado->custom_duration_minutes,
                // Num personalizado o prazo da procura conta do envio; este
                // já foi analisado, por isso o envio é agora.
                'custom_dispatched_at' => $cancelado->is_custom ? now() : null,
            ]);
            $novo->payment_status = PaymentStatus::PENDING;
            $novo->pending_schedule_data = $agenda;
            $novo->save();

            if ($cancelado->is_custom) {
                $novo->operationAreas()->sync($cancelado->operationAreas()->pluck('operation_areas.id'));
            }

            if ($cancelado->vendor_id) {
                ServiceCandidate::create([
                    'service_id' => $novo->id,
                    'vendor_id' => $cancelado->vendor_id,
                    'rank' => 0,
                    'wave' => 0,
                    'status' => CandidateStatus::EXCLUDED,
                ]);
            }

            return $novo;
        });

        if ($this->matching->dispatchNextWave($novo)->isEmpty()) {
            // Ninguém para convidar: o cliente recebe o "tenta outra vez" de
            // sempre, em vez de um pedido parado à espera de nada.
            $this->matching->fail($novo);
        }

        return $novo->refresh();
    }

    private function agendaQueAindaServe(?array $intencao): ?array
    {
        $dia = $intencao['scheduled_day'] ?? null;
        $hora = $intencao['scheduled_time_start'] ?? null;

        if (! $dia || ! $hora) {
            return null;
        }

        $inicio = str_contains((string) $hora, '-')
            ? Carbon::parse($hora)
            : Carbon::parse($dia)->setTimeFrom(Carbon::parse($hora));

        if ($inicio->lt(now()->addMinutes(self::MINUTOS_PARA_MANTER_A_HORA))) {
            return null;
        }

        return [
            'scheduled' => true,
            'schedule' => [
                'scheduled_day' => Carbon::parse($dia)->toDateString(),
                'scheduled_time_start' => $inicio->format('H:i'),
            ],
        ];
    }
}
