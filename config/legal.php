<?php

return [
    /*
     * Documentos legais e a versão em vigor.
     *
     * A versão vive aqui e não na base de dados de propósito: mudá-la é um
     * deploy, o que a torna deliberada e rastreável no git. Subir a versão faz
     * a app voltar a pedir a aceitação a toda a gente -- e é isso que se quer
     * quando o texto muda.
     */
    'provider_terms' => [
        'version' => env('LEGAL_PROVIDER_TERMS_VERSION', '1.1'),
        'url' => env('LEGAL_PROVIDER_TERMS_URL', 'https://piquetapp.com/termos-e-condicoes-prestadores-de-servico/'),
    ],
];
