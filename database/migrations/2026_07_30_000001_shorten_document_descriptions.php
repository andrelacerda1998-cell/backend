<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * As descricoes dos documentos repetiam o nome que esta mesmo por cima delas:
 * "Registo Criminal" era descrito como "Certificado de registo criminal", e a
 * "Declaracao de Inicio de Atividade" como "Comprovativo de inicio de atividade
 * nas Financas" -- zero informacao nova, duas linhas de ruido cada.
 *
 * Ficam so com o que o nome NAO diz: frente e verso, prazo de validade, onde se
 * obtem. A app ja mostra a ligacao para o portal de cada um.
 *
 * Emparelha pelo nome em vez do id porque os ids nao coincidem entre ambientes.
 */
return new class extends Migration
{
    /** @var array<string, array{old: string, new: string}> */
    private const DESCRIPTIONS = [
        'Cartão de Cidadão' => [
            'old' => 'Documento de identificação válido (frente e verso).',
            'new' => 'Frente e verso, dentro da validade.',
        ],
        'Registo Criminal' => [
            'old' => 'Certificado de registo criminal (válido 90 dias).',
            'new' => 'Emitido há menos de 90 dias.',
        ],
        'Declaração de Início de Atividade' => [
            'old' => 'Comprovativo de início de atividade nas Finanças.',
            'new' => 'A que entregaste nas Finanças.',
        ],
    ];

    public function up(): void
    {
        $this->apply('new');
    }

    public function down(): void
    {
        $this->apply('old');
    }

    private function apply(string $which): void
    {
        foreach (DB::table('documents')->get() as $document) {
            $name = json_decode($document->name ?? '', true)['pt-pt'] ?? null;

            if ($name === null || ! isset(self::DESCRIPTIONS[$name])) {
                continue;
            }

            $description = json_decode($document->description ?? '', true) ?: [];
            $description['pt-pt'] = self::DESCRIPTIONS[$name][$which];

            DB::table('documents')
                ->where('id', $document->id)
                ->update(['description' => json_encode($description, JSON_UNESCAPED_UNICODE)]);
        }
    }
};
