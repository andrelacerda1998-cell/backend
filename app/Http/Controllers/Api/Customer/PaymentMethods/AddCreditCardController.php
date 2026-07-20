<?php

namespace App\Http\Controllers\Api\Customer\PaymentMethods;

use App\Exceptions\Api\Common\PaymentMethods\CreditCardInvalidData;
use App\Exceptions\Api\Common\WrongEncryptionKey;
use App\Exceptions\Api\Customer\PaymentMethodDisabled;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Customer\PaymentMethods\CreditCardRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Auth\Authentications;
use Illuminate\Support\Facades\Validator;
use LVR\CreditCard\CardCvc;
use LVR\CreditCard\CardExpirationMonth;
use LVR\CreditCard\CardExpirationYear;
use LVR\CreditCard\CardNumber;

class AddCreditCardController extends Controller
{
    private const BEARER_PREFIX = 'Bearer ';

    public function __invoke(CreditCardRequest $request)
    {
        try {
            if (! config('payment_methods.credit_card.enabled')) {
                throw new PaymentMethodDisabled;
            }

            $authToken = $this->extractAuthToken($request->header('Authorization', ''));
            $authentication = $this->getAuthentication($authToken);

            $decryptedData = $this->decryptCreditCardData($request->get('creditCardData'), $authentication->rsa);
            $validatedCardData = $this->validateCardData($decryptedData);
            $validatedCardData['cardNumber'] = str_replace(' ', '', $validatedCardData['cardNumber']);

            $user = auth('api')->user();
            if (! $user) {
                throw new \Exception('Authentication required');
            }

            $this->ensurePayShopCustomer($user);

            $result = $user->addCreditCard(
                $validatedCardData['cardNumber'],
                $validatedCardData['cvc'],
                $validatedCardData['expirationMonth'],
                $validatedCardData['expirationYear'],
                $validatedCardData['holderName']
            );

            $response = [
                'id' => $result['id'],
                'brand' => $result['brand'],
                'brand_description' => $result['brand_description'],
                'last4' => $result['last4'],
            ];

            return ApiSuccessResponse::make($response);
        } catch (\Exception $exception) {
            return new ApiErrorResponse($exception);
        }
    }

    private function extractAuthToken(string $authorizationHeader): string
    {
        return str_replace(self::BEARER_PREFIX, '', $authorizationHeader);
    }

    /**
     * @throws WrongEncryptionKey
     */
    private function getAuthentication(string $token): Authentications
    {
        $auth = Authentications::where('token', $token)->first();

        if (! $auth) {
            throw new WrongEncryptionKey('Authentication token is invalid.');
        }

        return $auth;
    }

    /**
     * @throws WrongEncryptionKey
     */
    private function decryptCreditCardData(string $encodedData, string $privateKey): array
    {
        $decodedData = base64_decode($encodedData);
        openssl_private_decrypt($decodedData, $decrypted, $privateKey);
        if ($decrypted === false) {
            throw new WrongEncryptionKey;
        }

        return json_decode($decrypted, true);
    }

    private function validateCardData(array $cardData): array
    {
        $validator = Validator::make($cardData, [
            'cardNumber' => ['required', new CardNumber],
            'cvc' => ['required', new CardCvc($cardData['cardNumber'])],
            'expirationMonth' => ['required', new CardExpirationMonth($cardData['expirationYear'])],
            'expirationYear' => ['required', new CardExpirationYear($cardData['expirationMonth'])],
            'holderName' => 'required',
        ]);

        if ($validator->fails()) {
            throw new CreditCardInvalidData('Invalid card data.');
        }

        return $validator->validated();
    }

    private function ensurePayShopCustomer($user): void
    {
        if (! $user->hasPayShopId()) {
            $user->createAsPayShopCustomer();
        }
    }
}
