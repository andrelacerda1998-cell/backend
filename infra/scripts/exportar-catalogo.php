<?php

/**
 * Exporta o catalogo de producao para outro ambiente (ex.: servidor de testes).
 *
 * Corre DENTRO do contentor, via `php artisan tinker --execute='require ...'`,
 * porque a imagem da app nao traz o `mysqldump` e o nome do servico da base de
 * dados no host nao esta neste repositorio. Ir pelo PDO da propria app evita
 * as duas coisas e nao toca em credenciais.
 *
 * Escreve em /tmp/catalogo-export:
 *   catalogo.sql  - REPLACE INTO das tres tabelas (operation_areas,
 *                   services_types, media filtrada aos dois model_type)
 *   paths.txt     - caminhos, relativos a storage/app, para o tar dos ficheiros:
 *                   a pasta inteira das areas (originais) e so as conversions/
 *                   dos tipos de servico (os originais pesam ~300 MB e a app
 *                   nunca os pede).
 *
 * So le. Nao altera nada na base nem no disco fora de /tmp.
 */
$destino = '/tmp/catalogo-export';
if (! is_dir($destino) && ! mkdir($destino, 0755, true)) {
    throw new RuntimeException("Nao consegui criar {$destino}");
}

$pdo = DB::getPdo();
$modelos = [
    'App\\Models\\GeneralSettings\\OperationArea',
    'App\\Models\\GeneralSettings\\ServicesType',
];

$tabelas = [
    'operation_areas' => DB::table('operation_areas')->orderBy('id')->get(),
    'services_types' => DB::table('services_types')->orderBy('id')->get(),
    'media' => DB::table('media')->whereIn('model_type', $modelos)->orderBy('id')->get(),
];

$sql = fopen("{$destino}/catalogo.sql", 'w');
fwrite($sql, '-- Catalogo Piquet exportado de producao em '.now()->toIso8601String()."\n");
fwrite($sql, "-- REPLACE INTO: linhas com o mesmo id sao substituidas; as outras ficam.\n");
fwrite($sql, "-- Para limpar um catalogo de demonstracao antes de importar, descomentar:\n");
fwrite($sql, "-- DELETE FROM `media` WHERE model_type IN (".implode(',', array_map([$pdo, 'quote'], $modelos)).");\n");
fwrite($sql, "-- DELETE FROM `services_types`;\n");
fwrite($sql, "-- DELETE FROM `operation_areas`;\n");
fwrite($sql, "SET FOREIGN_KEY_CHECKS=0;\n");

foreach ($tabelas as $tabela => $linhas) {
    fwrite($sql, "\n-- {$tabela}: ".count($linhas)." linhas\n");
    foreach ($linhas as $linha) {
        $linha = (array) $linha;
        $colunas = '`'.implode('`,`', array_keys($linha)).'`';
        $valores = implode(',', array_map(
            fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
            $linha
        ));
        fwrite($sql, "REPLACE INTO `{$tabela}` ({$colunas}) VALUES ({$valores});\n");
    }
    echo str_pad($tabela, 18).count($linhas)." linhas\n";
}

fwrite($sql, "\nSET FOREIGN_KEY_CHECKS=1;\n");
fclose($sql);

$paths = fopen("{$destino}/paths.txt", 'w');
$incluidos = 0;
foreach ($tabelas['media'] as $media) {
    $pasta = storage_path('app/'.$media->id);
    if (! is_dir($pasta)) {
        echo "AVISO: media {$media->id} ({$media->file_name}) sem pasta em storage/app\n";

        continue;
    }
    if (str_contains($media->model_type, 'OperationArea')) {
        fwrite($paths, "{$media->id}\n");
        $incluidos++;
    } elseif (is_dir("{$pasta}/conversions")) {
        fwrite($paths, "{$media->id}/conversions\n");
        $incluidos++;
    } else {
        echo "AVISO: media {$media->id} ({$media->file_name}) sem conversions/\n";
    }
}
fclose($paths);
echo str_pad('ficheiros', 18).$incluidos." pastas para o tar\n";
