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
    public function __construct(private Service $service)
    {
    }

    /**
     * @throws ExceptionInterface
     * @throws \Exception
     * @throws \Throwable
     */
    public function refuse(): void
    {
        $this->service->refresh();

        if ($this->service->status === ServiceStatus::CANCELED) {
            throw new \Exception('Service was already canceled');
        }

        if ($this->service->status === ServiceStatus::PENDING) {
            \DB::beginTransaction();
            try {
                $this->service->status = ServiceStatus::REFUSED;
                $this->service->status_justification = 'internal/services.refused.vendor';

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
