<?php

namespace App\Services\Common\Services;

use App\Models\Service;
use App\Models\User;
use App\Notifications\Admin\ServiceProblemOpsNotification;
use App\Notifications\Vendor\ServiceProblemReportedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Alguém diz que algo correu mal num serviço.
 *
 * Enquanto houver um problema reportado, o fecho automático não cobra nada
 * (ver Service::autoCloseAt) — decide uma pessoa da Piquet, avisada por email.
 *
 * Partilhado entre o cliente ("Reportar um problema") e o técnico ("Cliente
 * não está"): o efeito é o mesmo, e as duas entradas não podem divergir.
 */
class ReportarProblema
{
    /**
     * @return bool true se é o primeiro relato (e houve avisos); false se só
     *              acrescentou ao que já existia.
     */
    public function handle(Service $service, string $por, string $motivo, ?string $mensagem): bool
    {
        $primeiro = $service->problem_reported_at === null;

        $service->forceFill([
            'problem_reported_at' => $service->problem_reported_at ?? now(),
            'problem_reported_by' => $service->problem_reported_by ?? $por,
            'problem_reason' => $primeiro ? $motivo : $service->problem_reason,
            'problem_message' => trim(implode("\n\n", array_filter([
                $service->problem_message,
                $mensagem ? trim($mensagem) : null,
            ]))) ?: null,
        ])->save();

        if (! $primeiro) {
            return false;
        }

        try {
            $admins = User::query()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'super-admin']))
                ->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new ServiceProblemOpsNotification($service));
            }

            // Ao técnico, só quando é o cliente a reportar: quando é ele, já sabe.
            if ($por === 'customer') {
                $service->vendor?->user?->notify(new ServiceProblemReportedNotification($service));
            }
        } catch (\Throwable $e) {
            // Um aviso que falha não desfaz o relato: o fecho automático já parou.
            report($e);
        }

        return true;
    }
}
