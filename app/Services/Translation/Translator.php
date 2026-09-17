<?php

namespace App\Services\Translation;

/**
 * Traduz um texto curto. Devolve `null` quando nao consegue.
 *
 * `null` e uma resposta legitima e nao uma excepcao: quem chama isto esta a
 * oferecer uma conveniencia (preencher um rascunho), nao a executar uma
 * operacao que o utilizador pediu. Se o fornecedor estiver em baixo ou sem
 * chave, o campo fica por preencher e a pessoa escreve — que e exatamente o
 * que fazia antes.
 */
interface Translator
{
    public function translate(string $text, string $from, string $to): ?string;

    /** Ha fornecedor configurado? O backoffice usa isto para explicar o botao desligado. */
    public function isConfigured(): bool;
}
