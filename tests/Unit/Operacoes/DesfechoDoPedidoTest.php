<?php

namespace Tests\Unit\Operacoes;

use App\Enums\Services\ServiceStatus;
use App\Services\Operacoes\DesfechoDoPedido as D;
use PHPUnit\Framework\TestCase;

/** "MatchingFailed" são três histórias diferentes; o resto também tem de cair num sítio só. */
class DesfechoDoPedidoTest extends TestCase
{
    public function test_matching_failed_separa_falta_de_oferta_silencio_e_desistencia(): void
    {
        $this->assertSame(D::SEM_OFERTA, D::de(ServiceStatus::MATCHING_FAILED, false, 0, false));
        $this->assertSame(D::SEM_RESPOSTA, D::de(ServiceStatus::MATCHING_FAILED, false, 5, false));
        $this->assertSame(D::SEM_ESCOLHA, D::de(ServiceStatus::MATCHING_FAILED, false, 5, true));
    }

    public function test_com_tecnico_e_servido_em_qualquer_fase(): void
    {
        foreach ([ServiceStatus::ACCEPTED, ServiceStatus::SCHEDULED, ServiceStatus::ARRIVED, ServiceStatus::FINISHED, ServiceStatus::CLOSED, ServiceStatus::CLOSED_PENDING_PAYMENT] as $e) {
            $this->assertSame(D::SERVIDO, D::de($e, true, 3, true), $e->value);
        }
    }

    public function test_arquivado_so_e_servido_se_chegou_a_ter_tecnico(): void
    {
        $this->assertSame(D::SERVIDO, D::de(ServiceStatus::ARCHIVED, true, 1, true));
        $this->assertSame(D::OUTRO, D::de(ServiceStatus::ARCHIVED, false, 0, false));
    }

    public function test_pagamentos_que_falharam_e_cancelamentos(): void
    {
        foreach ([ServiceStatus::REFUSED_MBWAY, ServiceStatus::EXPIRED_MBWAY, ServiceStatus::CANCELED_MBWAY, ServiceStatus::EXPIRED_3DS] as $e) {
            $this->assertSame(D::PAGAMENTO_FALHOU, D::de($e, false, 0, false), $e->value);
        }
        $this->assertSame(D::CANCELADO, D::de(ServiceStatus::CANCELED, true, 2, true));
        $this->assertSame(D::CANCELADO, D::de(ServiceStatus::REFUSED, false, 2, false));
    }

    public function test_os_abertos_ainda_nao_tem_desfecho(): void
    {
        foreach (D::ABERTOS as $e) {
            $this->assertSame(D::EM_ABERTO, D::de($e, false, 2, true), $e->value);
        }
    }

    /** Cada estado do enum cai num desfecho conhecido — um estado novo não se perde em silêncio. */
    public function test_todos_os_estados_tem_desfecho(): void
    {
        $conhecidos = [D::EM_ABERTO, D::SERVIDO, D::SEM_OFERTA, D::SEM_RESPOSTA, D::SEM_ESCOLHA, D::PAGAMENTO_FALHOU, D::CANCELADO, D::OUTRO];
        foreach (ServiceStatus::cases() as $e) {
            $this->assertContains(D::de($e, false, 0, false), $conhecidos, $e->value);
        }
    }
}
