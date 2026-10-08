<?php

namespace App\Services\Operacoes;

use App\Models\ServiceEvent;

/**
 * Escreve os passos de cada pedido em `service_events`.
 *
 * Quem chama são os observadores (ServiceObserver, ServiceCandidateObserver),
 * para apanhar todos os caminhos — a app do cliente, a do técnico, o
 * `matching:advance`, o backoffice e o Filament — sem tocar em nenhum.
 *
 * NUNCA falha para fora. Um registo de histórico que não se grava é uma
 * linha a menos numa cronologia; um registo que rebenta é um pedido que não
 * avança. Fica reportado e segue.
 */
final class RegistoDeEventos
{
    public const CRIADO = 'criado';

    public const ESTADO = 'estado';

    public const CONVIDADO = 'convidado';

    public const RESPOSTA = 'resposta';

    public const A_CAMINHO = 'a_caminho';

    /** @param  array<string, mixed>|null  $dados */
    public static function registar(
        int $servicoId,
        string $tipo,
        ?string $de = null,
        ?string $para = null,
        ?int $vendorId = null,
        ?array $dados = null,
    ): void {
        try {
            ServiceEvent::create([
                'service_id' => $servicoId,
                'tipo' => $tipo,
                'estado_de' => $de,
                'estado_para' => $para,
                'vendor_id' => $vendorId,
                'dados' => $dados,
                'ocorreu_em' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
