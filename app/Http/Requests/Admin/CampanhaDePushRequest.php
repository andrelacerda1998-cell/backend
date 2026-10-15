<?php

namespace App\Http\Requests\Admin;

use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Uma campanha de push vinda do backoffice: os mesmos campos do formulário do
 * Filament (NotificationCampaignResource), com duas regras a mais.
 *
 * - Um intervalo personalizado não fica abaixo de uma hora: no Filament dava
 *   para mandar push a toda a gente de minuto a minuto.
 * - O que não se aplica ao público fica vazio. Um filtro de técnicos numa
 *   campanha só de clientes não fazia nada, mas ficava gravado e confundia
 *   quem a lia depois.
 *
 * Na contagem do público (POST /notification-campaigns/publico) só se valida
 * o público: o texto ainda pode estar por escrever.
 */
class CampanhaDePushRequest extends FormRequest
{
    public const INTERVALO_MINIMO_EM_MINUTOS = 60;

    public function authorize(): bool
    {
        return true;
    }

    private function soPublico(): bool
    {
        return str_ends_with($this->path(), 'notification-campaigns/publico');
    }

    public function rules(): array
    {
        $publico = [
            'target_type' => ['required', Rule::in(['vendor', 'customer', 'both'])],
            'user_status' => ['nullable', Rule::in(['online', 'offline', 'both'])],
            'vendor_eligibility' => ['nullable', Rule::in(['ready', 'incomplete'])],
            'vendor_missing_schedule_address' => ['boolean'],
            'customer_never_requested' => ['boolean'],
            'inactive_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];

        if ($this->soPublico()) {
            return $publico;
        }

        return $publico + [
            'name' => ['required', 'string', 'max:255'],
            'title' => ['required', 'array'],
            'title.pt-pt' => ['required', 'string', 'max:255'],
            'title.en' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'array'],
            'body.pt-pt' => ['required', 'string', 'max:1000'],
            'body.en' => ['nullable', 'string', 'max:1000'],
            'english_reviewed' => ['boolean'],
            'open_type' => ['nullable', Rule::in(['ServicesType', 'OperationArea'])],
            'open_id' => ['nullable', 'integer', 'required_with:open_type'],
            'frequency_type' => ['required', Rule::in(['once', 'daily', 'weekly', 'custom'])],
            'frequency_value' => ['nullable', 'required_if:frequency_type,custom', 'integer', 'min:1'],
            'frequency_unit' => ['nullable', 'required_if:frequency_type,custom', Rule::in(['minutes', 'hours', 'days'])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }

    public function after(): array
    {
        if ($this->soPublico()) {
            return [];
        }

        return [
            function (Validator $validator) {
                if ($this->input('frequency_type') === 'custom') {
                    $minutos = (int) $this->input('frequency_value') * match ($this->input('frequency_unit')) {
                        'hours' => 60,
                        'days' => 1440,
                        default => 1,
                    };

                    if ($minutos < self::INTERVALO_MINIMO_EM_MINUTOS) {
                        $validator->errors()->add('frequency_value', 'O intervalo mínimo é de uma hora.');
                    }
                }

                $destino = match ($this->input('open_type')) {
                    'ServicesType' => ServicesType::query(),
                    'OperationArea' => OperationArea::query(),
                    default => null,
                };

                if ($destino && ! $destino->whereKey($this->integer('open_id'))->exists()) {
                    $validator->errors()->add('open_id', 'Esse destino já não existe no catálogo.');
                }
            },
        ];
    }

    /** Os atributos da campanha, com o que não se aplica ao público vazio. Sempre desligada. */
    public function atributos(): array
    {
        $alvo = $this->input('target_type');
        $tecnicos = in_array($alvo, ['vendor', 'both'], true);
        $clientes = in_array($alvo, ['customer', 'both'], true);

        $publico = [
            'target_type' => $alvo,
            // O envio só lê a disponibilidade numa campanha só de técnicos.
            'user_status' => $alvo === 'vendor' ? $this->input('user_status') : null,
            'vendor_eligibility' => $tecnicos ? $this->input('vendor_eligibility') : null,
            'vendor_missing_schedule_address' => $tecnicos && $this->boolean('vendor_missing_schedule_address'),
            'customer_never_requested' => $clientes && $this->boolean('customer_never_requested'),
            'inactive_days' => $this->filled('inactive_days') ? $this->integer('inactive_days') : null,
        ];

        if ($this->soPublico()) {
            return $publico;
        }

        $texto = fn (string $campo, string $lingua) => trim((string) $this->input("{$campo}.{$lingua}", ''));
        $temIngles = $texto('title', 'en') !== '' || $texto('body', 'en') !== '';
        $personalizado = $this->input('frequency_type') === 'custom';
        $abre = $clientes && $this->filled('open_type');

        return $publico + [
            'name' => trim((string) $this->input('name')),
            // Como o Filament grava: as duas línguas, '' na que não tem texto.
            'title' => ['pt-pt' => $texto('title', 'pt-pt'), 'en' => $texto('title', 'en')],
            'body' => ['pt-pt' => $texto('body', 'pt-pt'), 'en' => $texto('body', 'en')],
            'english_reviewed_at' => $temIngles && $this->boolean('english_reviewed') ? now() : null,
            'open_type' => $abre ? $this->input('open_type') : null,
            'open_id' => $abre ? $this->integer('open_id') : null,
            'frequency_type' => $this->input('frequency_type'),
            'frequency_value' => $personalizado ? $this->integer('frequency_value') : null,
            'frequency_unit' => $personalizado ? $this->input('frequency_unit') : null,
            'starts_at' => $this->input('starts_at') ?: null,
            'ends_at' => $this->input('ends_at') ?: null,
            'is_active' => false,
        ];
    }
}
