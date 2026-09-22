<?php

namespace App\Services\Vendor\Services;

use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use Bavix\Wallet\Internal\Exceptions\ExceptionInterface;

class FinishService
{
    public function __construct(private Service $service) {}

    /**
     * @throws ExceptionInterface
     * @throws \Exception
     */
    public function finish()
    {
        if ($this->service->status === ServiceStatus::FINISHED) {
            throw new \Exception('Service already finished', 409);
        }

        if (in_array($this->service->status, [ServiceStatus::ACCEPTED, ServiceStatus::ARRIVED]) === false) {
            // 422 e nao 500: terminar um servico que ainda nao foi aceite e
            // uma regra de negocio, nao uma avaria. Sem codigo ficava no 500
            // por omissao e a app dizia "Something went wrong".
            throw new \Exception('Service not accepted', 422);
        }

        \DB::beginTransaction();
        try {
            $this->service->status = ServiceStatus::FINISHED;
            $this->service->save();

            \DB::commit();
        } catch (\Exception $e) {
            \DB::rollBack();
            throw $e;
        }
    }
}
