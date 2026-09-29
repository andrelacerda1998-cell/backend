<?php

use App\Models\GeneralSettings\ServicesType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O catálogo em alemão.
 *
 * Migração SEPARADA e não uma edição da de francês/espanhol/inglês
 * (2026_09_29_160000) de propósito: essa já fez merge, e um ambiente que já
 * a tenha corrido nunca a voltaria a correr — acrescentar-lhe o `de` deixava
 * produção sem alemão e ninguém dava por isso.
 *
 * Tudo o resto é igual, pelas mesmas razões, que continuam a valer:
 *
 * CASA PELO TEXTO PORTUGUÊS APARADO, não por id. Os ids desta base e os de
 * produção não têm de coincidir, e três tópicos têm um espaço no fim que o
 * ficheiro de traduções não traz.
 *
 * ESCREVE O `name` PELO QUERY BUILDER. O Spatie intercepta o setAttribute e
 * grava o JSON inteiro como sendo o texto do idioma actual.
 *
 * NÃO TOCA no que já lá está, e o `down` só tira o que foi ele a pôr.
 */
return new class extends Migration
{
    /** Só o alemão: os outros três entraram na migração anterior. */
    private const IDIOMAS = ['de'];

    public function up(): void
    {
        $mapa = $this->mapa();

        if ($mapa === []) {
            // Sem ficheiro de traduções não há nada a fazer, e falhar aqui
            // impedia o resto das migrações de correr por causa de dados.
            return;
        }

        DB::transaction(function () use ($mapa) {
            $this->traduzirNomes('operation_areas', $mapa);
            $this->traduzirNomes('services_types', $mapa);
            $this->traduzirTopicos($mapa);
        });
    }

    /**
     * Desfaz APENAS o que esta migração pôs.
     *
     * Remover os três idiomas à cega apagava também o inglês que já existia em
     * 11 serviços, escrito à mão no backoffice muito antes disto. Um `down`
     * que destrói dados que não criou não é reversão, é outra perda — e só se
     * daria por ela quando alguém fosse procurar o nome em inglês.
     *
     * Por isso compara com o mapa: só sai o que for exactamente a tradução que
     * esta migração gerou.
     */
    public function down(): void
    {
        $mapa = $this->mapa();

        DB::transaction(function () use ($mapa) {
            // Pelo query builder, pela mesma razão do `up`.
            foreach (['operation_areas', 'services_types'] as $tabela) {
                foreach (DB::table($tabela)->get() as $linha) {
                    $nomes = json_decode($linha->name ?? '{}', true) ?: [];
                    $pt = $nomes['pt-pt'] ?? null;

                    foreach (self::IDIOMAS as $idioma) {
                        $nosso = $this->traducoesDe($mapa, $pt)[$idioma] ?? null;

                        // Só sai se for a nossa tradução. Um nome diferente é
                        // de outra pessoa e fica.
                        if ($nosso !== null && ($nomes[$idioma] ?? null) === $nosso) {
                            unset($nomes[$idioma]);
                        }
                    }

                    DB::table($tabela)->where('id', $linha->id)
                        ->update(['name' => json_encode($nomes, JSON_UNESCAPED_UNICODE)]);
                }
            }

            foreach (ServicesType::withTrashed()->get() as $tipo) {
                foreach (['includes', 'excludes'] as $coluna) {
                    $itens = collect($tipo->{$coluna} ?? [])
                        ->map(function ($item) use ($mapa) {
                            if (! is_array($item)) {
                                return $item;
                            }

                            $pt = $item['pt-pt'] ?? null;

                            if ($pt === null) {
                                // Sem português não se sabe o que era antes:
                                // fica como está em vez de se perder texto.
                                return $item;
                            }

                            foreach (self::IDIOMAS as $idioma) {
                                $nosso = $this->traducoesDe($mapa, $pt)[$idioma] ?? null;
                                if ($nosso !== null && ($item[$idioma] ?? null) === $nosso) {
                                    unset($item[$idioma]);
                                }
                            }

                            // Se só sobrou o português, volta ao texto simples
                            // — que é o formato que a coluna tinha antes.
                            return array_keys($item) === ['pt-pt'] ? $pt : $item;
                        })
                        ->filter()
                        ->values()
                        ->all();
                    $tipo->{$coluna} = $itens;
                }
                $tipo->saveQuietly();
            }
        });
    }

    /**
     * {"texto em português": {"fr": "...", "es": "...", "en": "..."}}
     *
     * INDEXADO PELO TEXTO APARADO. Três tópicos desta base têm um espaço no
     * fim ("Serviços de lavandaria ") e no ficheiro de traduções a chave veio
     * sem ele — nem todos os lotes aparavam. A casar por string exacta, esses
     * três ficavam sem tradução nenhuma e ninguém dava por isso: um cliente
     * francês via duas alíneas em francês e uma em português no meio.
     *
     * Apanhado a comparar o mapa com a base antes de aplicar. Aparar dos dois
     * lados resolve-o sem tocar nos dados, e resolve também o caso inverso —
     * produção pode ter espaços onde esta base não tem.
     *
     * Verificado: 0 colisões ao aparar as 844 chaves.
     */
    private function mapa(): array
    {
        $caminho = database_path('data/catalogo-traduzido.json');

        if (! is_file($caminho)) {
            return [];
        }

        $bruto = json_decode(file_get_contents($caminho), true) ?: [];

        $mapa = [];
        foreach ($bruto as $texto => $traducoes) {
            $mapa[trim((string) $texto)] = $traducoes;
        }

        return $mapa;
    }

    /** A tradução de um texto, seja ele qual for o espaço que traga. */
    private function traducoesDe(array $mapa, ?string $texto): ?array
    {
        if ($texto === null) {
            return null;
        }

        return $mapa[trim($texto)] ?? null;
    }

    private function traduzirNomes(string $tabela, array $mapa): void
    {
        foreach (DB::table($tabela)->get() as $linha) {
            $nomes = json_decode($linha->name ?? '{}', true) ?: [];
            $pt = $nomes['pt-pt'] ?? null;

            $traducoes = $this->traducoesDe($mapa, $pt);

            if (! $pt || $traducoes === null) {
                continue;
            }

            foreach (self::IDIOMAS as $idioma) {
                // O que já existe manda. Um nome escrito à mão no backoffice
                // vale mais do que uma tradução automática por cima.
                if (! empty($nomes[$idioma])) {
                    continue;
                }
                if (! empty($traducoes[$idioma])) {
                    $nomes[$idioma] = $traducoes[$idioma];
                }
            }

            DB::table($tabela)->where('id', $linha->id)
                ->update(['name' => json_encode($nomes, JSON_UNESCAPED_UNICODE)]);
        }
    }

    private function traduzirTopicos(array $mapa): void
    {
        foreach (ServicesType::withTrashed()->get() as $tipo) {
            foreach (['includes', 'excludes'] as $coluna) {
                $tipo->{$coluna} = collect($tipo->{$coluna} ?? [])
                    ->map(function ($item) use ($mapa) {
                        // Formato antigo: texto simples. É o que a base tem
                        // hoje em todos os 154 serviços.
                        $pt = is_array($item) ? ($item['pt-pt'] ?? null) : $item;

                        if (! is_string($pt) || $pt === '') {
                            return $item;
                        }

                        $traduzido = is_array($item) ? $item : [];
                        $traduzido['pt-pt'] = $pt;
                        $traducoes = $this->traducoesDe($mapa, $pt) ?? [];

                        foreach (self::IDIOMAS as $idioma) {
                            if (! empty($traduzido[$idioma])) {
                                continue;
                            }
                            if (! empty($traducoes[$idioma])) {
                                $traduzido[$idioma] = $traducoes[$idioma];
                            }
                        }

                        return $traduzido;
                    })
                    ->all();
            }

            $tipo->saveQuietly();
        }
    }
};
