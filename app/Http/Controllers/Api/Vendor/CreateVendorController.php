<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\CreateVendorRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\Auth\LoginApiResponse;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Auth\UserRegistered;
use App\Support\Locale;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateVendorController extends Controller
{
    /**
     * Gera um `username` a partir do nome do técnico.
     *
     * A app do técnico deixou de pedir "nome de utilizador" no registo (não fazia
     * sentido para quem se inscreve como profissional), mas a coluna continua a ser
     * usada como nome de apresentação e é única. Aqui: slug do nome (minúsculas,
     * sem acentos) e, se já existir, sufixo numérico até ser único. O `max:50` da
     * validação é respeitado deixando espaço para o sufixo.
     */
    private function generateUsername(string $name): string
    {
        $base = Str::slug($name, '');

        // Nomes só com símbolos/acentos podem colapsar para vazio — usa-se um prefixo genérico.
        if ($base === '') {
            $base = 'tecnico';
        }

        $base = Str::limit($base, 40, '');

        $username = $base;
        $suffix = 1;
        while (Vendor::where('username', $username)->exists()) {
            $suffix++;
            $username = $base.$suffix;
        }

        return $username;
    }

    public function __invoke(CreateVendorRequest $request)
    {
        $name = $request->input('name');
        if (strpos($name, ' ') === false) {
            $first_name = $name;
            $last_name = '';
        } else {
            $first_name = explode(' ', $name)[0];
            $last_name = explode(' ', $name)[1];
        }

        try {
            DB::beginTransaction();
            $user = User::create([
                'first_name' => $first_name,
                'last_name' => $last_name,
                // 'date_birthday' => $request->input('date_birthday'),
                'email' => $request->input('email'),
                'password' => Hash::Make($request->input('password')),
                // 'gender_id' => $request->input('gender_id') ?? null,
                'phone_number' => $request->input('phone_number'),
                'language' => Locale::normalize($request->input('language')),
                // 'nif' => $request->input('nif'),
            ]);

            $vendor = $user->vendor()->create([
                'username' => $request->filled('username')
                    ? $request->input('username')
                    : $this->generateUsername($name),
                'price_rate' => $request->input('price_rate')
            ]);

            $vendor->operationAreas()->sync($request->input('operation_areas'));
            $vendor->servicesTypes()->sync($request->input('services_types'));

            /*if ($request->get('documents')) {
                foreach ($request->get('documents') as $key => $document) {
                    $vendorDocument = $vendor->documents()->create([
                        'document_id' => $document['document_id'],
                    ]);

                    if ($request->hasFile("documents.$key.file")) {
                        $vendorDocument->addMediaFromRequest("documents.$key.file")->toMediaCollection();
                    }
                }
            }*/

            DB::commit();
            $token = auth('api')->login($user);

            $user->sendEmailVerificationNotification();

            event(new UserRegistered($user));

            return new LoginApiResponse($token, ['message' => 'Vendor created successfully']);
        } catch (Exception $exception) {
            DB::rollBack();

            return new ApiErrorResponse($exception);
        }
    }
}
