<?php

namespace App\Services\Common\Services;

use App\Enums\Services\ServiceStatus;
use App\Events\Common\Services\ServiceAcceptedEvent;
use App\Events\Common\Services\ServiceRefusedEvent;
use App\Exceptions\Api\Vendor\Service\ServiceIsNotPending;
use App\Models\Service;
use App\Notifications\Customer\ServiceAcceptedNotification;
use Illuminate\Support\Facades\Log;

class AcceptService
{
    public function accept(Service $service): Service
    {
        // Lock + re-check inside a transaction so a double-submit can't transition twice and fire the
        // "accepted" notification more than once.
        $aceite = \DB::transaction(function () use ($service): Service {
            $locked = Service::whereKey($service->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->status != ServiceStatus::PENDING) {
                throw new ServiceIsNotPending;
            }

            $service->status = ServiceStatus::ACCEPTED;
            $service->save();

            $this->notifyCustomer($service->formatDataForCustomer(), $service);

            return $service;
        });

        // FORA da transação de propósito: libertar os outros pedidos fala com o
        // Payshop (cancelar autorizações), e uma chamada de rede dentro de uma
        // transação aberta segura a linha da base de dados durante todo o
        // tempo de resposta do gateway.
        $this->libertarOsOutrosPedidosImediatos($aceite);

        return $aceite;
    }

    /**
     * Aceitar um pedido imediato liberta os outros pedidos imediatos do mesmo
     * profissional.
     *
     * PORQUÊ: um pedido imediato é "podes AGORA?". Tendo dois na mão e dizendo
     * que sim aos dois, ele comprometia-se a estar em dois sítios à mesma hora
     * -- e o segundo cliente só descobria quando ninguém aparecesse. Nada no
     * sistema o impedia: os dois estavam PENDING e os dois aceitavam.
     *
     * NÃO MEXE NOS AGENDADOS. Um trabalho marcado para quinta às 15h não
     * colide com um que começa agora, e cancelá-lo tirava-lhe trabalho que ele
     * podia mesmo fazer.
     *
     * Os pedidos libertados seguem o caminho da recusa (dinheiro devolvido ao
     * cliente, agendamento apagado, voucher libertado) com uma justificação
     * própria: não foi ele que recusou, foi a Piquet que o considerou ocupado.
     * Por isso também não conta na taxa de aceitação -- ver StatsController.
     */
    private function libertarOsOutrosPedidosImediatos(Service $aceite): void
    {
        // Só um pedido imediato ocupa o "agora". Aceitar um agendado não trava nada.
        if ($aceite->schedule()->exists()) {
            return;
        }

        $outros = Service::query()
            ->where('vendor_id', $aceite->vendor_id)
            ->where('status', ServiceStatus::PENDING)
            ->whereKeyNot($aceite->getKey())
            ->whereDoesntHave('schedule')
            ->get();

        foreach ($outros as $outro) {
            try {
                (new RefuseService($outro))->refuse(RefuseService::MOTIVO_OCUPADO);

                $outro->refresh();
                if ($outro->status === ServiceStatus::REFUSED) {
                    ServiceRefusedEvent::dispatch($outro->customer, $outro);
                }
            } catch (\Throwable $e) {
                // Uma falha a libertar o pedido B não pode desfazer o aceite do
                // pedido A, que já está feito e já foi comunicado ao cliente.
                // Fica o registo e o reaper (`services:expirar-pedidos-pendentes`)
                // apanha-o no minuto seguinte.
                Log::error('[aceitar] falhou a libertar o pedido #'.$outro->id, [
                    'aceite' => $aceite->id,
                    'erro' => $e->getMessage(),
                ]);
            }
        }
    }

    public function acceptSchedule($service)
    {
        if ($service->status != ServiceStatus::PENDING) {
            throw new ServiceIsNotPending;
        }

        $service->status = ServiceStatus::SCHEDULED;
        $service->save();
    }

    private function notifyCustomer(array $serviceData, Service $service): void
    {
        ServiceAcceptedEvent::dispatch($service->customer, $serviceData);
        $service->customer->notify(new ServiceAcceptedNotification($service));
    }
}
