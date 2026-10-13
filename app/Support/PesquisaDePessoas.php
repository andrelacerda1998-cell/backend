<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Procurar uma pessoa — cliente ou técnico — como a equipa a procura.
 *
 * Quem liga para o suporte diz "Ana Costa" e lê o número como o tem na
 * agenda: "912 345 678". A pesquisa do backoffice falhava as duas coisas:
 *
 *  - procurava no primeiro nome OU no apelido, um de cada vez, e "Ana Costa"
 *    não cabe em nenhum dos dois sozinho;
 *  - comparava o telefone como texto, e o número está gravado como
 *    "+351912345678". Quem o escrevesse com espaços, com "+351 " à frente ou
 *    com "00351" não encontrava ninguém.
 *
 * O telefone passa a comparar-se pelos DÍGITOS, dos dois lados, e sem o
 * indicativo de Portugal no termo: "912 345 678", "+351 912 345 678",
 * "00351912345678" e "912-345-678" encontram a mesma pessoa. As linhas antigas
 * gravadas sem indicativo ("912345678") também, porque a comparação é por
 * conteúdo e não pelo início.
 *
 * Um sítio só, usado pelas listas de clientes, de técnicos e de pedidos: se
 * cada uma tivesse a sua regra, "encontrei-o nos pedidos mas não nos
 * clientes" seria a primeira pergunta do suporte.
 */
final class PesquisaDePessoas
{
    public const NOME = 'nome';

    public const TELEFONE = 'telefone';

    public const EMAIL = 'email';

    public const NIF = 'nif';

    public const TUDO = [self::NOME, self::TELEFONE, self::EMAIL, self::NIF];

    /**
     * Menos dígitos do que isto não é um telefone: "91" encontraria meio país.
     * Abaixo disto fica só a comparação de texto que sempre existiu.
     */
    private const DIGITOS_MINIMOS = 3;

    /**
     * Os dígitos de um termo que parece um número de telefone, já sem o
     * indicativo de Portugal; null quando o termo não parece um telefone.
     *
     * "Parece" = só tem dígitos e a pontuação com que as pessoas escrevem
     * números (espaços, +, -, ., /, parênteses). "Rua 25 de Abril" não é um
     * telefone, e "#282" é o número de um pedido.
     */
    public static function digitosDoTelefone(string $termo): ?string
    {
        $termo = trim($termo);

        if ($termo === '' || ! preg_match('/^[\d\s+().\/-]+$/', $termo)) {
            return null;
        }

        $digitos = preg_replace('/\D/', '', $termo) ?? '';

        if (str_starts_with($digitos, '00351')) {
            $digitos = substr($digitos, 5);
        } elseif (str_starts_with($digitos, '351') && strlen($digitos) >= 12) {
            // Só com o número inteiro a seguir: "35191" ainda pode ser o
            // princípio de um "+351 91…" escrito aos bocados, e cortá-lo
            // deixava "91", que encontra toda a gente.
            $digitos = substr($digitos, 3);
        }

        return strlen($digitos) >= self::DIGITOS_MINIMOS ? $digitos : null;
    }

    /**
     * A coluna sem a pontuação com que um telefone se escreve -- os mesmos
     * caracteres que `digitosDoTelefone()` aceita no termo.
     *
     * REPLACE encadeados e não REGEXP_REPLACE: esse só existe a partir do
     * MySQL 8, e a versão da base de produção não está escrita em lado
     * nenhum deste repositório. Se faltasse, qualquer pesquisa com três
     * dígitos dava erro 500 nas três listas.
     */
    private static function sqlSoDigitos(string $coluna): string
    {
        $sql = $coluna;

        foreach ([' ', '+', '-', '.', '/', '(', ')'] as $caracter) {
            $sql = "REPLACE({$sql}, '{$caracter}', '')";
        }

        return $sql;
    }

    /**
     * Junta à consulta (sobre a tabela `users`) as condições da pesquisa, todas
     * em OU e dentro de um só grupo — um OR solto desfazia os outros filtros.
     *
     * @param  list<string>  $campos  quais procurar (ver as constantes)
     */
    public static function aplicar(Builder $query, string $termo, array $campos = self::TUDO): Builder
    {
        $termo = trim(preg_replace('/\s+/u', ' ', $termo) ?? $termo);

        if ($termo === '') {
            return $query;
        }

        $like = "%{$termo}%";
        $digitos = self::digitosDoTelefone($termo);

        return $query->where(function (Builder $q) use ($campos, $like, $digitos) {
            if (in_array(self::NOME, $campos, true)) {
                // O nome completo, para "Ana Costa" encontrar a Ana Costa.
                // CONCAT_WS e não a coluna virtual `name`: essa é um CONCAT,
                // que dá NULL a quem não tem apelido (contas só de telemóvel).
                $q->orWhereRaw("CONCAT_WS(' ', users.first_name, users.last_name) LIKE ?", [$like]);
            }

            if (in_array(self::EMAIL, $campos, true)) {
                $q->orWhere('users.email', 'like', $like);
            }

            if (in_array(self::NIF, $campos, true)) {
                $q->orWhere('users.nif', 'like', $like);
            }

            if (in_array(self::TELEFONE, $campos, true)) {
                // O texto tal e qual, como sempre foi.
                $q->orWhere('users.phone_number', 'like', $like);

                if ($digitos !== null) {
                    $q->orWhereRaw(self::sqlSoDigitos('users.phone_number').' LIKE ?', ["%{$digitos}%"]);
                }
            }
        });
    }
}
