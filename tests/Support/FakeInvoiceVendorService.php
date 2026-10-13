<?php

namespace Tests\Support;

use App\Exceptions\Api\Vendor\Invoicing\VendorInvalidAtCredentials;
use App\Models\Service;
use App\Models\Vendor;
use App\Services\InvoiceXpress\InvoiceVendorService;
use Carbon\Carbon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Duplo de teste do InvoiceVendorService: um InvoiceXpress em memória, sem
 * nenhuma chamada HTTP. Entra pelo contentor (`ligar()`), que é por onde a
 * FaturasEmAtraso o pede, e regista cada chamada em `$chamadas`.
 *
 * O PDF guarda-se como o verdadeiro guarda (coleção `invoices` do serviço),
 * porque é isso que diz se a fatura saiu.
 */
class FakeInvoiceVendorService extends InvoiceVendorService
{
    /** @var array<int, array{0: string, 1?: int}> Cada chamada: [passo, id]. */
    public array $chamadas = [];

    /** O estado de cada documento no "InvoiceXpress", por id. */
    public array $estados = [];

    /** A data com que cada fatura foi criada (Y-m-d), por id. */
    public array $datas = [];

    /** Respostas às próximas finalizações, por ordem. Sem resposta = finaliza. */
    public array $respostasAoFinalizar = [];

    public bool $credenciaisDaAtRecusadas = false;

    private int $proximoId = 9001;

    private ?Vendor $tecnico = null;

    public function __construct() {}

    public static function ligar(): self
    {
        $falso = new self;

        app()->bind(InvoiceVendorService::class, function ($app, array $parametros) use ($falso) {
            $falso->tecnico = $parametros['vendor'];

            return $falso;
        });

        return $falso;
    }

    /** Um documento que já existe no InvoiceXpress (rascunho, finalizado, anulado...). */
    public function jaExiste(int $invoiceId, string $estado): void
    {
        $this->estados[$invoiceId] = $estado;
    }

    /** Só os passos, por ordem: ['criar', 'finalizar', 'pdf']. */
    public function passos(): array
    {
        return array_map(fn (array $chamada) => $chamada[0], $this->chamadas);
    }

    public function createServiceInvoice(Service $service, ?Carbon $emitidaEm = null)
    {
        $id = $this->proximoId++;

        $this->chamadas[] = ['criar', $id];
        $this->estados[$id] = 'draft';
        $this->datas[$id] = ($emitidaEm ?? $service->created_at)->toDateString();

        $service->invoice_id = $id;
        $service->save();

        return $id;
    }

    public function estadoDaFatura(int $invoiceId): ?string
    {
        $this->chamadas[] = ['estado', $invoiceId];

        return $this->estados[$invoiceId] ?? null;
    }

    public function finalizeInvoice(int $invoiceId)
    {
        $this->chamadas[] = ['finalizar', $invoiceId];

        $resposta = array_shift($this->respostasAoFinalizar) ?? [];

        if (empty($resposta['errors'])) {
            $this->estados[$invoiceId] = 'settled';
        }

        return $resposta;
    }

    public function createSequence(): array
    {
        $this->chamadas[] = ['serie'];

        // Como o verdadeiro: credenciais recusadas desligam a AT do técnico.
        if ($this->credenciaisDaAtRecusadas) {
            $this->tecnico->at_valid = false;
            $this->tecnico->save();

            throw new VendorInvalidAtCredentials;
        }

        return [];
    }

    public function downloadPdf(int $invoiceId, Service $service): Media
    {
        $this->chamadas[] = ['pdf', $invoiceId];

        return $service->addMediaFromString('%PDF-1.4 fatura '.$invoiceId)
            ->usingFileName('fatura-'.$invoiceId.'.pdf')
            ->toMediaCollection(Service::COLECAO_DA_FATURA);
    }
}
