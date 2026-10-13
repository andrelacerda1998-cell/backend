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
        // 1.2 (13/10/2026): sai a cláusula 7.4 (perda do valor retido) e o prazo
        // de 5 dias; o IBAN e a morada fiscal só a partir do 4.º serviço (#175);
        // o pagamento de um serviço espera pela fatura (#176).
        'version' => env('LEGAL_PROVIDER_TERMS_VERSION', '1.2'),
        'url' => env('LEGAL_PROVIDER_TERMS_URL', 'https://piquetapp.com/termos-e-condicoes-prestadores-de-servico/'),
    ],
];
