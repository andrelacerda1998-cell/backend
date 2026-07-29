<?php

namespace App\Http\Controllers\Api\Common;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\AnalyticsEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Recebe eventos de produto da app, em lote.
 *
 * A app agrega os eventos e envia-os de vez em quando, para não gastar rede do
 * técnico no terreno. Falhar aqui nunca pode partir a app, por isso devolvemos
 * sempre 200 e descartamos em silêncio o que vier malformado.
 */
class AnalyticsController extends Controller
{
    /** Chaves que nunca aceitamos em `properties`, por conterem dados pessoais. */
    private const BLOCKED_PROPERTIES = [
        'email', 'phone', 'phone_number', 'name', 'first_name', 'last_name',
        'address', 'street_name', 'iban', 'nif', 'at_user', 'at_password',
        'password', 'token', 'latitude', 'longitude',
    ];

    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'events' => ['required', 'array', 'max:100'],
            'events.*.name' => ['required', 'string', 'max:64'],
            'events.*.occurred_at' => ['nullable', 'date'],
            'events.*.properties' => ['nullable', 'array'],
            'platform' => ['nullable', 'string', 'max:16'],
            'app_version' => ['nullable', 'string', 'max:24'],
        ]);

        // Autenticação OPCIONAL: queremos os eventos de onboarding (ainda sem
        // sessão) mas também saber de quem são os restantes. Sem o middleware
        // `auth:api`, o utilizador não vem resolvido — resolvemo-lo à mão e
        // ignoramos qualquer falha de token.
        $userId = null;
        try {
            $userId = auth('api')->user()?->id;
        } catch (\Throwable) {
            // Token ausente, expirado ou inválido: fica anónimo.
        }
        $now = Carbon::now();

        $rows = collect($validated['events'])->map(function (array $event) use ($validated, $userId, $now) {
            $properties = collect($event['properties'] ?? [])
                ->reject(fn ($value, $key) => in_array(strtolower((string) $key), self::BLOCKED_PROPERTIES, true))
                ->take(20)
                ->all();

            return [
                'user_id' => $userId,
                'name' => $event['name'],
                'platform' => $validated['platform'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'properties' => json_encode($properties),
                'occurred_at' => isset($event['occurred_at'])
                    ? Carbon::parse($event['occurred_at'])
                    : $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();

        if ($rows !== []) {
            AnalyticsEvent::query()->insert($rows);
        }

        return new ApiSuccessResponse(['received' => count($rows)]);
    }
}
