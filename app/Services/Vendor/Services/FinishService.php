<?php

namespace App\Services\Vendor\Services;

use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Services\RateService;
use Bavix\Wallet\External\Dto\Extra;
use Bavix\Wallet\Internal\Exceptions\ExceptionInterface;

class FinishService
{
    public function __construct(private Service $service)
    {
    }

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
            throw new \Exception('Service not accepted');
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
