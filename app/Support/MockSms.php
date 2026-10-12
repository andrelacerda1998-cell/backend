<?php

namespace App\Support;

/**
 * O modo de SMS a fingir (MOCK_SMS): nenhum SMS é enviado e o código 123456
 * serve para qualquer número. Existe para testar em desenvolvimento sem gastar
 * SMS nem precisar de um telemóvel real.
 *
 * NUNCA em produção, mesmo com a variável ligada. Os sítios que ENVIAM o código
 * já o garantiam (`! app()->isProduction()`), mas os que o VERIFICAM não: com
 * MOCK_SMS ligado por engano num deploy, o login por SMS aceitava 123456 para
 * qualquer número — bastava saber o telefone de alguém para entrar na conta
 * dele —, a validação do telefone aceitava-o também, e o SMS de validação
 * deixava de ser enviado. Agora a resposta é uma só, aqui, e todos a usam.
 */
class MockSms
{
    public const CODIGO = '123456';

    public static function ativo(): bool
    {
        return (bool) config('app.MOCK_SMS') && ! app()->isProduction();
    }
}
