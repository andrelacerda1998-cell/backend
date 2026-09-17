<?php

namespace App\Console\Commands;

use App\Enums\Services\AddressType;
use App\Models\Address;
use App\Models\Vendor;
use Illuminate\Console\Command;
use OwenIt\Auditing\Models\Audit;

/**
 * Moradas de tecnico que foram reescritas por cima de outra.
 *
 * ESTE COMANDO NAO ESCREVE NADA. So le auditorias e imprime o que encontra.
 *
 * O bug (corrigido em backend#63): os dois ecras de morada da app gravavam com
 * `updateOrCreate([], ...)`. Com o array de correspondencia vazio, o Eloquent
 * agarrava a PRIMEIRA morada do tecnico — fosse de que tipo fosse — e
 * reescrevia-a, tipo incluido. Gravar a morada da empresa apagava a de
 * agendamento; gravar a de agendamento apagava a fiscal.
 *
 * A linha nao era apagada: era reescrita. Por isso nao ha nada no `deleted_at`
 * para restaurar — mas o `Address` e auditado (owen-it/laravel-auditing,
 * `threshold` a 0 e `auditExclude` vazio), e cada sobreposicao deixou um
 * registo `updated` com os `old_values` da morada anterior.
 *
 * A assinatura e essa: um `updated` em que o `address_type` mudou. Uma morada
 * nao muda de tipo por nenhuma razao legitima — ou e a fiscal ou e a de
 * agendamento, e isso e decidido quando nasce.
 *
 * LIMITES, que interessam antes de se prometer recuperacao a alguem:
 *
 *  - se o tecnico gravou por cima varias vezes, a morada a recuperar e a da
 *    PRIMEIRA sobreposicao, nao a da ultima (listam-se todas, por ordem);
 *  - o `old_values` so guarda os campos que MUDARAM naquele update; numa
 *    sobreposicao muda quase tudo, mas pode haver casos parciais — a coluna
 *    "completa" diz se os campos essenciais la estao;
 *  - o que tenha sido sobreposto antes de a auditoria existir nao esta la.
 *
 * Quem hoje ja tem o tipo perdido preencheu-o outra vez: nao ha nada a fazer.
 */
class ListOverwrittenVendorAddressesCommand extends Command
{
    protected $signature = 'vendors:overwritten-addresses {--json : Saida em JSON, para tratar noutro sitio}';

    protected $description = 'Lista moradas de tecnico reescritas por cima de outra (so leitura)';

    /** Sem estes campos nao vale a pena tentar recuperar a morada. */
    private const CAMPOS_ESSENCIAIS = ['street_name', 'postal_code', 'city', 'latitude', 'longitude'];

    public function handle(): int
    {
        $ocorrencias = $this->encontrar();

        if ($this->option('json')) {
            $this->line(json_encode($ocorrencias, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($ocorrencias === []) {
            $this->info('Nenhuma morada sobreposta encontrada nas auditorias.');
            $this->line('Isso nao prova que nunca aconteceu: o que for anterior a auditoria nao esta la.');

            return self::SUCCESS;
        }

        $recuperaveis = 0;

        foreach ($ocorrencias as $o) {
            $this->newLine();
            $this->line(sprintf(
                '<options=bold>morada #%d</> — tecnico %s (%s)',
                $o['address_id'],
                $o['vendor_id'] ?? '?',
                $o['vendor_name'] ?? 'sem nome',
            ));
            $this->line(sprintf('  %s  %s -> %s', $o['at'], $o['lost_type'], $o['became_type']));
            $this->line(sprintf('  perdida: %s', $o['lost_address'] ?: '(sem dados suficientes no registo)'));

            if ($o['already_refilled']) {
                $this->line('  <fg=gray>hoje ja tem esse tipo preenchido — nada a fazer</>');
            } elseif (! $o['complete']) {
                $this->line('  <fg=yellow>registo incompleto: faltam '.implode(', ', $o['missing']).'</>');
            } else {
                $recuperaveis++;
                $this->line('  <fg=green>RECUPERAVEL</>');
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%d sobreposicoes · %d recuperaveis · %d ja repostas pelo proprio tecnico',
            count($ocorrencias),
            $recuperaveis,
            collect($ocorrencias)->where('already_refilled', true)->count(),
        ));
        $this->line('Nada foi alterado: este comando so le.');

        return self::SUCCESS;
    }

    /** @return array<int, array<string, mixed>> */
    private function encontrar(): array
    {
        $ocorrencias = [];

        Audit::query()
            ->where('auditable_type', Address::class)
            ->where('event', 'updated')
            ->orderBy('id')
            ->chunk(500, function ($audits) use (&$ocorrencias) {
                foreach ($audits as $audit) {
                    $antes = (array) $audit->old_values;
                    $depois = (array) $audit->new_values;

                    $tipoAntes = $antes['address_type'] ?? null;
                    $tipoDepois = $depois['address_type'] ?? null;

                    // Uma morada nao muda de tipo por razao legitima nenhuma.
                    if (! $tipoAntes || ! $tipoDepois || $tipoAntes === $tipoDepois) {
                        continue;
                    }

                    $ocorrencias[] = $this->descrever($audit, $antes, (string) $tipoAntes, (string) $tipoDepois);
                }
            });

        return $ocorrencias;
    }

    /** @return array<string, mixed> */
    private function descrever(Audit $audit, array $antes, string $tipoAntes, string $tipoDepois): array
    {
        $address = Address::withTrashed()->find($audit->auditable_id);

        // Pelo `user_id` e nao por uma relacao: o `Address` nao tem `user()`.
        // Sem isto o tecnico saia sempre a "?" — e, pior, a verificacao de
        // "ja voltou a preencher" dava sempre falso e TODAS as ocorrencias
        // apareciam como recuperaveis.
        $vendor = $address
            ? Vendor::where('user_id', $address->user_id)->with('user')->first()
            : null;

        $emFalta = array_values(array_filter(
            self::CAMPOS_ESSENCIAIS,
            fn (string $campo) => ($antes[$campo] ?? null) === null,
        ));

        // Ja voltou a ter esse tipo? Entao preencheu-o outra vez e nao ha nada
        // a repor — so vale a pena olhar para quem continua sem ele.
        $jaReposta = $vendor
            ? $vendor->addresses()->where('address_type', $tipoAntes)->exists()
            : false;

        return [
            'address_id' => (int) $audit->auditable_id,
            'vendor_id' => $vendor?->id,
            'vendor_name' => $vendor?->user?->name,
            'at' => (string) $audit->created_at,
            'lost_type' => $tipoAntes,
            'became_type' => $tipoDepois,
            'lost_address' => $this->morada($antes),
            'lost_values' => array_intersect_key($antes, array_flip(array_merge(
                self::CAMPOS_ESSENCIAIS,
                ['street_number', 'name', 'address_name', 'state', 'country'],
            ))),
            'complete' => $emFalta === [],
            'missing' => $emFalta,
            'already_refilled' => $jaReposta,
        ];
    }

    private function morada(array $antes): string
    {
        if (! empty($antes['name'])) {
            return (string) $antes['name'];
        }

        $partes = array_filter([
            trim(($antes['street_name'] ?? '').' '.($antes['street_number'] ?? '')),
            $antes['postal_code'] ?? null,
            $antes['city'] ?? null,
        ]);

        return implode(', ', $partes);
    }
}
