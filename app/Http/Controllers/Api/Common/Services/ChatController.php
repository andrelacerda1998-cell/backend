<?php

namespace App\Http\Controllers\Api\Common\Services;

use App\Events\Common\Services\NewMessageEvent;
use App\Exceptions\Api\Common\WrongEncryptionKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Common\ChatRequest;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Message;
use App\Models\Service;
use App\Notifications\Vendor\NewMessageNotification as NewMessageNotificationVendor;
use App\Notifications\Customer\NewMessageNotification as NewMessageNotificationCustomer;

class ChatController extends Controller
{
    public function store(Service $service, ChatRequest $request)
    {
        if ($service->customer_id !== auth('api')->user()->id && $service->vendor->user->id !== auth('api')->user()->id) {
            abort(403);
        }
        $message = $request->get('message');

        $rsa = $service->rsa;
        $messageDecrypted = $this->decryptData($message, $rsa);

        $message = $service->messages()->create([
            'message' => $request->get('message'),
            'user_id' => auth('api')->user()->id,
        ]);

        $this->notify($message, $service, $messageDecrypted);

        return ApiSuccessResponse::make();
    }

    /**
     * @throws WrongEncryptionKey
     */
    private function decryptData(string $encodedData, string $privateKey): string
    {
        $decodedData = base64_decode($encodedData);

        $success = openssl_private_decrypt($decodedData, $decrypted, $privateKey);

        if (!$success) {
            throw new WrongEncryptionKey();
        }

        return mb_convert_encoding($decrypted, 'UTF-8', 'UTF-8');
    }

    public function index(Service $service)
    {
        if ($service->customer_id !== auth('api')->user()->id && $service->vendor->user->id !== auth('api')->user()->id) {
            abort(403);
        }

        $viewerId = auth('api')->user()->id;

        // Abrir o chat marca como lidas as mensagens do OUTRO lado. A coluna
        // is_read já existia na tabela mas nunca era escrita nem exposta: o
        // técnico escrevia ao cliente e ficava sem saber se tinha sido lido —
        // à porta fechada, com o cliente ausente, isso é aflitivo.
        $service->messages()
            ->where('user_id', '!=', $viewerId)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        $messages = $service->messages()->get()->transform(function ($message) use ($service) {

            return [
                'message' => $this->decryptData($message->message, $service->rsa),
                'from' => $message->user_id,
                'date' => $message->created_at,
                // Só interessa nas MINHAS mensagens (o "lido" do destinatário);
                // a app ignora-o nas recebidas.
                'is_read' => (bool) $message->is_read,
            ];
        });

        return new ApiSuccessResponse(compact('messages'));
    }

    public function notify(Message $message, Service $service, string $messageDecrypted)
    {
        NewMessageEvent::dispatch($message, $service, $messageDecrypted);
        if ($service->customer_id === auth('api')->user()->id) {
            $service->vendor->user->notify(new NewMessageNotificationVendor($service));
        } else {
            $service->customer->notify(new NewMessageNotificationCustomer($service));
        }
    }
}
