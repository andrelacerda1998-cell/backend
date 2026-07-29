<?php

namespace App\Http\Controllers\Api\User;

use App\Enums\Services\AddressType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Customer\UpdateUserRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Http\Responses\Api\Auth\LoginApiResponse;
use App\Models\Auth\Authentications;
use Illuminate\Http\Response;
use Tymon\JWTAuth\Exceptions\JWTException;

class UserController extends Controller
{
    public function me()
    {
        $user = auth()->user();

        $isVendor = $user->isVendor();

        if ($isVendor && $user->vendor->deleted_at === null) {
            $vendor = auth('api')->user()->vendor;

            return new ApiSuccessResponse([
                ...$vendor->makeHidden([
                    'created_at',
                    'updated_at',
                    'deleted_at',
                    'id',
                ])->toArray(),
                'missing_documents' => $vendor->missing_documents
                    ->diffUsing($vendor->pending_documents, fn ($a, $b) => $a['id'] <=> $b['id'])
                    ->transform(fn ($item) => [
                        'id' => $item['id'],
                        'name' => $item['name'],
                        'reason' => $item['reason'] ?? null,
                    ]),
                'pending_documents' => $vendor->pending_documents
                    ->transform(fn ($item) => [
                        'id' => $item['id'],
                        'name' => $item['name'],
                    ]),
                'optional_documents' => $vendor->optional_documents
                    ->diffUsing($vendor->pending_documents, fn ($a, $b) => $a['id'] <=> $b['id'])
                    ->transform(fn ($item) => [
                        'id' => $item['id'],
                        'name' => $item['name'],
                        'reason' => $item['reason'] ?? null,
                    ]),
                'current_location' => [
                    'latitude' => $vendor->currentLocation->latitude ?? 0,
                    'longitude' => $vendor->currentLocation->longitude ?? 0,
                ],
                'notifications' => $user->unreadNotifications->count(),
                'iban' => $vendor->iban,
                'price_rate' => $vendor->price_rate,
                'company_name' => $vendor->company_name,
                'nif' => $vendor->user->nif,
                'company_address' => $vendor->addresses->where('address_type', AddressType::FISCAL_ADDRESS)->first()?->name
                    ?? $vendor->addresses->first()?->name
                    ?? null,
                'at_user' => str_contains($vendor->at_user, '/') ? $vendor->at_user : null,
            ]);
        }

        $userAddress = $user->mainAddress() ?? null;

        $addressData = null;
        if ($userAddress) {
            $addressData = $userAddress->makeHidden(['user_id'])->toArray();
            if (empty($addressData['street_name']) && ! empty($addressData['name'])) {
                $addressData['street_name'] = $addressData['name'];
            }
        }

        return new ApiSuccessResponse([
            ...$user->makeHidden([
                'pay_shop_id',
                'created_at',
                'updated_at',
                'deleted_at',
            ])->toArray(),
            'notifications' => $user->unreadNotifications->count(),
            // hide user-id from address later
            'address' => $addressData,
        ]);

    }

    public function refresh(): ApiErrorResponse|LoginApiResponse
    {
        try {
            $token = auth('api')->refresh(true, true);

            return new LoginApiResponse($token, ['message' => 'refresh successfully']);
        } catch (JWTException) {
            return new ApiErrorResponse(
                null,
                'Token inválido ou expirado.',
                Response::HTTP_UNAUTHORIZED,
            );
        } catch (\Exception $exception) {
            return new ApiErrorResponse($exception);
        }
    }

    public function update(UpdateUserRequest $request)
    {
        $user = auth()->user();
        $name = $request->get('name');
        $nameParts = explode(' ', trim($name), 2);
        $first_name = $nameParts[0];
        $last_name = $nameParts[1] ?? '';
        $date_birthday = $request->get('date_birthday');
        $nif = $request->get('nif');
        $email = $request->get('email');
        $phone_number = $request->get('phone_number');
        $gender_id = $request->get('gender_id');
        $avatar = $request->hasFile('avatar') ? $request->file('avatar') : null;
        if ($avatar) {
            $user->clearMediaCollection('avatar');
            $user->addMedia($avatar)->toMediaCollection('avatar');
        }

        $updateData = [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'phone_number' => $phone_number,
            'profile_completion_pending' => false,
        ];

        // Só mexer nestes campos quando vêm mesmo no pedido. Antes eram sempre
        // reescritos com `?: null`, por isso um formulário que deixasse de os
        // enviar APAGAVA o NIF e a data de nascimento já guardados. O NIF do
        // técnico passou a derivar do subutilizador AT (UpdateAtUserController)
        // e sairia do perfil na primeira gravação. Enviar a chave vazia continua
        // a limpar o valor, para a app do cliente manter o que já fazia.
        if ($request->has('date_birthday')) {
            $updateData['date_birthday'] = $date_birthday ?: null;
        }
        if ($request->has('nif')) {
            $updateData['nif'] = $nif ?: null;
        }
        if ($email) {
            $updateData['email'] = $email;
        }
        if ($gender_id) {
            $updateData['gender_id'] = $gender_id;
        }

        $user->update($updateData);

        return new ApiSuccessResponse([
            ...$user->makeHidden([
                'created_at',
                'updated_at',
                'deleted_at',
                'id',
            ])->toArray(),
            'avatar' => $user->avatar,
        ]);
    }
}
