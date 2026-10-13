<?php

namespace App\Services\InvoiceXpress;

use App\Exceptions\Api\Vendor\Invoicing\VendorInvalidAtCredentials;
use App\Exceptions\Api\Vendor\VendorDuplicatedSequence;
use App\Jobs\Services\EmitirFaturaEmAtrasoJob;
use App\Models\Service;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emite as faturas que ficaram por emitir quando o serviço fechou.
 *
 * A fatura de um serviço só é tentada uma vez, ao fechar (ServiceObserver ->
 * CreateInvoiceJob). Desde 30/09/2026 o técnico pode fazer os três primeiros
 * serviços sem a AT, e a partir de 13/10 também sem IBAN e morada fiscal. Nesses
 * casos a fatura falhava e ninguém voltava a tentar:
 *
 *  - sem conta de faturação, não chegava a existir (`invoice_id` a null);
 *  - com conta mas sem AT, ficava em RASCUNHO, sem ATCUD (`invoice_id` sem PDF).
 *
 * Isto emite-as quando o técnico passa a ter o que é preciso: a conta de
 * faturação e a AT validada. Decisão do André (13/10/2026, "solução 1").
 *
 * A DATA: uma fatura nova sai com a data do dia em que é emitida, e a data em
 * que o serviço foi feito vai na descrição. Um rascunho é só finalizado — fica
 * com a data com que foi criado. Por validar com o contabilista.
 *
 * NUNCA DUAS FATURAS PARA O MESMO SERVIÇO: um rascunho é finalizado, nunca
 * substituído; só se cria uma fatura nova para quem não tem `invoice_id`; e
 * cada serviço é trancado enquanto é tratado, para a tarefa diária e o botão do
 * backoffice não se cruzarem.
 */
class FaturasEmAtraso
{
    /**
     * Estados de uma fatura que já saiu. Uma fatura-recibo finalizada fica
     * `settled`; `final` fica por segurança, para o caso de não ser recibo.
     */
    private const ESTADOS_DE_EMITIDA = ['final', 'settled'];

    /** O técnico já tem o que a fatura precisa: a conta de faturação e a AT validada. */
    public static function podeEmitir(Vendor $vendor): bool
    {
        return (bool) $vendor->invoice_workspace && $vendor->at_ready;
    }

    /** Os serviços deste técnico com a fatura por emitir, dos mais antigos para os mais recentes. */
    public static function doTecnico(Vendor $vendor): Collection
    {
        return Service::query()
            ->where('vendor_id', $vendor->id)
            ->porFaturar()
            ->orderBy('id')
            ->get();
    }

    /**
     * Põe na fila as faturas por emitir deste técnico, uma tarefa por serviço,
     * em cadeia: do mais antigo para o mais recente, uma de cada vez, como o
     * InvoiceXpress as numera. Devolve quantas foram para a fila.
     */
    public static function agendar(Vendor $vendor): int
    {
        if (! self::podeEmitir($vendor)) {
            return 0;
        }

        $tarefas = self::doTecnico($vendor)
            ->map(fn (Service $service) => new EmitirFaturaEmAtrasoJob($service))
            ->values()
            ->all();

        if ($tarefas !== []) {
            Bus::chain($tarefas)->dispatch();
        }

        return count($tarefas);
    }

    /**
     * @return string 'emitida' | 'ja_emitida' | 'sem_dados' | 'ocupada' | 'falhou'
     */
    public function emitir(Service $service): string
    {
        $tranca = Cache::lock('fatura-em-atraso:'.$service->id, 600);

        if (! $tranca->get()) {
            return 'ocupada';
        }

        try {
            $service->refresh();

            if ($service->temFaturaEmitida()) {
                return 'ja_emitida';
            }

            $vendor = $service->vendor;

            if (! $vendor || ! self::podeEmitir($vendor)) {
                return 'sem_dados';
            }

            $faturas = app(InvoiceVendorService::class, ['vendor' => $vendor]);
            $invoiceId = (int) $service->invoice_id;

            if ($invoiceId) {
                // Já há documento. Um rascunho finaliza-se: criar outra fatura
                // deixava duas para o mesmo serviço no InvoiceXpress.
                $estado = $faturas->estadoDaFatura($invoiceId);

                // Finalizado numa tentativa que não chegou a guardar o PDF (o
                // downloadPdf desiste à sexta). Finalizá-lo outra vez dava erro:
                // o InvoiceXpress só aceita as mudanças de estado previstas.
                if (in_array($estado, self::ESTADOS_DE_EMITIDA, true)) {
                    $faturas->downloadPdf($invoiceId, $service);

                    return 'emitida';
                }

                if ($estado !== 'draft') {
                    Log::warning('[faturas em atraso] o documento não é rascunho nem fatura emitida', [
                        'service_id' => $service->id,
                        'invoice_id' => $invoiceId,
                        'estado' => $estado,
                    ]);

                    return 'falhou';
                }
            } else {
                $invoiceId = (int) $faturas->createServiceInvoice($service, now());
            }

            $resposta = $faturas->finalizeInvoice($invoiceId);

            // A conta ainda não tem a série comunicada à AT. É o mesmo
            // remédio do CreateInvoiceJob: criar a série e tentar outra vez.
            if ($this->semAtcud($resposta)) {
                try {
                    $faturas->createSequence();
                } catch (VendorDuplicatedSequence) {
                    // Já existia: segue.
                }

                $resposta = $faturas->finalizeInvoice($invoiceId);
            }

            if (! empty($resposta['errors'])) {
                Log::warning('[faturas em atraso] o InvoiceXpress recusou finalizar', [
                    'service_id' => $service->id,
                    'invoice_id' => $invoiceId,
                    'erro' => $resposta['errors'][0]['error'] ?? $resposta['errors'],
                ]);

                return 'falhou';
            }

            $faturas->downloadPdf($invoiceId, $service);

            return 'emitida';
        } catch (VendorInvalidAtCredentials) {
            // O createSequence já pôs `at_valid` a false: o técnico vê na app
            // que tem de voltar a dar o acesso, e os serviços seguintes deste
            // técnico param no `sem_dados`.
            Log::warning('[faturas em atraso] credenciais da AT recusadas', ['service_id' => $service->id]);

            return 'falhou';
        } catch (Throwable $e) {
            report($e);

            return 'falhou';
        } finally {
            $tranca->release();
        }
    }

    private function semAtcud(array $resposta): bool
    {
        return str_contains($resposta['errors'][0]['error'] ?? '', 'Este documento não tem ATCUD');
    }
}
