<?php

namespace App\Services\Operacoes;

use App\Enums\Services\ServiceStatus;

/**
 * Como acabou um pedido — uma resposta só, para as contas da liquidez.
 *
 * Os estados do serviço dizem onde ele parou, não PORQUÊ. "MatchingFailed"
 * junta três histórias muito diferentes: não havia ninguém para convidar
 * (falta oferta), convidou-se e ninguém respondeu (oferta adormecida), ou
 * alguém aceitou e o cliente não escolheu ou não pagou. Cada uma pede uma
 * resposta diferente, e o diagnóstico de 06/10 só as separou à mão.
 */
final class DesfechoDoPedido
{
    public const EM_ABERTO = 'em_aberto';

    public const SERVIDO = 'servido';

    public const SEM_OFERTA = 'sem_oferta';

    public const SEM_RESPOSTA = 'sem_resposta';

    public const SEM_ESCOLHA = 'sem_escolha';

    public const PAGAMENTO_FALHOU = 'pagamento_falhou';

    public const CANCELADO = 'cancelado';

    public const OUTRO = 'outro';

    /** Pedidos à procura de técnico ou à espera do cliente: ainda sem desfecho. */
    public const ABERTOS = [
        ServiceStatus::PENDING,
        ServiceStatus::MATCHING,
        ServiceStatus::PENDING_REVIEW,
        ServiceStatus::AWAITING_PAYMENT,
        ServiceStatus::PENDING_3DS,
    ];

    /** Houve técnico e o trabalho seguiu (ou já acabou). */
    public const COM_TECNICO = [
        ServiceStatus::ACCEPTED,
        ServiceStatus::SCHEDULED,
        ServiceStatus::ARRIVED,
        ServiceStatus::FINISHED,
        ServiceStatus::CLOSED,
        ServiceStatus::CLOSED_PENDING_PAYMENT,
    ];

    public const PAGAMENTO = [
        ServiceStatus::REFUSED_MBWAY,
        ServiceStatus::EXPIRED_MBWAY,
        ServiceStatus::CANCELED_MBWAY,
        ServiceStatus::EXPIRED_3DS,
    ];

    /**
     * @param  int  $convidados  quantos técnicos receberam convite
     * @param  bool  $algumSim  alguém aceitou (accepted, selected ou lost)
     */
    public static function de(ServiceStatus $estado, bool $temTecnico, int $convidados, bool $algumSim): string
    {
        if (in_array($estado, self::ABERTOS, true)) {
            return self::EM_ABERTO;
        }
        if (in_array($estado, self::COM_TECNICO, true)) {
            return self::SERVIDO;
        }
        // Arquivar é arrumar: conta como servido se chegou a ter técnico.
        if ($estado === ServiceStatus::ARCHIVED) {
            return $temTecnico ? self::SERVIDO : self::OUTRO;
        }
        if (in_array($estado, self::PAGAMENTO, true)) {
            return self::PAGAMENTO_FALHOU;
        }
        if ($estado === ServiceStatus::CANCELED || $estado === ServiceStatus::REFUSED) {
            return self::CANCELADO;
        }
        if ($estado === ServiceStatus::MATCHING_FAILED) {
            return match (true) {
                $convidados === 0 => self::SEM_OFERTA,
                ! $algumSim => self::SEM_RESPOSTA,
                default => self::SEM_ESCOLHA,
            };
        }

        return self::OUTRO;
    }
}
