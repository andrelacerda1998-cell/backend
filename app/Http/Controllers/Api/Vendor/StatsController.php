<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StatsController extends Controller
{
    /**
     * Resumo do vendor: métricas do perfil (avaliação, serviços, taxa de aceitação)
     * e ganhos (semana atual, últimas 4 semanas, próximo pagamento, serviços da semana).
     */
    public function __invoke(Request $request)
    {
        $vendor = $request->user()->vendor;

        $startOfWeek = Carbon::now()->startOfWeek();

        $closed = fn () => $vendor->services()->where('status', ServiceStatus::CLOSED);
        $thisWeek = fn () => $closed()->where('updated_at', '>=', $startOfWeek);

        // Avaliação média dada pelos clientes
        $ratingAvg = $vendor->services()
            ->whereNotNull('rating_by_customer')
            ->avg('rating_by_customer');

        // Taxa de aceitação: dos serviços que lhe chegaram, quantos não recusou.
        $assigned = $vendor->services()
            ->whereIn('status', [
                ServiceStatus::CLOSED,
                ServiceStatus::ACCEPTED,
                ServiceStatus::ARRIVED,
                ServiceStatus::FINISHED,
                ServiceStatus::REFUSED,
            ])->count();
        $refused = $vendor->services()->where('status', ServiceStatus::REFUSED)->count();
        $acceptanceRate = $assigned > 0 ? (int) round((($assigned - $refused) / $assigned) * 100) : null;

        // Últimas 4 semanas (da mais recente para a mais antiga), incluindo a atual.
        $weeks = [];
        for ($i = 0; $i < 4; $i++) {
            $ws = Carbon::now()->startOfWeek()->subWeeks($i);
            $we = (clone $ws)->endOfWeek();
            $q = $closed()->whereBetween('updated_at', [$ws, $we]);
            $weeks[] = [
                'week_start' => $ws->toDateString(),
                'week_end' => $we->toDateString(),
                'earnings' => (int) (clone $q)->sum('amount_for_vendor'),
                'services' => (clone $q)->count(),
            ];
        }

        // Serviços concluídos esta semana (detalhe para o ecrã de Ganhos)
        $completedThisWeek = $thisWeek()
            ->with('serviceType')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'service_type' => $s->serviceType?->name,
                'amount_for_vendor' => (int) $s->amount_for_vendor,
                'completed_at' => $s->updated_at?->toIso8601String(),
            ]);

        return new ApiSuccessResponse([
            // valores em cêntimos — a app usa renderMoney (÷100)
            'this_week_earnings' => (int) $thisWeek()->sum('amount_for_vendor'),
            'this_week_services' => $thisWeek()->count(),
            'total_services' => $closed()->count(),
            // Total GANHO em serviços fechados. Mantido com o nome antigo para não
            // partir versões da app já publicadas, mas deixou de ser apresentado
            // como "já pago" — não é o que foi transferido para o banco.
            'total_paid' => (int) $closed()->sum('amount_for_vendor'),
            'total_earned' => (int) $closed()->sum('amount_for_vendor'),
            // O que saiu MESMO para a conta bancária do técnico: transações de
            // levantamento confirmadas na carteira.
            'total_transferred' => (int) abs(
                $vendor->transactions()
                    ->where('type', 'withdraw')
                    ->where('confirmed', true)
                    ->sum('amount')
            ),
            // Serviços já feitos que ainda não foram pagos ao técnico.
            'pending_payment_amount' => (int) $vendor->services()
                ->whereIn('status', [ServiceStatus::FINISHED, ServiceStatus::CLOSED_PENDING_PAYMENT])
                ->sum('amount_for_vendor'),
            'pending_payment_count' => (int) $vendor->services()
                ->whereIn('status', [ServiceStatus::FINISHED, ServiceStatus::CLOSED_PENDING_PAYMENT])
                ->count(),
            'rating' => $ratingAvg !== null ? round((float) $ratingAvg, 1) : null,
            'acceptance_rate' => $acceptanceRate,
            'last_weeks' => $weeks,
            'last_4_weeks_earnings' => (int) collect($weeks)->sum('earnings'),
            // pagamentos são sempre às segundas-feiras
            'next_payment_date' => Carbon::now()->next(Carbon::MONDAY)->toDateString(),
            'completed_this_week' => $completedThisWeek,
            'decimal_places' => 2,
        ]);
    }
}
