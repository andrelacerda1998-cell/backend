<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use RwInteractive\PayshopSdk\Exceptions\Api\CreditCardValidationRequired;
use RwInteractive\PayshopSdk\Exceptions\Api\OperationInvalid;

class payshopTestCommand extends Command
{
    protected $signature = 'payshop:test-integration';

    protected $description = 'Command description';

    /**
     * @throws OperationInvalid
     */
    public function handle(): void
    {


        $customer = User::find(1);
        #dd($customer->createAsPayShopCustomer());
        $paymentMethod = $customer->addCreditCard("4018810000190011", "123","12", "34", "FILIPE CLEMENTE");

        //$paymentMethod = $customer->paymentMethods()->find(26);
        $paymentOrder = $customer->createPaymentOrder(
            OperationType::DEFERRED,
            1,
            'Payment abc',
            Carbon::now()->addHour(),
            routeParams: Service::first()->id
        );

        try {
            dd($paymentOrder->authorize($paymentMethod)->confirm());
        }catch (CreditCardValidationRequired $e){
            dd($e->getUrl());
        }
    }
}

