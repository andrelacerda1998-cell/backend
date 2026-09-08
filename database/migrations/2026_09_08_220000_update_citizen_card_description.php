<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O Cartao de Cidadao passa a pedir so a frente.
 *
 * A descricao vive na tabela `documents` e nao nas traducoes da app, por isso
 * muda-se por migration. Segue o mesmo padrao do
 * 2026_07_30_000001_shorten_document_descriptions: emparelha pelo NOME e nao
 * pelo id, porque os ids nao coincidem entre ambientes.
 *
 * So escreve se a descricao atual for a que esperamos. Se alguem a tiver
 * mudado no backoffice entretanto, essa alteracao ganha — uma migration de
 * conteudo nao deve passar por cima de uma decisao mais recente de quem la
 * mexeu.
 */
return new class extends Migration
{
    private const NAME = 'Cartão de Cidadão';

    private const OLD = 'Frente e verso, dentro da validade.';

    private const NEW = 'Frente e dentro de validade.';

    public function up(): void
    {
        $this->replace(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->replace(self::NEW, self::OLD);
    }

    private function replace(string $from, string $to): void
    {
        foreach (DB::table('documents')->get() as $document) {
            if ((json_decode($document->name ?? '', true)['pt-pt'] ?? null) !== self::NAME) {
                continue;
            }

            $description = json_decode($document->description ?? '', true) ?: [];

            if (($description['pt-pt'] ?? null) !== $from) {
                continue;
            }

            $description['pt-pt'] = $to;

            DB::table('documents')
                ->where('id', $document->id)
                ->update(['description' => json_encode($description, JSON_UNESCAPED_UNICODE)]);
        }
    }
};
