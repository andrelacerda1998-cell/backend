<?php

namespace App\Services\Common\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Models\Service;
use App\Models\VoucherUsage;
use App\Services\RateService;
use Bavix\Wallet\External\Dto\Extra;
use Bavix\Wallet\Internal\Exceptions\ExceptionInterface;
use Filament\Support\Exceptions\Cancel;
use Illuminate\Support\Facades\Log;
use RwInteractive\PayshopSdk\Enums\Payment\Status;

class RefuseService
{
    /** O profissional recusou, com o dedo. */
    public const MOTIVO_RECUSA = 'internal/services.refused.vendor';

    /**
     * O prazo acabou sem resposta.
     *
     * O DINHEIRO SEGUE O MESMO CAMINHO (libertar a autorização ou reembolsar,
     * devolver o crédito, apagar o agendamento, libertar o voucher) — deixar um
     * pedido pago a expirar sem isto prendia o dinheiro do cliente para sempre.
     *
     * O que NÃO é igual é a leitura: não responder porque se estava a conduzir
     * não é recusar. Por isso o motivo fica gravado, e a taxa de aceitação
     * ignora-o (ver StatsController).
     */
    public const MOTIVO_EXPIRADO = 'internal/services.refused.timeout';

    /**
     * O profissional aceitou OUTRO pedido imediato e ficou ocupado.
     *
     * Também não é recusa dele: ele disse que sim -- a outro. Fica de fora da
     * taxa de aceitação pela mesma razão que o timeout.
     */
    public const MOTIVO_OCUPADO = 'internal/services.refused.vendor_busy';

    /**
     * As justificações que NÃO são uma recusa do profissional.
     *
     * Vive aqui, e não espalhada pelos sítios que a consultam, porque a lista
     * vai crescer e esquecer um sítio é fazer a taxa de aceitação de alguém
     * cair por uma razão que não é dele.
     */
    public const MOTIVOS_QUE_NAO_SAO_RECUSA = [
        self::MOTIVO_EXPIRADO,
        self::MOTIVO_OCUPADO,
    ];

    public function __construct(private Service $service)
    {
    }

    /**
     * @throws ExceptionInterface
     * @throws \Exception
     * @throws \Throwable
     */
    public function refuse(string $motivo = self::MOTIVO_RECUSA): void
    {
        $this->service->refresh();

        if ($this->service->status === ServiceStatus::CANCELED) {
            throw new \Exception('Service was already canceled');
        }

        if ($this->service->status === ServiceStatus::PENDING) {
            \DB::beginTransaction();
            try {
                $this->service->status = ServiceStatus::REFUSED;
                $this->service->status_justification = $motivo;

                $this->service->save();
                $customer = $this->service->customer;

                if ($this->service->paymentOrder) {
                    $paymentOrder = $this->service->paymentOrder;
                    try {
                        if ($paymentOrder->status === Status::PENDING_CONFIRMATION) {
                            $paymentOrder->cancel();   // liberta a autorização (cativo)
                        } elseif ($paymentOrder->status === Status::SUCCESS) {
                            $paymentOrder->refund();   // caso já capturado
                        }
                        $this->service->payment_status = PaymentStatus::REFUNDED;
                        $this->service->save();
                    } catch (\Exception $e) {
                        Log::warning($e);
                    }
                }
                if ($this->service->credit_used>0){
                    $customer->deposit($this->service->credit_used, [
                        "description" => 'internal/services.refunds.refused',
                        "type" => 'internal/services.transactions_type.refund',
                        "class" => "App\\Models\\User",
                        "id" => $customer->id,
                        "admin_description" => 'internal/services.refunds.refused',
                    ]);
                }

                if ($this->service->schedule) {
                    $this->service->schedule->delete();
                }

                // Release any single-use voucher so a refused request does not consume it permanently.
                VoucherUsage::where('service_id', $this->service->id)->delete();

                \DB::commit();
            } catch (\Exception $e) {
                \DB::rollBack();
                throw $e;
            }
        }
    }
}
