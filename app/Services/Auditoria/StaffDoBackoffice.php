<?php

namespace App\Services\Auditoria;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Quem, do staff, está por trás de um pedido à API de admin.
 *
 * O backoffice fala com o Laravel com um token só, partilhado (ver
 * AdminApiToken). Para o Laravel, todas as alterações feitas no backoffice
 * eram do "Sistema": a auditoria sabia o quê e quando, nunca quem. O
 * backoffice já sabe quem tem a sessão aberta (login Supabase, tabela
 * `staff`) e passa a dizê-lo em dois cabeçalhos:
 *
 *  - X-Staff-Email: o email de quem está no backoffice;
 *  - X-Staff-Nome: o nome, para o feed se ler sem consultas. Vem com
 *    encodeURIComponent — um cabeçalho HTTP não leva "André" em UTF-8 de
 *    forma fiável (o fetch do Node manda-o em Latin-1, ou recusa-o).
 *
 * SÓ CONTAM DEPOIS DO TOKEN. Quem os guarda é o AdminApiToken, e só depois de
 * o token bater certo; quem os lê (os resolvers da auditoria) lê o que ficou
 * guardado no pedido, nunca os cabeçalhos. Um cabeçalho destes num pedido da
 * app, ou com o token errado, não chega a lado nenhum.
 *
 * É informação declarada pelo backoffice, não uma autenticação: diz quem o
 * backoffice afirma ser, com a mesma confiança que se tem no próprio token.
 */
final class StaffDoBackoffice
{
    public const ATRIBUTO_EMAIL = 'staff_email';

    public const ATRIBUTO_NOME = 'staff_nome';

    /** O tamanho das colunas em `audits`. */
    public const MAXIMO = 190;

    /**
     * Guarda no pedido quem o fez. Chamado pelo AdminApiToken, com o token já
     * validado.
     *
     * O email é a identidade; o nome é só para se ler. Sem um email válido não
     * se guarda nenhum dos dois: um nome solto não diz quem foi, e uma linha
     * com nome e sem email ficava fora do feed sem ninguém perceber porquê.
     */
    public static function registar(Request $request): void
    {
        $email = self::email($request->header('X-Staff-Email'));

        $request->attributes->set(self::ATRIBUTO_EMAIL, $email);
        $request->attributes->set(self::ATRIBUTO_NOME, $email ? self::nome($request->header('X-Staff-Nome')) : null);
    }

    /** O email de quem está a fazer o pedido atual, ou null. */
    public static function emailAtual(): ?string
    {
        return self::atual(self::ATRIBUTO_EMAIL);
    }

    /** O nome de quem está a fazer o pedido atual, ou null. */
    public static function nomeAtual(): ?string
    {
        return self::atual(self::ATRIBUTO_NOME);
    }

    /** Um email válido, em minúsculas, ou null. Demasiado longo é inválido: cortado já não era o dele. */
    public static function email(?string $valor): ?string
    {
        $email = Str::lower(self::texto($valor));

        if ($email === '' || mb_strlen($email) > self::MAXIMO) {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /** O nome numa linha, sem caracteres de controlo, até MAXIMO caracteres; ou null. */
    public static function nome(?string $valor): ?string
    {
        $nome = self::texto($valor);

        return $nome === '' ? null : mb_substr($nome, 0, self::MAXIMO);
    }

    private static function texto(?string $valor): string
    {
        $texto = (string) $valor;

        if (preg_match('/%[0-9A-Fa-f]{2}/', $texto)) {
            $texto = rawurldecode($texto);
        }
        // Um "é" que chegou em Latin-1 (um byte só) não é UTF-8 válido.
        if (! mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1');
        }

        return Str::squish(preg_replace('/\p{Cc}+/u', ' ', $texto) ?? '');
    }

    private static function atual(string $atributo): ?string
    {
        $valor = app()->bound('request') ? request()->attributes->get($atributo) : null;

        return is_string($valor) && $valor !== '' ? $valor : null;
    }
}
